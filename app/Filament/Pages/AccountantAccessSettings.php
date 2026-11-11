<?php

namespace App\Filament\Pages;

use App\Filament\Support\AccountantAccessTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class AccountantAccessSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.accountant-review';

    protected static ?string $slug = 'acesso-de-contabilistas';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Users;

    public static function canAccess(): bool
    {
        return AccountantAccessTable::canManage();
    }

    protected function getHeaderActions(): array
    {
        return [AccountantAccessTable::grantAction()];
    }

    public function table(Table $table): Table
    {
        return AccountantAccessTable::configure($table);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Accountant access');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Accountant access');
    }
}
