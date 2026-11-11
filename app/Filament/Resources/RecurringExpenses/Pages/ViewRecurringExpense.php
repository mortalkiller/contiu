<?php

namespace App\Filament\Resources\RecurringExpenses\Pages;

use App\Actions\GenerateRecurringExpenses;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\RecurringExpenses\Schemas\RecurringExpenseHeader;
use App\Filament\Resources\RecurringExpenses\Widgets\RecurringExpenseSummary;
use App\Filament\Support\DetailPage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * A recurring expense's own page, as in the mock: what repeats and how, four figures, the
 * expenses it has created and, beside them, its rule, its classification and its supplier.
 */
class ViewRecurringExpense extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = RecurringExpenseResource::class;

    /**
     * The dates still to be created, read once per request.
     *
     * @var list<CarbonImmutable>|null
     */
    private ?array $upcomingDates = null;

    /**
     * The date "Generate" offered when the page opened. The action only ever creates that one, so
     * a second click, or the scheduler running in between, cannot create the period after it.
     */
    #[Locked]
    public ?string $offeredDate = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->offeredDate = $this->nextDate()?->toDateString();
    }

    /** "Recurring › Internet": the page being the record's own is said by its title. */
    public function getBreadcrumbs(): array
    {
        return array_slice(parent::getBreadcrumbs(), 0, -1, preserve_keys: true);
    }

    /** As in the mock: the menu of everything else, pausing, editing, then creating the next expense ahead of its date. */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()->successRedirectUrl(RecurringExpenseResource::getUrl()),
            ])->label(__('More actions')),
            $this->switchTo(active: false),
            $this->switchTo(active: true),
            EditAction::make()
                ->color('gray')
                ->modalWidth('3xl')
                ->schema(fn (Schema $schema): Schema => RecurringExpenseResource::form($schema))
                ->mutateDataUsing(fn (array $data): array => ExpenseForm::normalize($data))
                ->successRedirectUrl(fn (): string => $this->pageUrl()),
            $this->generateNext(),
        ];
    }

    /** The four figures, between the title and the records. */
    protected function getHeaderWidgets(): array
    {
        return [RecurringExpenseSummary::class];
    }

    /** As in the mock: the expenses take the width of the page, with the rule and the rest in a narrower column beside them. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 7])->schema([
                Group::make([$this->getRelationManagersContentComponent()])
                    ->columnSpan(['default' => 1, 'xl' => 5]),
                Group::make([$this->getInfolistContentComponent()])
                    ->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }

    /**
     * "Pausar" on an active one and "Retomar" on a paused one: the switch the list has on each
     * row, with the same permission as editing.
     */
    private function switchTo(bool $active): Action
    {
        return Action::make($active ? 'resume' : 'pause')
            ->label($active ? __('Resume') : __('Pause'))
            ->color('gray')
            ->visible(fn (): bool => $this->record->is_active !== $active && RecurringExpenseResource::canEdit($this->record))
            ->action(function () use ($active): void {
                RecurringExpenseResource::authorizeEdit($this->record);
                $this->record->update(['is_active' => $active]);

                Notification::make()->success()
                    ->title($active ? __('Recurring expense resumed') : __('Recurring expense paused'))
                    ->send();
                $this->redirect($this->pageUrl());
            });
    }

    /**
     * "Gerar a de dezembro": creates now, unpaid, the expense the scheduled generator would
     * create next, with the generator itself. Nothing to offer while paused or after the end date.
     */
    private function generateNext(): Action
    {
        return Action::make('generateNext')
            ->label(fn (): string => __('Generate the one for :month', ['month' => $this->nextMonth()]))
            ->icon(Phosphor::Plus)
            ->visible(fn (): bool => $this->nextDate() !== null && ExpenseResource::canCreate())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('The expense of :date is created now, unpaid. The ones after it are still created on their own.', [
                'date' => $this->nextDate() === null ? '' : DetailPage::date($this->nextDate()),
            ]))
            ->action(function (): void {
                abort_unless(ExpenseResource::canCreate(), 403);

                $created = Cache::lock("recurring-expense:generate:{$this->record->getKey()}", 10)->get(function (): int {
                    $this->upcomingDates = null;
                    $next = $this->nextDate();

                    return $next === null || $next->toDateString() !== $this->offeredDate
                        ? 0
                        : app(GenerateRecurringExpenses::class)->forTemplate($this->record, $next);
                });

                if (! $created) {
                    Notification::make()->warning()->title(__('There is no expense to generate.'))->send();
                    $this->redirect($this->pageUrl());

                    return;
                }

                Notification::make()->success()->title(__('Expense for :month created', ['month' => $this->nextMonth()]))->send();
                $this->redirect($this->pageUrl());
            });
    }

    private function nextDate(): ?CarbonImmutable
    {
        $this->upcomingDates ??= RecurringExpenseHeader::upcomingDates($this->record);

        return $this->upcomingDates[0] ?? null;
    }

    /** "dezembro", with the year when it is not this one. */
    private function nextMonth(): string
    {
        $next = $this->nextDate();

        return $next === null ? '' : $next->locale(app()->getLocale())->translatedFormat($next->isCurrentYear() ? 'F' : 'F Y');
    }

    private function pageUrl(): string
    {
        return RecurringExpenseResource::getUrl('view', ['record' => $this->record]);
    }
}
