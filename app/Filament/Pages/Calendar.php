<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Calendário": everything in the account that has a date, read-only.
 */
class Calendar extends Page
{
    protected static ?string $title = 'Calendário';

    protected static ?string $slug = 'calendario';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CalendarDots;

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

    protected static ?int $navigationSort = 4;

    public function getSubheading(): string
    {
        return 'Tudo o que tem data: obrigações fiscais, recebimentos, valores a receber, despesas e o que ainda vai ser gerado.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->getWidgetsSchemaComponents([ActivityCalendarWidget::class]),
        ]);
    }
}
