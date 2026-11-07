<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectStatus;
use App\Filament\Support\FinanceFields;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ProjectForm
{
    /** The full form, used by the edit page (the create page is a wizard built from the same parts). */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Trabalho')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        static::codeField(),
                        static::statusField(),
                        ...static::clientFields(),
                        ...static::projectFields(),
                    ]),

                Section::make('Valor')
                    ->columnSpan(1)
                    ->schema(static::valueFields()),

                Section::make('Plano de pagamento')
                    ->description('Uma linha por prestação. Para um pagamento único, deixa só uma linha com o valor total.')
                    ->columnSpanFull()
                    ->schema(static::planFields()),

                Section::make('Notas')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([static::notesField()]),
            ]);
    }

    public static function codeField(): TextInput
    {
        return TextInput::make('code')
            ->label('Código')
            ->placeholder('PRINTEE OC-202611-1')
            ->helperText('O código do orçamento.')
            ->unique(ignoreRecord: true)
            ->required();
    }

    public static function statusField(): Select
    {
        return Select::make('status')
            ->label('Estado')
            ->options(ProjectStatus::class)
            ->default(ProjectStatus::Approved)
            ->native(false)
            ->required();
    }

    /** @return array<int, Component> */
    public static function clientFields(): array
    {
        return [
            FinanceFields::clientSelect(),
            TextInput::make('business_line')
                ->label('Linha de negócio')
                ->datalist(['Printee', 'Pressiu'])
                ->placeholder('Printee, Pressiu…'),
        ];
    }

    /** Title, description, category and quote date. @return array<int, Component> */
    public static function projectFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Título')
                ->columnSpanFull()
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(2)
                ->columnSpanFull(),
            Select::make('category_id')
                ->label('Categoria')
                ->relationship('category', 'name', fn (Builder $query) => $query->income())
                ->native(false),
            DatePicker::make('quoted_on')
                ->label('Data do orçamento')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(today()),
        ];
    }

    /** @return array<int, Component> */
    public static function valueFields(): array
    {
        return [
            FinanceFields::netAmount(),
            FinanceFields::vatRate(),
            FinanceFields::totalPreview(),
        ];
    }

    /** The instalments repeater and the live "do they add up?" line. @return array<int, Component> */
    public static function planFields(): array
    {
        return [
            Repeater::make('receivables')
                ->hiddenLabel()
                ->relationship()
                ->orderColumn('sort')
                ->defaultItems(1)
                ->addActionLabel('Adicionar prestação')
                ->rule(fn (Get $get): Closure => static::instalmentPlanRule($get))
                ->columns(3)
                ->table([
                    Repeater\TableColumn::make('Descrição'),
                    Repeater\TableColumn::make('Valor'),
                    Repeater\TableColumn::make('Vencimento'),
                ])
                ->schema([
                    TextInput::make('description')
                        ->default('Pagamento único')
                        ->required(),
                    TextInput::make('amount')
                        ->numeric()
                        ->step(0.01)
                        ->suffix('€')
                        ->live(onBlur: true)
                        ->required(),
                    DatePicker::make('due_on')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                ]),
            TextEntry::make('plan_check')
                ->hiddenLabel()
                ->state(function (Get $get): HtmlString {
                    $total = static::totalFrom($get);
                    $planned = round(collect($get('receivables'))->sum(fn ($row) => (float) ($row['amount'] ?? 0)), 2);
                    $difference = round($total - $planned, 2);

                    $text = $difference == 0
                        ? 'As prestações somam o total do trabalho.'
                        : 'As prestações somam '.Money::format($planned).' — '.($difference > 0 ? 'faltam ' : 'sobram ').Money::format(abs($difference)).' para o total de '.Money::format($total).'.';

                    return new HtmlString('<span class="'.($difference == 0 ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400').'">'.e($text).'</span>');
                }),
        ];
    }

    public static function notesField(): Textarea
    {
        return Textarea::make('notes')->hiddenLabel()->rows(3);
    }

    public static function totalFrom(Get $get): float
    {
        return round((float) $get('net_amount') * (1 + (float) $get('vat_rate')), 2);
    }

    /**
     * The instalments must add up to the project total and none may fall due before the quote.
     */
    protected static function instalmentPlanRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $rows = collect(is_array($value) ? $value : []);
            $total = static::totalFrom($get);
            $planned = round($rows->sum(fn ($row) => (float) ($row['amount'] ?? 0)), 2);

            if (abs($total - $planned) > 0.009) {
                $fail('As prestações somam '.Money::format($planned).', mas o total do trabalho é '.Money::format($total).'.');
            }

            $quotedOn = $get('quoted_on');

            if (filled($quotedOn) && $rows->contains(fn ($row) => filled($row['due_on'] ?? null) && Carbon::parse($row['due_on'])->lt(Carbon::parse($quotedOn)))) {
                $fail('Nenhuma prestação pode vencer antes da data do orçamento ('.Carbon::parse($quotedOn)->format('d/m/Y').').');
            }
        };
    }
}
