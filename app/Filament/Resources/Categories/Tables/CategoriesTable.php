<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Project;
use App\Models\Retainer;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('usage')
                    ->label(__('Uses'))
                    ->state(fn (Category $record) => static::usage($record))
                    ->numeric()
                    ->alignCenter(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modalWidth('md'),
                    DeleteAction::make()
                        ->visible(fn (Category $record) => static::usage($record) === 0),
                ]),
            ])
            ->emptyStateHeading(__('No categories'));
    }

    protected static function usage(Category $category): int
    {
        return $category->type === CategoryType::Income
            ? Project::query()->where('category_id', $category->id)->count() + Retainer::query()->where('category_id', $category->id)->count()
            : $category->expenses()->count();
    }
}
