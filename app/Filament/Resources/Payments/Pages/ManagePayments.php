<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Actions\OneOffIncomeAction;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Widgets\PaymentsSummary;
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
            RecordPaymentAction::make()->after(fn () => $this->resetTable()),
        ];
    }
}
