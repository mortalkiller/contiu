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
            FinanceReviewTarget::Expense => 'Despesas para revisão',
            FinanceReviewTarget::FiscalDocument => 'Documentos fiscais para revisão',
            default => 'Registos para revisão',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label('Voltar à revisão')->url(AccountantReview::getUrl()),
            Action::make('allRecords')->label('Ver todos os registos')
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
                TextColumn::make('description')->label('Descrição')->searchable()->wrap(),
                TextColumn::make('invoice_number')->label('N.º do documento')->searchable(),
                TextColumn::make($date)->label('Data')->date()->sortable(),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('proof')
                    ->label('Comprovativo')
                    ->state(fn (Expense $record): string => $record->hasProof() ? 'Disponível' : 'Em falta')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Disponível' ? 'success' : 'gray'),
            ] : [
                TextColumn::make('number')->label('N.º do documento')->searchable(),
                TextColumn::make('party_name')->label('Entidade')->searchable()->wrap(),
                TextColumn::make('direction')->label('Direção')->badge(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make($date)->label('Data')->date()->sortable(),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn (int $state, Model $record): string => (string) Number::currency($state / 100, $record->currency, 'pt_PT')),
            ])
            ->defaultSort($date, 'desc')
            ->recordActions([
                ...($isExpense ? [
                    Action::make('downloadProof')->label('Descarregar PDF')
                        ->visible(fn (Expense $record): bool => $record->hasProof())
                        ->url(fn (Expense $record): string => ExpenseResource::getUrl('download-proof', ['record' => $record])),
                ] : []),
                $this->reviewAction('comment', 'Comentar', FinanceReviewKind::Comment),
                $this->reviewAction('requestEvidence', 'Pedir comprovativo', FinanceReviewKind::EvidenceRequest),
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
            ->successNotificationTitle($kind === FinanceReviewKind::Comment ? 'Comentário guardado' : 'Pedido guardado');
    }
}
