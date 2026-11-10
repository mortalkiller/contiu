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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class AccountantReview extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.accountant-review';

    protected static ?string $slug = 'revisao-contabilistica';

    protected static ?string $title = 'Revisão contabilística';

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ListChecks;

    public static function canAccess(): bool
    {
        return AccountantReviewRecords::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('expenses')->label('Despesas')
                ->url(AccountantReviewRecords::getUrl(['source' => FinanceReviewTarget::Expense->value])),
            Action::make('documents')->label('Documentos fiscais')
                ->url(AccountantReviewRecords::getUrl(['source' => FinanceReviewTarget::FiscalDocument->value])),
        ];
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        return $table
            ->query(FinanceReviewItem::query()->with('author'))
            ->columns([
                TextColumn::make('kind')->label('Tipo')->badge(),
                TextColumn::make('target_label')->label('Registo')
                    ->formatStateUsing(fn (string $state, FinanceReviewItem $record): string => $record->target_type->label().' '.$state)
                    ->description(fn (FinanceReviewItem $record): ?string => $record->{$record->target_type->foreignKey()} === null ? 'Registo removido' : null)
                    ->url(fn (FinanceReviewItem $record): ?string => ($id = $record->{$record->target_type->foreignKey()}) === null
                        ? null
                        : AccountantReviewRecords::getUrl(['source' => $record->target_type->value, 'record' => $id])),
                TextColumn::make('body')->label('Mensagem')->wrap(),
                TextColumn::make('author.name')->label('Autor')->placeholder('Utilizador removido'),
                TextColumn::make('created_at')->label('Data')->dateTime()->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->state(fn (FinanceReviewItem $record): ?string => match (true) {
                        $record->kind === FinanceReviewKind::Comment => null,
                        $record->isPending() => 'Pendente',
                        default => 'Resolvido',
                    })
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Pendente' ? 'warning' : 'success'),
            ])
            ->filters([
                SelectFilter::make('requestStatus')->label('Estado dos pedidos')
                    ->options(['pending' => 'Pendente', 'resolved' => 'Resolvido'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pending' => $query->where('kind', FinanceReviewKind::EvidenceRequest)->whereNull('resolved_at'),
                        'resolved' => $query->where('kind', FinanceReviewKind::EvidenceRequest)->whereNotNull('resolved_at'),
                        default => $query,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Ainda não há comentários ou pedidos')
            ->recordActions([
                Action::make('resolveRequest')
                    ->label('Marcar como resolvido')
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
}
