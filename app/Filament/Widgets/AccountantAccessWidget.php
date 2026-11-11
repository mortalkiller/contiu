<?php

namespace App\Filament\Widgets;

use App\Filament\Support\AccountantAccessTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * "Acesso de contabilistas" on the "Assinatura" page, as in the mock: who can see the account's
 * accountant review, with the ways to grant and revoke it.
 */
class AccountantAccessWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return AccountantAccessTable::canManage();
    }

    public function table(Table $table): Table
    {
        return AccountantAccessTable::configure($table)
            ->heading(__('Accountant access'))
            ->description(__('They see this account\'s accountant review. They cannot create or edit records.'))
            ->headerActions([AccountantAccessTable::grantAction()])
            ->paginated(false);
    }
}
