<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Filament\Support\MoneyInput;
use App\Models\Article;
use App\Support\CurrentAccount;
use App\Support\FiscalDocumentRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('code')
                    ->label(__('Code'))
                    ->required()
                    ->maxLength(32)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('account_id', CurrentAccount::id()))
                    ->validationMessages(['unique' => __('You already have an article with this code.')]),
                Select::make('unit')
                    ->label(__('Unit'))
                    ->options(fn (?Article $record): array => static::unitOptions($record?->unit))
                    ->default('un')
                    ->selectablePlaceholder(false)
                    ->required(),
                MoneyInput::make('unit_price')
                    ->label(__('Usual price'))
                    ->required()
                    ->minValue(0),
                Select::make('vat_basis_points')
                    ->label(__('VAT'))
                    ->options(fn (?Article $record): array => static::vatOptions($record?->vat_basis_points))
                    ->placeholder(__('The document decides'))
                    // An empty choice must stay empty: cast to a number it would read as "exempt".
                    ->dehydrateStateUsing(fn (mixed $state): ?int => filled($state) ? (int) $state : null),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->helperText(__('An inactive article is no longer suggested on new documents.'))
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The usual units, by the code stored on the article.
     *
     * @return array<string, string>
     */
    public static function units(): array
    {
        return [
            'un' => __('Unit'),
            'h' => __('Hour'),
            'dia' => __('Day'),
            'mês' => __('Month'),
            'cx' => __('Box'),
            'kg' => __('Kilo'),
        ];
    }

    public static function unitLabel(string $unit): string
    {
        return static::units()[$unit] ?? $unit;
    }

    /** "Isento" for no VAT, otherwise the rate as a percentage ("23%", "6,5%"). */
    public static function vatLabel(int $basisPoints): string
    {
        if ($basisPoints === 0) {
            return __('VAT exempt');
        }

        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',').'%';
    }

    /**
     * The usual units, plus the article's own when it came from somewhere with another one.
     *
     * @return array<string, string>
     */
    private static function unitOptions(?string $current): array
    {
        $units = static::units();

        return blank($current) || isset($units[$current]) ? $units : [...$units, $current => $current];
    }

    /**
     * The rates a document line may carry, plus the article's own when the account has since
     * moved to a region with other rates.
     *
     * @return array<int, string>
     */
    private static function vatOptions(?int $current): array
    {
        $rates = FiscalDocumentRules::vatRates();

        if ($current !== null && ! in_array($current, $rates, true)) {
            $rates[] = $current;
        }

        return collect($rates)->mapWithKeys(fn (int $rate): array => [$rate => static::vatLabel($rate)])->all();
    }
}
