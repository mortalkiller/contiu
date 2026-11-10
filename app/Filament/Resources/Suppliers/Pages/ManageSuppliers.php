<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Suppliers\Widgets\SuppliersSummary;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SuppliersSummary::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo fornecedor'),
        ];
    }
}
