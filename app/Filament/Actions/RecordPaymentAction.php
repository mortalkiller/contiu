<?php

namespace App\Filament\Actions;

use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Filament\Actions\Concerns\DisabledWhenReadOnly;
use App\Filament\Support\MoneyInput;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Registar recebimento": one modal used from projects, retainers, clients, receivables and the
 * payments list. It suggests the oldest open amount of whatever record it is attached to.
 */
class RecordPaymentAction extends Action
{
    use DisabledWhenReadOnly;

    public static function getDefaultName(): ?string
    {
        return 'recordPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Record payment'))
            ->icon(Phosphor::HandCoins)
            ->color('primary')
            ->modalHeading(__('Record payment'))
            ->modalDescription(fn (?Model $record) => $record instanceof Receivable
                ? __(':label — :amount outstanding', ['label' => $record->label, 'amount' => Money::format($record->outstanding)])
                : __('Select the receivable. The outstanding amount is filled in automatically.'))
            ->modalIcon(Phosphor::HandCoins)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('Record'))
            ->visible(fn (?Model $record): bool => static::hasOpenReceivables($record))
            ->fillForm(function (?Model $record): array {
                $receivable = static::openReceivablesQuery($record)->with(['payments', 'billable'])->orderBy('due_on')->first();

                return [
                    'receivable_id' => $receivable?->getKey(),
                    'paid_on' => today(),
                    'amount' => $receivable?->outstanding,
                    'method' => PaymentMethod::Transfer,
                    'tax_exempt' => static::exemptByDefault($receivable),
                ];
            })
            ->schema(fn (?Model $record) => [
                Select::make('receivable_id')
                    ->label(__('Receivable'))
                    ->options(fn () => static::receivableOptions($record))
                    ->required()
                    ->live()
                    ->hidden($record instanceof Receivable)
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $receivable = Receivable::with(['payments', 'billable'])->find($state);
                        $set('amount', Money::toEuros($receivable?->outstanding)); // the field holds euros
                        $set('tax_exempt', static::exemptByDefault($receivable));
                    }),
                Grid::make(2)->schema([
                    DatePicker::make('paid_on')
                        ->label(__('Date'))
                        ->native(false)
                        ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                        ->maxDate(today())
                        ->required(),
                    MoneyInput::make('amount')
                        ->label(__('Payment amount'))
                        ->minValue(0.01)
                        ->required()
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $receivable = $record instanceof Receivable ? $record : Receivable::with('payments')->find($get('receivable_id'));

                            if ($receivable && (int) Money::toCents($value) > $receivable->outstanding) {
                                $fail(__('The payment exceeds the outstanding amount (:amount). If the client overpaid, record the difference against another receivable.', [
                                    'amount' => Money::format($receivable->outstanding),
                                ]));
                            }
                        }),
                ]),
                ToggleButtons::make('method')
                    ->label(__('Method'))
                    ->options(PaymentMethod::class)
                    ->inline()
                    ->required(),
                Toggle::make('tax_exempt')
                    ->label(__('Exempt from taxes'))
                    ->helperText(__('Excluded from tax estimates (VAT, Social Security and personal income tax). Selected by default according to Tax settings and for tax-exempt retainers.')),
                TextInput::make('invoice_number')
                    ->label(__('Invoice / receipt number'))
                    ->placeholder(__('E.g. FR 2026/12'))
                    ->maxLength(100),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(2),
            ])
            ->action(function (array $data, ?Model $record, Schema $schema): void {
                DB::transaction(function () use ($data, $record, $schema): void {
                    $id = $record instanceof Receivable ? $record->getKey() : $data['receivable_id'];
                    $receivable = Receivable::query()->lockForUpdate()->findOrFail($id);

                    if ($data['amount'] > $receivable->outstanding) {
                        throw ValidationException::withMessages([
                            $schema->getStatePath().'.amount' => __('The amount exceeds the outstanding balance for this receivable.'),
                        ]);
                    }

                    Payment::create([...$data, 'receivable_id' => $id]);
                });

                Notification::make()
                    ->title(__('Payment recorded'))
                    ->body(Money::format($data['amount']))
                    ->success()
                    ->send();
            });
    }

    /** A retainer's payment follows the retainer; any other follows "Recebimentos isentos por defeito". */
    protected static function exemptByDefault(?Receivable $receivable): bool
    {
        $billable = $receivable?->billable;

        return $billable instanceof Retainer
            ? (bool) $billable->tax_exempt
            : app(FinanceSettings::class)->paymentsExemptByDefault();
    }

    /**
     * @return Collection<int, Receivable>
     */
    public static function openReceivables(?Model $record): Collection
    {
        return static::openReceivablesQuery($record)->with(['payments', 'billable.client'])->orderBy('due_on')->get();
    }

    private static function hasOpenReceivables(?Model $record): bool
    {
        if ($record !== null || ! request()->isMethod('GET')) {
            return static::openReceivablesQuery($record)->exists();
        }

        $accountId = CurrentAccount::get()?->id;

        return once(fn (): bool => static::openReceivablesQuery(null)
            ->where('receivables.account_id', $accountId)
            ->exists());
    }

    /** @return Builder<Receivable> */
    private static function openReceivablesQuery(?Model $record): Builder
    {
        $query = Receivable::query()->open()->excludingCancelled();

        match (true) {
            $record instanceof Receivable => $query->whereKey($record->getKey()),
            $record instanceof Project, $record instanceof Retainer => $query->whereMorphedTo('billable', $record),
            $record instanceof Client => $query->forClient($record->getKey()),
            default => null,
        };

        return $query;
    }

    /**
     * Grouped by client when the action is not tied to one record.
     *
     * @return array<string, string>|array<string, array<string, string>>
     */
    protected static function receivableOptions(?Model $record): array
    {
        $label = fn (Receivable $receivable) => __(':label — due :date — :amount outstanding', [
            'label' => $receivable->label,
            'date' => $receivable->due_on->format(Language::resolve(app()->getLocale())->dateFormat()),
            'amount' => Money::format($receivable->outstanding),
        ]);

        $receivables = static::openReceivables($record);

        if ($record !== null) {
            return $receivables->mapWithKeys(fn (Receivable $receivable) => [$receivable->getKey() => $label($receivable)])->all();
        }

        return $receivables
            ->groupBy(fn (Receivable $receivable) => $receivable->client?->name ?? '—')
            ->map(fn ($group) => $group->mapWithKeys(fn (Receivable $receivable) => [$receivable->getKey() => $label($receivable)])->all())
            ->all();
    }
}
