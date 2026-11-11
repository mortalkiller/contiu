<?php

namespace App\Filament\Actions;

use App\Actions\RecordAllocatedPayment;
use App\Enums\PaymentMethod;
use App\Filament\Actions\Concerns\DisabledWhenReadOnly;
use App\Filament\Support\MoneyInput;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class RecordSplitPaymentAction extends Action
{
    use DisabledWhenReadOnly;

    public static function getDefaultName(): ?string
    {
        return 'recordSplitPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Record split payment'))
            ->icon(Phosphor::HandCoins)
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create', Payment::class) ?? false)
            ->modalDescription(__('Allocate a single payment to two or more receivables for the same client.'))
            ->modalSubmitActionLabel(__('Record'))
            ->schema([
                DatePicker::make('paid_on')->label(__('Date'))->default(today())->maxDate(today())->required(),
                Select::make('method')->label(__('Method'))->options(PaymentMethod::class)->default(PaymentMethod::Transfer->value)->required(),
                MoneyInput::make('amount')->label(__('Total received'))->minValue(0.01)->rule('decimal:0,2')->required(),
                Repeater::make('allocations')->label(__('Allocations'))
                    ->defaultItems(2)->minItems(2)->reorderable(false)->addActionLabel(__('Add receivable'))->columns(2)
                    ->schema([
                        Select::make('receivable_id')->label(__('Charge'))->searchable()->required()->distinct()
                            ->options(fn (): array => Receivable::query()->open()->excludingCancelled()->with(['payments', 'billable.client'])
                                ->orderBy('due_on')->get()->filter(fn (Receivable $record): bool => $record->client !== null)
                                ->mapWithKeys(fn (Receivable $record): array => [$record->id => $record->client->name.' · '.$record->label.' · '.Money::format($record->outstanding)])->all()),
                        MoneyInput::make('amount')->label(__('Amount to allocate'))->minValue(0.01)->rule('decimal:0,2')->required(),
                    ]),
                Toggle::make('tax_exempt')->label(__('Exempt from taxes'))->default(fn (): bool => app(FinanceSettings::class)->paymentsExemptByDefault()),
            ])
            ->action(function (array $data, Schema $schema): void {
                $amounts = collect($data['allocations'])->pluck('amount', 'receivable_id')->all();
                unset($data['allocations']);

                try {
                    app(RecordAllocatedPayment::class)->handle($data, $amounts);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        $schema->getStatePath().'.allocations' => $exception->errors()['allocations'],
                    ]);
                }

                Notification::make()->title(__('Split payment recorded'))->success()->send();
            });
    }
}
