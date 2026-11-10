<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\ManageCustomerAdvance;
use App\Enums\PaymentMethod;
use App\Filament\Actions\OneOffIncomeAction;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\RecordSplitPaymentAction;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Widgets\PaymentsSummary;
use App\Models\Client;
use App\Models\Payment;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [PaymentsSummary::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            OneOffIncomeAction::make()->color('gray')->after(fn () => $this->resetTable()),
            Action::make('recordCustomerAdvance')
                ->label('Registar sinal')
                ->color('gray')
                ->authorize(fn (): bool => auth()->user()?->can('create', Payment::class) ?? false)
                ->disabled(fn (): bool => CurrentAccount::readOnly())
                ->modalDescription('Regista a entrada de dinheiro. Podes afetar o sinal a uma cobrança mais tarde.')
                ->schema([
                    Select::make('client_id')->label('Cliente')->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('amount')->label('Montante recebido')->suffix('€')->inputMode('decimal')
                        ->regex('/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/')->required(),
                    DatePicker::make('paid_on')->label('Data')->default(today())->maxDate(today())->required(),
                    Select::make('method')->label('Método')->options(PaymentMethod::class)->default(PaymentMethod::Transfer->value)->required(),
                ])
                ->action(function (array $data, ManageCustomerAdvance $service): void {
                    $method = $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']);
                    $service->record((int) $data['client_id'], Money::toCents($data['amount']), $data['paid_on'], $method);
                    Notification::make()->title('Sinal registado')->success()->send();
                    $this->resetTable();
                }),
            RecordSplitPaymentAction::make()->after(fn () => $this->resetTable()),
            RecordPaymentAction::make()->after(fn () => $this->resetTable()),
        ];
    }
}
