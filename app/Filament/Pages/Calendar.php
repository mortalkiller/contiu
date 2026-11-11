<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\ActivityCalendarWidget;
use App\Filament\Analysis\Widgets\MonthAhead;
use App\Filament\Analysis\Widgets\UpcomingDeadlines;
use App\Filament\Analysis\Widgets\WeekAhead;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Calendário": everything in the account that has a date, read-only.
 */
class Calendar extends Page
{
    protected static ?string $slug = 'calendario';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CalendarDots;

    /** At the top of the menu, with Hoje and Fecho do mês: outside any group. */
    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = -1;

    public function getSubheading(): string
    {
        return __('See what’s on the calendar: tax obligations, payments received, receivables, expenses and entries yet to be generated.');
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
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([WeekAhead::class, MonthAhead::class, UpcomingDeadlines::class])),
            ]),
        ]);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Calendar');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Calendar');
    }
}
