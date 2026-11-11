<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Enums\CategoryType;
use App\Enums\Frequency;
use App\Enums\Language;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\QuickCreate;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;

/**
 * As in the mock ("Nova avença"), on both the create and the edit page: the retainer, its amount
 * per period, how often it is billed and notes, one card each, with a summary beside them that
 * follows what is typed.
 */
class RetainerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    Section::make(__('Retainer'))
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            TextInput::make('code')
                                ->label(__('Code'))
                                ->placeholder('MINFRA')
                                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('account_id', CurrentAccount::id())) // codes repeat across accounts
                                ->required(),
                            FinanceFields::clientSelect(),
                            TextInput::make('title')
                                ->label(__('Title'))
                                ->placeholder(__('Website infrastructure'))
                                ->columnSpanFull()
                                ->required(),
                            TextInput::make('business_line')
                                ->label(__('Business line'))
                                ->datalist(fn () => FinanceFields::businessLines()),
                            QuickCreate::category(Select::make('category_id')
                                ->label(__('Category'))
                                ->relationship('category', 'name', fn (Builder $query) => $query->income())
                                ->native(false), CategoryType::Income),
                            Toggle::make('is_active')
                                ->label(__('Active retainer'))
                                ->default(true),
                            Toggle::make('tax_exempt')
                                ->label(__('Tax-exempt retainer'))
                                ->helperText(__('Payments for this retainer are excluded from tax estimates.'))
                                ->default(fn () => app(FinanceSettings::class)->paymentsExemptByDefault()),
                        ]),

                    Section::make(__('Amount per period'))
                        ->columns(['default' => 1, 'sm' => 3])
                        ->schema(FinanceFields::amountFields()),

                    Section::make(__('Schedule'))
                        ->columns(['default' => 1, 'sm' => 4])
                        ->schema([
                            ToggleButtons::make('frequency')
                                ->label(__('Billing frequency'))
                                ->options(Frequency::class)
                                ->default(Frequency::Monthly)
                                ->inline()
                                ->grouped()
                                ->required()
                                ->columnSpan(['default' => 1, 'sm' => 2]),
                            TextInput::make('due_day')
                                ->label(__('Due day'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(28)
                                ->default(1)
                                ->required(),
                            DatePicker::make('starts_on')
                                ->label(__('Start'))
                                ->native(false)
                                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                                ->default(today()->startOfMonth())
                                ->required()
                                ->columnStart(1)
                                ->columnSpan(['default' => 1, 'sm' => 2]),
                            DatePicker::make('ends_on')
                                ->label(__('End'))
                                ->native(false)
                                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                                ->placeholder(__('No end date'))
                                ->columnSpan(['default' => 1, 'sm' => 2]),
                        ]),

                    Section::make(__('Notes'))
                        ->schema([
                            Textarea::make('notes')->hiddenLabel()->rows(3)->placeholder(__('Only for you.')),
                        ]),
                ])->columnSpan(['default' => 1, 'lg' => 2]),

                static::summarySection(),
            ]);
    }

    /**
     * What the retainer comes to per month and per year with VAT, when it is first billed, and
     * whether it is active and how often it bills, worked out in the browser (finance-preview.js).
     */
    private static function summarySection(): Section
    {
        $frequency = fn (Get $get): ?Frequency => $get('frequency') instanceof Frequency ? $get('frequency') : Frequency::tryFrom((string) $get('frequency'));
        $total = fn (Get $get): int => FinanceFields::totalCents($get('net_amount'), $get('vat_rate'));
        $frequencies = collect(Frequency::cases())->mapWithKeys(fn (Frequency $frequency): array => [$frequency->value => $frequency->getLabel()])->all();
        $activity = ['true' => __('Active retainer'), 'false' => __('Inactive retainer')];

        return FinanceFields::summary([
            TextEntry::make('summary_per_month')
                ->label(__('Per month, with VAT'))
                ->state(fn (Get $get): HtmlString => FinanceFields::preview('perMonth', $frequency($get)?->perMonth($total($get)) ?? '—')),
            TextEntry::make('summary_per_year')
                ->label(__('Per year'))
                ->state(fn (Get $get): HtmlString => FinanceFields::preview('perYear', $frequency($get)?->perYear($total($get)) ?? '—')),
            TextEntry::make('summary_first_due')
                ->label(__('First charge'))
                ->state(fn (): HtmlString => FinanceFields::preview('firstDue', '—')),
            Flex::make([
                TextEntry::make('summary_active')
                    ->hiddenLabel()
                    ->grow(false)
                    ->state(fn (Get $get): HtmlString => FinanceFields::preview('choice', $activity[$get('is_active') ? 'true' : 'false'], $activity, 'is_active')),
                TextEntry::make('summary_frequency')
                    ->hiddenLabel()
                    ->grow(false)
                    ->state(fn (Get $get): HtmlString => FinanceFields::preview('choice', $frequency($get)?->getLabel() ?? '', $frequencies, 'frequency')),
            ])->inlineLabel(false),
        ]);
    }
}
