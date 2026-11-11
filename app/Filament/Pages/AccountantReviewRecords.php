<?php

namespace App\Filament\Pages;

use App\Actions\ManageFinanceReview;
use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;

class AccountantReviewRecords extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.accountant-review';

    protected static ?string $slug = 'registos-da-revisao/{source}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public FinanceReviewTarget $target;

    #[Locked]
    public ?int $recordId = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'registos-da-revisao';
    }

    public static function canAccess(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canReview($account);
    }

    public function mount(string $source): void
    {
        abort_unless(static::canAccess(), 403);

        $this->target = FinanceReviewTarget::tryFrom($source) ?? abort(404);
        if (request()->query->has('record')) {
            $this->recordId = $this->target->query()->findOrFail(request()->integer('record'))->getKey();
        }
    }

    public function getTitle(): string
    {
        return match ($this->target ?? null) {
            FinanceReviewTarget::Expense => __('Expenses for review'),
            FinanceReviewTarget::FiscalDocument => __('Tax documents for review'),
            default => __('Records for review'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label(__('Back to review'))->url(AccountantReview::getUrl()),
            Action::make('allRecords')->label(__('View all records'))
                ->visible(fn (): bool => $this->recordId !== null)
                ->url(fn (): string => static::getUrl(['source' => $this->target->value])),
        ];
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        $isExpense = $this->target === FinanceReviewTarget::Expense;
        $date = $isExpense ? 'spent_on' : 'document_date';
        $query = $this->target->query();

        if ($this->recordId !== null) {
            $query->whereKey($this->recordId);
        }

        return $table
            ->query($query)
            ->columns($isExpense ? [
                TextColumn::make('description')->label(__('Description'))->searchable()->wrap(),
                TextColumn::make('invoice_number')->label(__('Document number'))->searchable(),
                TextColumn::make($date)->label(__('Date'))->date()->sortable(),
                TextColumn::make('total')->label(__('Total'))->formatStateUsing(fn (int $state): string => Money::format($state, locale: app()->getLocale())),
                TextColumn::make('proof')
                    ->label(__('Proof'))
                    ->state(fn (Expense $record): string => $record->hasProof() ? __('Available') : __('Missing'))
                    ->badge()
                    ->color(fn (Expense $record): string => $record->hasProof() ? 'success' : 'gray'),
            ] : [
                TextColumn::make('number')->label(__('Document number'))->searchable(),
                TextColumn::make('party_name')->label(__('Entity'))->searchable()->wrap(),
                TextColumn::make('direction')->label(__('Document direction'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make($date)->label(__('Date'))->date()->sortable(),
                TextColumn::make('total_amount')->label(__('Total'))
                    ->formatStateUsing(fn (int $state, Model $record): string => (string) Number::currency($state / 100, $record->currency, app()->getLocale())),
            ])
            ->defaultSort($date, 'desc')
            ->recordActions([
                ...($isExpense ? [
                    Action::make('downloadProof')->label(__('Download PDF'))
                        ->visible(fn (Expense $record): bool => $record->hasProof())
                        ->url(fn (Expense $record): string => ExpenseResource::getUrl('download-proof', ['record' => $record])),
                ] : []),
                $this->reviewAction('comment', __('Add comment'), FinanceReviewKind::Comment),
                $this->reviewAction('requestEvidence', __('Request proof'), FinanceReviewKind::EvidenceRequest),
            ]);
    }

    private function reviewAction(string $name, string $label, FinanceReviewKind $kind): Action
    {
        return Action::make($name)
            ->label($label)
            ->visible(fn (): bool => static::canAccess() && CurrentAccount::get()?->hasAccess())
            ->schema([
                Textarea::make('body')->label($kind->getLabel())->required()->maxLength(ManageFinanceReview::MAX_BODY_LENGTH),
            ])
            ->action(fn (Model $record, array $data) => app(ManageFinanceReview::class)->add(
                $this->target, (int) $record->getKey(), $kind, $data['body'],
            ))
            ->successNotificationTitle($kind === FinanceReviewKind::Comment ? __('Comment saved') : __('Request saved'));
    }
}
