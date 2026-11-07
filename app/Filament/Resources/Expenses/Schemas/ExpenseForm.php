<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\ReconciliationColumns;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Despesa')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        FinanceFields::supplierSelect(),
                        Select::make('category_id')
                            ->label('Categoria')
                            ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                            ->native(false),
                        TextInput::make('description')
                            ->label('Descrição')
                            ->placeholder('ChatGPT Pro, servidor OVH…')
                            ->required(),
                        DatePicker::make('spent_on')
                            ->label('Data')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(today())
                            ->required(),
                    ]),

                Section::make('Valor')
                    ->columnSpan(1)
                    ->schema([
                        FinanceFields::netAmount(),
                        FinanceFields::vatRate(),
                        FinanceFields::totalPreview(),
                    ]),

                ...static::classificationAndPayment(),
            ]);
    }

    /**
     * Shared with the recurring expense form.
     *
     * @return array<int, Section>
     */
    public static function classificationAndPayment(bool $withPaymentStatus = true): array
    {
        return [
            Section::make('Classificação')
                ->columnSpan(2)
                ->columns(2)
                ->schema([
                    Radio::make('kind')
                        ->label('Tipo de custo')
                        ->options(ExpenseKind::class)
                        ->default(ExpenseKind::Overhead)
                        ->columnSpanFull()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            if (static::kind($state) !== ExpenseKind::Direct) {
                                $set('project_id', null);
                                $set('retainer_id', null);
                            }
                        }),
                    Select::make('project_id')
                        ->label('Trabalho')
                        ->relationship('project', 'code')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct)
                        ->disabled(fn (Get $get) => filled($get('retainer_id')))
                        ->required(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct && blank($get('retainer_id')))
                        ->helperText('Escolhe o trabalho ou a avença a que este custo pertence — só um dos dois.'),
                    Select::make('retainer_id')
                        ->label('Avença')
                        ->relationship('retainer', 'code')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct)
                        ->disabled(fn (Get $get) => filled($get('project_id')))
                        ->required(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct && blank($get('project_id'))),
                ]),

            Section::make('Pagamento')
                ->columnSpan(1)
                ->schema(array_values(array_filter([
                    $withPaymentStatus ? Toggle::make('is_paid')
                        ->label('Já está paga')
                        ->default(true)
                        ->live() : null,
                    $withPaymentStatus ? DatePicker::make('paid_on')
                        ->label('Paga em')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(today())
                        ->visible(fn (Get $get) => (bool) $get('is_paid')) : null,
                    ToggleButtons::make('method')
                        ->label('Método')
                        ->options(PaymentMethod::class)
                        ->inline(),
                    $withPaymentStatus ? TextInput::make('invoice_number')
                        ->label('N.º da fatura do fornecedor')
                        ->maxLength(100) : null,
                    $withPaymentStatus ? Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2) : null,
                ]))),
            ...($withPaymentStatus ? [ReconciliationColumns::section()] : []),
        ];
    }

    public static function kind(mixed $state): ?ExpenseKind
    {
        return $state instanceof ExpenseKind ? $state : ExpenseKind::tryFrom((string) $state);
    }

    /**
     * Overhead never keeps a cost centre, even if a hidden field still holds one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (static::kind($data['kind'] ?? null) !== ExpenseKind::Direct) {
            $data['project_id'] = null;
            $data['retainer_id'] = null;
        }

        if (array_key_exists('is_paid', $data) && ! $data['is_paid']) {
            $data['paid_on'] = null;
        }

        return $data;
    }
}
