<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use App\Filament\Analysis\Widgets\UpcomingDeadlines;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
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

    /** At the top of the menu, with Hoje and Fecho do mês: outside any group. */
    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = -1;

    public function getSubheading(): string
    {
        return 'Tudo o que tem data: obrigações fiscais, recebimentos, valores a receber, despesas e o que ainda vai ser gerado.';
    }

    public function content(Schema $schema): Schema
    {
        // The calendar with the deadlines beside it; stacked (calendar first) below lg.
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 4])->schema([
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'lg' => 3])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([ActivityCalendarWidget::class])),
                Grid::make(1)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([UpcomingDeadlines::class])),
            ]),
        ]);
    }
}
