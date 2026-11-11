<?php

namespace App\Filament\Pages;

use App\Actions\ManageFinanceReview;
use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Models\FinanceReviewItem;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class AccountantReview extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.accountant-review';

    protected static ?string $slug = 'revisao-contabilistica';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ListChecks;

    public static function canAccess(): bool
    {
        return AccountantReviewRecords::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('expenses')->label(__('Expenses'))
                ->url(AccountantReviewRecords::getUrl(['source' => FinanceReviewTarget::Expense->value])),
            Action::make('documents')->label(__('Tax documents'))
                ->url(AccountantReviewRecords::getUrl(['source' => FinanceReviewTarget::FiscalDocument->value])),
        ];
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        return $table
            ->query(FinanceReviewItem::query()->with('author'))
            ->columns([
                TextColumn::make('kind')->label(__('Type'))->badge(),
                TextColumn::make('target_label')->label(__('Entry'))
                    ->formatStateUsing(fn (string $state, FinanceReviewItem $record): string => $record->target_type->label().' '.$state)
                    ->description(fn (FinanceReviewItem $record): ?string => $record->{$record->target_type->foreignKey()} === null ? __('Record deleted') : null)
                    ->url(fn (FinanceReviewItem $record): ?string => ($id = $record->{$record->target_type->foreignKey()}) === null
                        ? null
                        : AccountantReviewRecords::getUrl(['source' => $record->target_type->value, 'record' => $id])),
                TextColumn::make('body')->label(__('Message'))->wrap(),
                TextColumn::make('author.name')->label(__('Author'))->placeholder(__('User deleted')),
                TextColumn::make('created_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (FinanceReviewItem $record): ?string => match (true) {
                        $record->kind === FinanceReviewKind::Comment => null,
                        $record->isPending() => __('Pending'),
                        default => __('Resolved'),
                    })
                    ->badge()
                    ->color(fn (FinanceReviewItem $record): string => $record->isPending() ? 'warning' : 'success'),
            ])
            ->filters([
                SelectFilter::make('requestStatus')->label(__('Request status'))
                    ->options(['pending' => __('Pending'), 'resolved' => __('Resolved')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pending' => $query->where('kind', FinanceReviewKind::EvidenceRequest)->whereNull('resolved_at'),
                        'resolved' => $query->where('kind', FinanceReviewKind::EvidenceRequest)->whereNotNull('resolved_at'),
                        default => $query,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('There are no comments or requests yet'))
            ->recordActions([
                Action::make('resolveRequest')
                    ->label(__('Mark as resolved'))
                    ->requiresConfirmation()
                    ->visible(fn (FinanceReviewItem $record): bool => $record->isPending() && self::canResolve())
                    ->action(fn (FinanceReviewItem $record) => app(ManageFinanceReview::class)->resolve((int) $record->getKey())),
            ]);
    }

    private static function canResolve(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && $account->hasAccess() && AccountantAccess::canResolve($account);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Accounting review');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Analysis');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Accounting review');
    }
}
