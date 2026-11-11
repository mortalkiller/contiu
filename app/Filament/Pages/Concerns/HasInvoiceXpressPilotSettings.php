<?php

namespace App\Filament\Pages\Concerns;

use App\Exceptions\InvoiceXpressException;
use App\Models\InvoiceXpressConfig;
use App\Support\InvoiceXpressCatalog;
use App\Support\InvoiceXpressIssueSettings;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasInvoiceXpressPilotSettings
{
    #[Locked]
    public ?array $catalog = null;

    public array $approvedItems = [];

    public string $taxName = '';

    public string $sequenceId = '';

    public function loadCatalog(InvoiceXpressCatalog $catalog): void
    {
        $this->authorizeAccount();
        $this->reset('catalog');
        $this->beforeInvoiceXpressCatalogLoad();
        $this->resetValidation();

        try {
            $this->catalog = $catalog->choices();
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['catalog' => $exception->getMessage()]);
        }
    }

    public function saveSettings(InvoiceXpressIssueSettings $settings): void
    {
        $this->authorizeAccount();
        $this->resetValidation();
        $this->validate(['approvedItems' => ['array', 'max:25'], 'approvedItems.*' => ['integer', 'min:1']]);
        $draftOnly = ($this->catalog['account'] ?? null) === InvoiceXpressConfig::current()?->subdomain
            && isset($this->catalog['draftSequences'][$this->sequenceId]);
        $settings->save(implode(',', $this->approvedItems), $this->taxName, $this->sequenceId, draftOnly: $draftOnly);
        $this->afterInvoiceXpressPilotSettingsSave();

        Notification::make()->title(__('Issuing settings saved'))->success()->send();
    }

    protected function fillInvoiceXpressPilotSettings(): void
    {
        $settings = InvoiceXpressIssueSettings::stored();
        if (($settings['subdomain'] ?? null) !== InvoiceXpressConfig::current()?->subdomain) {
            $settings = [];
        }

        $this->approvedItems = array_map('strval', $settings['item_ids'] ?? []);
        $this->taxName = $settings['tax_name'] ?? '';
        $this->sequenceId = (string) ($settings['sequence_id'] ?? '');
    }

    protected function beforeInvoiceXpressCatalogLoad(): void {}

    protected function afterInvoiceXpressPilotSettingsSave(): void {}
}
