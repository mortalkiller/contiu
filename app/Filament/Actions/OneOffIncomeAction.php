<?php

namespace App\Filament\Actions;

use App\Actions\RecordOneOffIncome;
use App\Enums\CategoryType;
use App\Enums\PaymentMethod;
use App\Filament\Actions\Concerns\DisabledWhenReadOnly;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\QuickCreate;
use App\Models\Category;
use App\Models\Supplier;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Js;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Receita avulsa": a donation, refund or other income with no job or retainer behind it. Recorded at
 * the gross amount (the fatura-recibo), with the platform's commission as an expense.
 */
class OneOffIncomeAction extends Action
{
    use DisabledWhenReadOnly;

    public static function getDefaultName(): ?string
    {
        return 'oneOffIncome';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('One-off income'))
            ->icon(Phosphor::Gift)
            ->modalHeading(__('One-off income'))
            ->modalDescription(__('Donations, refunds or other income unrelated to a project or retainer. Record the gross amount from the invoice-receipt. The platform fee is recorded as an expense.'))
            ->modalIcon(Phosphor::Gift)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('Record'))
            ->fillForm(fn (): array => [
                'received_on' => today()->toDateString(),
                'method' => PaymentMethod::Transfer->value,
                'tax_exempt' => app(FinanceSettings::class)->paymentsExemptByDefault(),
            ])
            ->schema(static::formSchema())
            ->action(function (array $data): void {
                app(RecordOneOffIncome::class)->handle($data);

                Notification::make()->title(__('Income recorded'))->success()->send();
            });
    }

    /**
     * Shared with "Criar receita" on the bank statement, which leaves out the date (the line's).
     *
     * @return array<int, Component>
     */
    /** @param  int|null  $received  cents that reached the bank, when created from a statement line */
    public static function formSchema(bool $withDate = true, ?int $received = null): array
    {
        $hasCommission = fn (Get $get): bool => $received === null
            ? (float) $get('commission') > 0
            : Money::toCents($get('gross_amount')) > $received;

        return [
            Grid::make($withDate ? 2 : 1)->schema(array_values(array_filter([
                $withDate ? DatePicker::make('received_on')->label(__('Date'))->required() : null,
                QuickCreate::category(Select::make('category_id')
                    ->label(__('Category'))
                    ->options(fn () => Category::query()->where('type', CategoryType::Income)->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder(__('E.g. Donations')), CategoryType::Income),
            ]))),
            TextInput::make('title')
                ->label(__('Description'))
                ->placeholder(__('E.g. Buy Me a Coffee donation'))
                ->required(),
            Grid::make(2)->schema([
                MoneyInput::make('gross_amount')
                    ->label(__('Gross amount'))
                    ->helperText(__('The amount on the invoice-receipt.'))
                    ->minValue($received === null ? 0.01 : Money::toEuros($received))
                    ->afterStateUpdatedJs($received === null ? null : function () use ($received): string {
                        $asset = Js::from(Vite::asset('resources/js/finance-preview.js'));

                        return <<<JS
                            const input = \$el;
                            import({$asset}).then(({ moneyCents }) => {
                                if (!input.isConnected) return;

                                const cents = moneyCents(\$get('gross_amount')) - BigInt('{$received}');
                                const fee = cents > 0n ? cents : 0n;
                                \$set('commission', (fee / 100n) + '.' + String(fee % 100n).padStart(2, '0'));
                            });
                            JS;
                    })
                    ->afterStateUpdated(fn (Set $set, $state) => $received === null ? null : $set('commission', Money::toEuros(max(0, (int) Money::toCents($state) - $received))))
                    ->required(),
                MoneyInput::make('commission')
                    ->label(__('Platform fee'))
                    ->helperText($received === null
                        ? __('The amount the platform kept. It is recorded as an expense.')
                        : __('Calculated as the gross amount minus the bank deposit (:amount).', ['amount' => Money::format($received)]))
                    ->minValue(0)
                    ->readOnly($received !== null),
            ]),
            QuickCreate::supplier(Select::make('commission_supplier_id')
                ->label(__('Platform'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Supplier::query()
                    ->whereLike('name', '%'.$search.'%')->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value): ?string => Supplier::query()->whereKey($value)->value('name')))
                ->visibleJs("Number(\$get('commission')) > 0")
                ->dehydrated($hasCommission)
                ->required($hasCommission),
            ToggleButtons::make('method')
                ->label(__('Method'))
                ->options(PaymentMethod::class)
                ->inline()
                ->required(),
            Grid::make(2)->schema([
                TextInput::make('invoice_number')->label(__('Invoice-receipt number')),
                Toggle::make('tax_exempt')->label(__('Tax-exempt'))->inline(false),
            ]),
        ];
    }
}
