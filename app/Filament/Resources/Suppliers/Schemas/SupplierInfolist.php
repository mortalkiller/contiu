<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\Language;
use App\Filament\Pages\BankStatementImport;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SupplierInfolist
{
    /**
     * The column beside the supplier's records (see ViewSupplier::content()): the supplier's
     * details, then the bank statement lines already tied to its expenses.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->components([
                Section::make(__('Supplier details'))
                    ->inlineLabel()
                    // One line per entry, label then value, as the mock's key/value list (filament-appearance).
                    ->extraAttributes(['class' => 'fap-key-value'])
                    ->schema([
                        TextEntry::make('vat_number')->label(__('Tax ID'))->placeholder('—')->fontFamily('mono'),
                        TextEntry::make('seat')->label(__('Location')),
                        TextEntry::make('website')->label(__('Website'))->placeholder('—')
                            ->url(fn (Supplier $record): ?string => $record->website, shouldOpenInNewTab: true)
                            ->formatStateUsing(fn (?string $state): ?string => $state ? preg_replace('#^https?://(www\.)?#', '', $state) : null)
                            ->color('primary'),
                        TextEntry::make('usual_category')->label(__('Usual category'))->placeholder('—')
                            ->state(fn (Supplier $record): ?string => $record->expenses()->with('category')->get()
                                ->countBy(fn (Expense $expense): string => $expense->category?->name ?? '')
                                ->forget('')->sortDesc()->keys()->first()),
                        TextEntry::make('last_expense_on')->label(__('Last expense'))->placeholder('—')->date()
                            ->state(fn (Supplier $record) => $record->expenses()->max('spent_on')),
                        TextEntry::make('notes')->label(__('Notes'))->inlineLabel(false)
                            ->visible(fn (Supplier $record): bool => filled($record->notes)),
                    ]),
                Section::make(__('On the bank statement'))
                    ->headerActions([
                        Action::make('statement')->label(__('Open bank statement'))->link()
                            ->visible(fn (): bool => BankStatementImport::canAccess())
                            ->url(fn (): string => BankStatementImport::getUrl()),
                    ])
                    ->schema([
                        View::make('filament.projects.timeline')
                            ->viewData(fn (Supplier $record): array => ['events' => static::bankEvents($record)])
                            ->visible(fn (Supplier $record): bool => static::bankEvents($record) !== []),
                        TextEntry::make('no_bank_lines')->hiddenLabel()->color('gray')
                            ->state(__('No statement line is tied to this supplier\'s expenses yet.'))
                            ->visible(fn (Supplier $record): bool => static::bankEvents($record) === []),
                    ]),
            ]);
    }

    /**
     * The latest statement lines tied to the supplier's expenses, in the shape of the timeline
     * view: the bank's description, when and to which expense, and the amount.
     *
     * @return list<array{state: string, title: string, meta: string, amount: string}>
     */
    private static function bankEvents(Supplier $supplier): array
    {
        return once(function () use ($supplier): array {
            $format = Language::resolve(app()->getLocale())->dateFormat();

            return $supplier->expenses()->with('bankLink.bankTransaction')->get()
                ->filter(fn (Expense $expense): bool => $expense->bankLink?->bankTransaction !== null)
                ->sortByDesc(fn (Expense $expense) => $expense->bankLink->bankTransaction->booked_on)
                ->take(5)
                ->map(fn (Expense $expense): array => [
                    'state' => 'done',
                    'title' => (string) $expense->bankLink->bankTransaction->description,
                    'meta' => $expense->bankLink->bankTransaction->booked_on->format($format).' · '.$expense->description,
                    'amount' => Money::format((int) $expense->bankLink->bankTransaction->amount),
                ])
                ->values()
                ->all();
        });
    }
}
