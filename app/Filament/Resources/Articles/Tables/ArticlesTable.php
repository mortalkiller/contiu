<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Models\Article;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->searchPlaceholder(__('Name or code'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Article'))
                    ->weight('medium')
                    ->description(fn (Article $record): HtmlString => static::codeLine($record))
                    ->searchable(['name', 'code'])
                    ->sortable(),
                TextColumn::make('unit')
                    ->label(__('Unit'))
                    ->formatStateUsing(fn (string $state): string => ArticleForm::unitLabel($state)),
                TextColumn::make('unit_price')
                    ->label(__('Price'))
                    ->money('EUR', divideBy: 100)
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('vat_basis_points')
                    ->label(__('VAT'))
                    ->formatStateUsing(fn (int $state): string => ArticleForm::vatLabel($state))
                    ->placeholder('—'),
                TextColumn::make('lines_count')
                    ->label(__('Times used'))
                    ->state(fn (Article $record): ?int => $record->lines_count ?: null)
                    ->numeric()
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('at_provider')
                    ->label(fn (): ?string => Invoicing::providerLabel())
                    ->state(fn (Article $record): string => ArticleResource::isAtProvider($record) ? __('In sync') : __('Sent when issuing'))
                    ->color(fn (Article $record): ?string => ArticleResource::isAtProvider($record) ? null : 'gray')
                    ->visible(fn (): bool => Invoicing::connected()),
            ])
            ->recordActions([
                EditAction::make()
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->icon(null)
                    ->extraModalFooterActions(fn (EditAction $action): array => [
                        DeleteAction::make()
                            // The article being edited, read once the form is open.
                            ->record(fn (): ?Model => $action->getRecord())
                            ->visible(fn (?Article $record): bool => $record !== null
                                && $record->lines_count === 0
                                && ArticleResource::canDelete($record))
                            ->cancelParentActions(),
                    ]),
            ])
            ->emptyStateHeading(__('No articles'))
            ->emptyStateDescription(__('Create one for what you bill often, or save a line as an article while writing an invoice.'));
    }

    /** Under the name, as in the mock: the code in a small monospaced font, and "Inativo" when it is no longer offered. */
    private static function codeLine(Article $article): HtmlString
    {
        $code = '<span class="font-mono text-xs">'.e($article->code).'</span>';

        return new HtmlString($article->is_active ? $code : $code.' · '.e(__('Inactive')));
    }
}
