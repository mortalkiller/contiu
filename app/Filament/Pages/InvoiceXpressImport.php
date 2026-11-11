<?php

namespace App\Filament\Pages;

use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\InvoiceXpressConfig;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressClient;
use App\Support\InvoiceXpressImport as Importer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class InvoiceXpressImport extends InvoiceXpressSectionPage
{
    protected string $view = 'filament.pages.invoice-xpress-import';

    protected static ?string $slug = 'importar-invoicexpress';

    public string $from;

    public string $to;

    public bool $archived = false;

    /** @var array{account: int, subdomain: string, page: int, totalPages: int}|null */
    #[Locked]
    public ?array $previewScope = null;

    #[Locked]
    public array $rows = [];

    #[Locked]
    public int $omitted = 0;

    public static function canAccess(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
    }

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
    }

    public function getSubheading(): ?string
    {
        return __('Import issued documents and link them to charges in Contiu.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fiscalDocuments')
                ->label(__('Tax documents'))
                ->link()
                ->color('gray')
                ->url(fn (): string => FiscalDocumentResource::getUrl())
                ->visible(fn (): bool => FiscalDocumentResource::canAccess()),
        ];
    }

    protected function getViewData(): array
    {
        return ['summary' => $this->previewScope === null ? [] : $this->summary()];
    }

    /**
     * The page of documents being reviewed, counted by what there is to do with each.
     *
     * @return list<array{label: string, value: string, note?: ?string, tone?: ?string}>
     */
    private function summary(): array
    {
        $states = array_count_values(array_column($this->rows, 'state'));
        $changed = $states['changed'] ?? 0;
        $conflicts = $states['conflict'] ?? 0;

        return [
            ['label' => __('Documents found'), 'value' => (string) count($this->rows), 'note' => $this->omitted > 0
                ? trans_choice('{1} 1 omitted on this page|[2,*] :count omitted on this page', $this->omitted)
                : __('Page :page of :total', ['page' => $this->previewScope['page'], 'total' => $this->previewScope['totalPages']])],
            ['label' => __('Already recorded'), 'value' => (string) ($states['same'] ?? 0), 'note' => __('No differences')],
            ['label' => __('Awaiting import'), 'value' => (string) (($states['new'] ?? 0) + $changed), 'note' => $changed > 0
                ? trans_choice('{1} 1 correction to apply|[2,*] :count corrections to apply', $changed)
                : null, 'tone' => 'warning'],
            ['label' => __('Conflicting'), 'value' => (string) $conflicts, 'note' => $conflicts > 0 ? __('Differ from the local record') : __('None'), 'tone' => $conflicts > 0 ? 'warning' : 'success'],
        ];
    }

    #[Computed]
    public function configuredSubdomain(): ?string
    {
        return InvoiceXpressConfig::current()?->subdomain;
    }

    public function configureAction(): Action
    {
        return InvoiceXpressSettingsPage::connectionForm(
            $this->canWrite(...),
            function (): void {
                unset($this->configuredSubdomain);
                $this->clearPreview();
            },
        );
    }

    public function updatedFrom(): void
    {
        $this->clearPreview();
    }

    public function updatedTo(): void
    {
        $this->clearPreview();
    }

    public function updatedArchived(): void
    {
        $this->clearPreview();
    }

    public function preview(): void
    {
        $this->loadPage(1);
    }

    public function movePage(int $direction): void
    {
        abort_unless(static::canAccess(), 403);
        $scope = $this->previewScope;

        if ($scope === null || ! in_array($direction, [-1, 1], true)) {
            return;
        }

        $page = $scope['page'] + $direction;

        if ($page >= 1 && $page <= min($scope['totalPages'], InvoiceXpressClient::MAX_PAGES)) {
            $this->loadPage($page);
        }
    }

    public function importDocumentAction(): Action
    {
        return Action::make('importDocument')
            ->label(fn (array $arguments): string => ($this->selectedRow($arguments)['state'] ?? null) === 'changed' ? __('Apply correction') : __('Import'))
            ->visible(fn (array $arguments): bool => $this->canWrite() && in_array($this->selectedRow($arguments)['state'] ?? null, ['new', 'changed'], true))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => ($this->selectedRow($arguments)['state'] ?? null) === 'changed'
                ? __('Apply correction to the tax document')
                : __('Import tax document'))
            ->modalDescription(__('Confirm the version shown to save the document in Contiu.'))
            ->modalSubmitActionLabel(__('Confirm record'))
            ->schema(fn (array $arguments): array => empty($suggestions = $this->selectedRow($arguments)['suggestions'] ?? []) ? [] : [
                Select::make('receivable_id')
                    ->label(__('Link to charge'))
                    ->placeholder(__('Import without linking'))
                    ->options($suggestions)
                    ->helperText(__('Verify the charge using its tax ID, amount and date.')),
            ])
            ->action(function (array $arguments, array $data, Importer $import, Action $action): void {
                abort_unless($this->canWrite(), 403);
                abort_unless($this->previewScope !== null && $this->previewScope['account'] === CurrentAccount::id(), 403);
                $row = $this->selectedRow($arguments);
                abort_unless(in_array($row['state'] ?? null, ['new', 'changed'], true), 404);

                try {
                    $receivableId = filled($data['receivable_id'] ?? null) ? (int) $data['receivable_id'] : null;
                    $import->confirmInvoice($row['invoice'], $row['current_hash'], $this->previewScope['subdomain'], $row['local_hash'], $receivableId);
                } catch (InvoiceXpressException|ValidationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->cancel();
                }

                $this->rows[$arguments['index']]['state'] = 'same';
                Notification::make()->success()->title(__('Tax document recorded'))->send();
            });
    }

    private function loadPage(int $page): void
    {
        abort_unless(static::canAccess(), 403);
        $this->clearPreview();
        $accountId = CurrentAccount::id();
        $client = app(InvoiceXpressClient::class);
        $import = app(Importer::class);

        try {
            $result = $client->listInvoices($this->from, $this->to, $page, $this->archived);
            if ($result['subdomain'] !== $client->configuration()->subdomain) {
                throw new InvoiceXpressException(__('The InvoiceXpress account changed. Fetch the documents again.'));
            }

            foreach ($result['invoices'] as $invoice) {
                if (! is_array($invoice)) {
                    $this->omitted++;

                    continue;
                }

                try {
                    $row = $import->reviewInvoice($invoice, $result['subdomain']);
                    $date = $row['after']['document_date'];

                    if ($date < $this->from || $date > $this->to) {
                        $this->omitted++;

                        continue;
                    }

                    $row['review_key'] = hash('sha256', implode('|', [
                        $accountId, $result['subdomain'], $row['invoice']['id'],
                        $row['source_hash'], $row['current_hash'], $row['local_hash'],
                    ]));
                    $this->rows[] = $row;
                } catch (ValidationException) {
                    $this->omitted++;
                }
            }
        } catch (InvoiceXpressException $exception) {
            $this->clearPreview();
            $this->addError('provider', $exception->getMessage());

            return;
        }

        $this->previewScope = [
            'account' => $accountId,
            'subdomain' => $result['subdomain'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ];
    }

    private function clearPreview(): void
    {
        $this->reset('previewScope', 'rows', 'omitted');
        $this->resetValidation();
    }

    private function selectedRow(array $arguments): ?array
    {
        $index = $arguments['index'] ?? null;

        $row = is_int($index) ? ($this->rows[$index] ?? null) : null;

        return $row !== null && $row['review_key'] === ($arguments['review'] ?? null) ? $row : null;
    }

    private function canWrite(): bool
    {
        return static::canAccess() && ! CurrentAccount::readOnly();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Import from InvoiceXpress');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Import from InvoiceXpress');
    }
}
