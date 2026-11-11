<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\ManageCustomerAdvance;
use App\Enums\PaymentMethod;
use App\Filament\Actions\OneOffIncomeAction;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\RecordSplitPaymentAction;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Widgets\PaymentsSummary;
use App\Filament\Support\QuickCreate;
use App\Models\Client;
use App\Models\Payment;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** "What has come in. €3,650.00 in 2026." */
    public function getSubheading(): string
    {
        return __('What has come in. :amount in :year.', [
            'amount' => Money::format((int) Payment::query()->whereYear('paid_on', today()->year)->sum('amount')),
            'year' => today()->year,
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [PaymentsSummary::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            // The mock shows three buttons; the export and the split payment are under "More actions".
            ActionGroup::make([
                ExportAction::make()
                    ->label(__('Export to Excel'))
                    ->icon(Phosphor::DownloadSimple)
                    ->exports([PaymentResource::excelExport()]),
                RecordSplitPaymentAction::make()->after(fn () => $this->resetTable()),
            ])
                ->label(__('More actions'))
                ->icon(Phosphor::DotsThree)
                ->color('gray')
                ->iconButton(),
            OneOffIncomeAction::make()->color('gray')->after(fn () => $this->resetTable()),
            Action::make('recordCustomerAdvance')
                ->label(__('Record advance payment'))
                ->color('gray')
                ->authorize(fn (): bool => auth()->user()?->can('create', Payment::class) ?? false)
                ->disabled(fn (): bool => CurrentAccount::readOnly())
                ->modalDescription(__('Record the payment you received. You can allocate the advance to a charge later.'))
                ->schema([
                    QuickCreate::client(Select::make('client_id')->label(__('Client'))->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required()),
                    TextInput::make('amount')->label(__('Amount received'))->suffix('€')->inputMode('decimal')
                        ->regex('/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/')->required(),
                    DatePicker::make('paid_on')->label(__('Date'))->default(today())->maxDate(today())->required(),
                    Select::make('method')->label(__('Method'))->options(PaymentMethod::class)->default(PaymentMethod::Transfer->value)->required(),
                ])
                ->action(function (array $data, ManageCustomerAdvance $service): void {
                    $method = $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']);
                    $service->record((int) $data['client_id'], Money::toCents($data['amount']), $data['paid_on'], $method);
                    Notification::make()->title(__('Advance payment recorded'))->success()->send();
                    $this->resetTable();
                }),
            RecordPaymentAction::make()->icon(null)->after(fn () => $this->resetTable()),
        ];
    }
}
