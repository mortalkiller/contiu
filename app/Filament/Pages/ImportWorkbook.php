<?php

namespace App\Filament\Pages;

use App\Actions\ImportFinanceWorkbook;
use App\Actions\ImportRefusedException;
use App\Actions\ImportResult;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * Upload the "controlo financeiro" workbook, preview what it would create (dry run), then import.
 */
class ImportWorkbook extends Page
{
    protected string $view = 'filament.pages.import-workbook';

    protected static ?string $title = 'Importar Excel';

    protected static ?string $slug = 'importar-excel';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::FileArrowUp;

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Result of the last dry run, shown before importing. */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->form->fill(['replace' => false]);
    }

    public function getSubheading(): ?string
    {
        return 'Carrega o ficheiro de controlo financeiro (folhas Receitas, Pagamentos, Despesas e Listas). Primeiro vês o que vai ser criado; só depois é gravado.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        FileUpload::make('file')
                            ->label('Ficheiro Excel')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'application/octet-stream',
                            ])
                            ->maxSize(10240)
                            ->required()
                            ->afterStateUpdated(fn () => $this->preview = null),
                        Checkbox::make('replace')
                            ->label('Substituir os dados atuais')
                            ->helperText('Apaga todos os clientes, trabalhos, avenças, recebimentos, despesas e fornecedores antes de importar. Não pode ser desfeito.')
                            ->live()
                            ->afterStateUpdated(fn () => $this->preview = null),
                        Actions::make([
                            Action::make('preview')
                                ->label('Pré-visualizar')
                                ->icon(Phosphor::Eye)
                                ->color('gray')
                                ->action(fn () => $this->preview()),
                            Action::make('import')
                                ->label('Importar')
                                ->icon(Phosphor::FileArrowUp)
                                ->visible(fn () => $this->preview !== null)
                                ->requiresConfirmation(fn () => (bool) ($this->data['replace'] ?? false))
                                ->modalHeading('Substituir os dados atuais?')
                                ->modalDescription('Os dados financeiros atuais vão ser apagados e substituídos pelos do ficheiro.')
                                ->color(fn () => ($this->data['replace'] ?? false) ? 'danger' : 'primary')
                                ->action(fn () => $this->import()),
                        ]),
                    ]),
            ]);
    }

    public function preview(): void
    {
        $result = $this->runImport(dryRun: true);

        $this->preview = $result?->toArray();
    }

    public function import(): void
    {
        $result = $this->runImport(dryRun: false);

        if ($result === null) {
            return;
        }

        Storage::disk('local')->delete($this->storedPath());

        Notification::make()
            ->title('Importação concluída')
            ->body(sprintf(
                '%d trabalhos, %d avenças, %d recebimentos e %d despesas. Contratado %s, recebido %s.',
                $result->counts['projects'],
                $result->counts['retainers'],
                $result->counts['payments'],
                $result->counts['expenses'],
                Money::format($result->contracted),
                Money::format($result->received),
            ))
            ->success()
            ->send();

        $this->preview = null;
        $this->form->fill(['replace' => false]);
    }

    /** Labels and values for the preview table. */
    public function previewRows(): array
    {
        return collect(ImportResult::LABELS)
            ->map(fn (string $label, string $key) => ['label' => $label, 'value' => $this->preview['counts'][$key] ?? 0])
            ->values()
            ->all();
    }

    private function runImport(bool $dryRun): ?ImportResult
    {
        $data = $this->form->getState();

        try {
            return app(ImportFinanceWorkbook::class)->run(
                Storage::disk('local')->path($data['file']),
                replace: (bool) ($data['replace'] ?? false),
                dryRun: $dryRun,
            );
        } catch (ImportRefusedException $exception) {
            $this->preview = null;

            Notification::make()
                ->title('Não foi possível importar')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    private function storedPath(): string
    {
        $file = $this->data['file'] ?? null;

        return is_array($file) ? (string) collect($file)->first() : (string) $file;
    }
}
