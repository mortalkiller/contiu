<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Filament\Actions\Concerns\DisabledWhenReadOnly;
use App\Filament\Support\MoneyInput;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
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
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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
            ->label('Registar recebimento')
            ->icon(Phosphor::HandCoins)
            ->color('primary')
            ->modalHeading('Registar recebimento')
            ->modalDescription(fn (?Model $record) => $record instanceof Receivable
                ? $record->label.' — falta receber '.Money::format($record->outstanding)
                : 'Escolhe o valor a receber; o montante vem preenchido com o que falta.')
            ->modalIcon(Phosphor::HandCoins)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Registar')
            ->visible(fn (?Model $record) => static::openReceivables($record)->isNotEmpty())
            ->fillForm(function (?Model $record): array {
                $receivable = static::openReceivables($record)->first();

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
                    ->label('Valor a receber')
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
                        ->label('Data')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(today())
                        ->required(),
                    MoneyInput::make('amount')
                        ->label('Montante')
                        ->minValue(0.01)
                        ->required()
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $receivable = $record instanceof Receivable ? $record : Receivable::with('payments')->find($get('receivable_id'));

                            if ($receivable && (int) Money::toCents($value) > $receivable->outstanding) {
                                $fail('Excede o que falta receber ('.Money::format($receivable->outstanding).'). Se o cliente pagou a mais, regista a diferença noutro valor a receber.');
                            }
                        }),
                ]),
                ToggleButtons::make('method')
                    ->label('Método')
                    ->options(PaymentMethod::class)
                    ->inline()
                    ->required(),
                Toggle::make('tax_exempt')
                    ->label('Isento de impostos')
                    ->helperText('Não conta para a estimativa de impostos (IVA, Segurança Social, IRS). Vem marcado por defeito (Definições fiscais) e nas avenças isentas.'),
                TextInput::make('invoice_number')
                    ->label('N.º da fatura / recibo')
                    ->placeholder('Ex.: FR 2026/12')
                    ->maxLength(100),
                Textarea::make('notes')
                    ->label('Notas')
                    ->rows(2),
            ])
            ->action(function (array $data, ?Model $record): void {
                Payment::create([
                    ...$data,
                    'receivable_id' => $record instanceof Receivable ? $record->getKey() : $data['receivable_id'],
                ]);

                Notification::make()
                    ->title('Recebimento registado')
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
        $query = Receivable::query()->open()->excludingCancelled()->with(['payments', 'billable.client'])->orderBy('due_on');

        match (true) {
            $record instanceof Receivable => $query->whereKey($record->getKey()),
            $record instanceof Project, $record instanceof Retainer => $query->whereMorphedTo('billable', $record),
            $record instanceof Client => $query->forClient($record->getKey()),
            default => null,
        };

        return $query->get();
    }

    /**
     * Grouped by client when the action is not tied to one record.
     *
     * @return array<string, string>|array<string, array<string, string>>
     */
    protected static function receivableOptions(?Model $record): array
    {
        $label = fn (Receivable $receivable) => sprintf(
            '%s — vence %s — falta %s',
            $receivable->label,
            $receivable->due_on->format('d/m/Y'),
            Money::format($receivable->outstanding),
        );

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
