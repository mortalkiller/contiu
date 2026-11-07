<?php

namespace App\Filament\Actions;

use App\Actions\RecordOneOffIncome;
use App\Enums\CategoryType;
use App\Enums\PaymentMethod;
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
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Receita avulsa": a donation, refund or other income with no job or retainer behind it. Recorded at
 * the gross amount (the fatura-recibo), with the platform's commission as an expense.
 */
class OneOffIncomeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'oneOffIncome';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Receita avulsa')
            ->icon(Phosphor::Gift)
            ->modalHeading('Receita avulsa')
            ->modalDescription('Donativos, reembolsos ou outras entradas sem trabalho nem avença. Regista o valor bruto (o da fatura-recibo); a comissão da plataforma fica como despesa.')
            ->modalIcon(Phosphor::Gift)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Registar')
            ->fillForm(fn (): array => [
                'received_on' => today()->toDateString(),
                'method' => PaymentMethod::Transfer->value,
                'tax_exempt' => app(FinanceSettings::class)->paymentsExemptByDefault(),
            ])
            ->schema(static::formSchema())
            ->action(function (array $data): void {
                app(RecordOneOffIncome::class)->handle($data);

                Notification::make()->title('Receita registada')->success()->send();
            });
    }

    /**
     * Shared with "Criar receita" on the bank statement, which leaves out the date (the line's).
     *
     * @return array<int, Component>
     */
    public static function formSchema(bool $withDate = true, ?float $received = null): array
    {
        return [
            Grid::make($withDate ? 2 : 1)->schema(array_values(array_filter([
                $withDate ? DatePicker::make('received_on')->label('Data')->required() : null,
                Select::make('category_id')
                    ->label('Categoria')
                    ->options(fn () => Category::query()->where('type', CategoryType::Income)->orderBy('name')->pluck('name', 'id')->all())
                    ->createOptionForm([TextInput::make('name')->label('Nome')->required()])
                    ->createOptionUsing(fn (array $data) => Category::create([...$data, 'type' => CategoryType::Income])->getKey())
                    ->placeholder('Ex.: Donativos'),
            ]))),
            TextInput::make('title')
                ->label('Descrição')
                ->placeholder('Ex.: Donativo Buy Me a Coffee')
                ->required(),
            Grid::make(2)->schema([
                TextInput::make('gross_amount')
                    ->label('Valor bruto')
                    ->helperText('O valor da fatura-recibo.')
                    ->numeric()
                    ->minValue($received ?? 0.01)
                    ->suffix('€')
                    ->live(onBlur: true)
                    // From a bank line the commission is what didn't arrive, so the income always matches.
                    ->afterStateUpdated(fn (Set $set, $state) => $received === null ? null : $set('commission', round(max(0, (float) $state - $received), 2)))
                    ->required(),
                TextInput::make('commission')
                    ->label('Comissão da plataforma')
                    ->helperText($received === null ? 'O que a plataforma ficou. Fica como despesa.' : 'Calculada: valor bruto menos o que chegou ao banco ('.Money::format($received).').')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('€')
                    ->readOnly($received !== null)
                    ->live(onBlur: true),
            ]),
            Select::make('commission_supplier_id')
                ->label('Plataforma')
                ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->createOptionForm([TextInput::make('name')->label('Nome')->required()])
                ->createOptionUsing(fn (array $data) => Supplier::create($data)->getKey())
                ->visible(fn (Get $get): bool => (float) $get('commission') > 0)
                ->required(fn (Get $get): bool => (float) $get('commission') > 0),
            ToggleButtons::make('method')
                ->label('Método')
                ->options(PaymentMethod::class)
                ->inline()
                ->required(),
            Grid::make(2)->schema([
                TextInput::make('invoice_number')->label('N.º da fatura-recibo'),
                Toggle::make('tax_exempt')->label('Isenta de impostos')->inline(false),
            ]),
        ];
    }
}
