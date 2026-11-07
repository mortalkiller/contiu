<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Enums\Frequency;
use App\Filament\Support\FinanceFields;
use App\Support\FinanceSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RetainerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Avença')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Código')
                            ->placeholder('MINFRA')
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->default(true)
                            ->inline(false),
                        Toggle::make('tax_exempt')
                            ->label('Isenta de impostos')
                            ->helperText('Os recebimentos desta avença não contam para a estimativa de impostos.')
                            ->default(fn () => app(FinanceSettings::class)->paymentsExemptByDefault())
                            ->inline(false),
                        FinanceFields::clientSelect(),
                        TextInput::make('business_line')
                            ->label('Linha de negócio')
                            ->datalist(['Printee', 'Pressiu']),
                        TextInput::make('title')
                            ->label('Título')
                            ->placeholder('Infraestrutura do site')
                            ->columnSpanFull()
                            ->required(),
                        Select::make('category_id')
                            ->label('Categoria')
                            ->relationship('category', 'name', fn (Builder $query) => $query->income())
                            ->native(false),
                    ]),

                Section::make('Valor por período')
                    ->columnSpan(1)
                    ->schema([
                        FinanceFields::netAmount(),
                        FinanceFields::vatRate(),
                        FinanceFields::totalPreview(),
                    ]),

                Section::make('Periodicidade')
                    ->description('A app vai criar um valor a receber por período. (Mock: a geração automática fica para a implementação.)')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        ToggleButtons::make('frequency')
                            ->label('Frequência')
                            ->options(Frequency::class)
                            ->default(Frequency::Monthly)
                            ->inline()
                            ->grouped()
                            ->required(),
                        TextInput::make('due_day')
                            ->label('Dia de vencimento')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(28)
                            ->default(1)
                            ->required(),
                        DatePicker::make('starts_on')
                            ->label('Início')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(today()->startOfMonth())
                            ->required(),
                        DatePicker::make('ends_on')
                            ->label('Fim')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->placeholder('Sem fim'),
                    ]),

                Section::make('Notas')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')->hiddenLabel()->rows(3),
                    ]),
            ]);
    }
}
