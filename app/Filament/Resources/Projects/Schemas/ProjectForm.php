<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\CategoryType;
use App\Enums\Language;
use App\Enums\ProjectStatus;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\QuickCreate;
use App\Support\CurrentAccount;
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
use Illuminate\Validation\Rules\Unique;

class ProjectForm
{
    /** The full form, used by the edit page (the create page is a wizard built from the same parts). */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('Project'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        static::codeField(),
                        static::statusField(),
                        ...static::clientFields(),
                        ...static::projectFields(),
                    ]),

                Section::make(__('Amount'))
                    ->columnSpan(1)
                    ->schema(static::valueFields()),

                Section::make(__('Payment plan'))
                    ->description(__('Add one row per instalment. For a single payment, leave one row with the full amount.'))
                    ->columnSpanFull()
                    ->schema(static::planFields()),

                Section::make(__('Notes'))
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([static::notesField()]),
            ]);
    }

    public static function codeField(): TextInput
    {
        return TextInput::make('code')
            ->label(__('Code'))
            ->placeholder('ORC-2026-001')
            ->helperText(__('The quote code.'))
            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('account_id', CurrentAccount::id())) // codes repeat across accounts
            ->required();
    }

    public static function statusField(): Select
    {
        return Select::make('status')
            ->label(__('Status'))
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
                ->label(__('Business line'))
                ->datalist(fn () => FinanceFields::businessLines())
                ->placeholder(__('E.g. Design, Consulting…')),
        ];
    }

    /** Title, description, category and quote date. @return array<int, Component> */
    public static function projectFields(): array
    {
        return [
            TextInput::make('title')
                ->label(__('Title'))
                ->columnSpanFull()
                ->required(),
            Textarea::make('description')
                ->label(__('Description'))
                ->rows(2)
                ->columnSpanFull(),
            QuickCreate::category(Select::make('category_id')
                ->label(__('Category'))
                ->relationship('category', 'name', fn (Builder $query) => $query->income())
                ->native(false), CategoryType::Income),
            DatePicker::make('quoted_on')
                ->label(__('Quote date'))
                ->native(false)
                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
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
                ->addActionLabel(__('Add instalment'))
                ->rule(fn (Get $get): Closure => static::instalmentPlanRule($get))
                ->columns(3)
                ->table([
                    Repeater\TableColumn::make(__('Description')),
                    Repeater\TableColumn::make(__('Amount')),
                    Repeater\TableColumn::make(__('Due date')),
                ])
                ->schema([
                    TextInput::make('description')
                        ->default(__('Single payment'))
                        ->required(),
                    MoneyInput::make('amount')
                        ->required(),
                    DatePicker::make('due_on')
                        ->native(false)
                        ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                        ->required(),
                ]),
            TextEntry::make('plan_check')
                ->hiddenLabel()
                ->state(function (Get $get): HtmlString {
                    $total = static::totalFrom($get);
                    $planned = static::plannedCents($get('receivables'));
                    $difference = $total - $planned;

                    $amounts = [
                        'planned' => Money::format($planned),
                        'difference' => Money::format(abs($difference)),
                        'total' => Money::format($total),
                    ];
                    $messages = [
                        'below' => __('The instalments add up to :planned — :difference below the total of :total.'),
                        'above' => __('The instalments add up to :planned — :difference above the total of :total.'),
                        'exact' => __('The instalments add up to the project total.'),
                    ];
                    $message = match (true) {
                        $difference > 0 => $messages['below'],
                        $difference < 0 => $messages['above'],
                        default => $messages['exact'],
                    };

                    return FinanceFields::preview('plan', strtr($message, [
                        ':planned' => $amounts['planned'],
                        ':difference' => $amounts['difference'],
                        ':total' => $amounts['total'],
                    ]), $messages);
                }),
        ];
    }

    public static function notesField(): Textarea
    {
        return Textarea::make('notes')->hiddenLabel()->rows(3);
    }

    /** Project total in cents from the form (which holds euros). */
    public static function totalFrom(Get $get): int
    {
        $net = Money::toCents($get('net_amount')) ?? 0;

        return $net + Money::vat($net, (string) ($get('vat_rate') ?? 0));
    }

    /** Sum of the instalment rows (euros in the form) in cents. */
    public static function plannedCents(mixed $rows): int
    {
        return (int) collect(is_array($rows) ? $rows : [])->sum(fn ($row) => Money::toCents($row['amount'] ?? null) ?? 0);
    }

    /**
     * The instalments must add up to the project total and none may fall due before the quote.
     */
    protected static function instalmentPlanRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $rows = collect(is_array($value) ? $value : []);
            $total = static::totalFrom($get);
            $planned = static::plannedCents($rows->all());

            if ($total !== $planned) {
                $fail(__('The instalments add up to :planned, but the project total is :total.', [
                    'planned' => Money::format($planned),
                    'total' => Money::format($total),
                ]));
            }

            $quotedOn = $get('quoted_on');

            if (filled($quotedOn) && $rows->contains(fn ($row) => filled($row['due_on'] ?? null) && Carbon::parse($row['due_on'])->lt(Carbon::parse($quotedOn)))) {
                $fail(__('No instalment can be due before the quote date (:date).', [
                    'date' => Carbon::parse($quotedOn)->format(Language::resolve(app()->getLocale())->dateFormat()),
                ]));
            }
        };
    }
}
