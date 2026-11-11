<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\FiscalProfileViesStatus;
use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressContactSync;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

class EditClient extends EditRecord
{
    use HasPageHeader;

    protected static string $resource = ClientResource::class;

    /** @var array{country: string, party: string, vat: string}|null */
    #[Locked]
    public ?array $viesIdentity = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->invoiceXpressSyncAction(),
            $this->viesVerificationAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    private function invoiceXpressSyncAction(): Action
    {
        return Action::make('syncInvoiceXpressClient')
            ->label(__('Sync with InvoiceXpress'))
            ->visible(fn (): bool => InvoiceXpressContactSync::available() && filled($this->getRecord()->vat_number))
            ->modalDescription(__('Fields filled in on the source record override the corresponding fields on the destination record with the same tax ID.'))
            ->modalSubmitActionLabel(__('Sync client'))
            ->schema([
                Select::make('direction')->label(__('Sync direction'))->required()
                    ->options(['pull' => __('InvoiceXpress → Contiu'), 'push' => __('Contiu → InvoiceXpress')])
                    ->in(['pull', 'push']),
            ])
            ->action(function (array $data, InvoiceXpressContactSync $sync, Action $action): void {
                $client = $this->getRecord();
                ClientResource::authorizeEdit($client);

                try {
                    if ($data['direction'] === 'pull') {
                        $sync->pull((string) $client->vat_number, expectedClientId: $client->id);
                        $this->refreshFormData(['name', 'email', 'phone', 'address', 'language']);
                    } else {
                        $sync->push($client->id);
                    }
                } catch (InvoiceXpressException|ValidationException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('Client synced'))->send();
            });
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $fresh = Client::query()->lockForUpdate()->findOrFail($record->getKey());
            $record->setRawAttributes($fresh->getAttributes(), sync: true);

            return parent::handleRecordUpdate($record, $data);
        });
    }

    private function viesVerificationAction(): Action
    {
        return Action::make('recordViesVerification')
            ->label(__('Record VIES check'))
            ->visible(fn (): bool => ! CurrentAccount::readOnly() && $this->getRecord()->viesIdentity() !== null)
            ->mountUsing(function (Schema $schema): void {
                $this->viesIdentity = $this->getRecord()->viesIdentity();
                $schema->fill();
            })
            ->modalDescription(fn (): string => $this->viesIdentity
                ? __('Check number :vat (:country) in VIES and record the result.', [
                    'vat' => $this->viesIdentity['vat'],
                    'country' => $this->viesIdentity['country'],
                ])
                : __('Save the client’s tax details first.'))
            ->schema([
                Actions::make([
                    Action::make('openVies')->label(__('Open the official VIES website'))
                        ->url('https://ec.europa.eu/taxation_customs/vies/#/vat-validation', shouldOpenInNewTab: true),
                ]),
                Select::make('result')->label(__('Check result'))->required()
                    ->options([
                        FiscalProfileViesStatus::Valid->value => FiscalProfileViesStatus::Valid->getLabel(),
                        FiscalProfileViesStatus::Invalid->value => FiscalProfileViesStatus::Invalid->getLabel(),
                        FiscalProfileViesStatus::Unavailable->value => FiscalProfileViesStatus::Unavailable->getLabel(),
                    ]),
            ])
            ->action(function (array $data, Schema $schema): void {
                DB::transaction(function () use ($data, $schema): void {
                    $client = Client::query()->lockForUpdate()->findOrFail($this->getRecord()->getKey());
                    ClientResource::authorizeEdit($client);

                    if ($this->viesIdentity === null || $client->viesIdentity() !== $this->viesIdentity) {
                        throw ValidationException::withMessages([
                            $schema->getStatePath().'.result' => __('The tax details have changed. Close this window and reopen the check.'),
                        ]);
                    }

                    $client->forceFill([
                        'vat_validation_status' => FiscalProfileViesStatus::from($data['result']),
                        'vat_validated_at' => now(),
                    ])->save();
                });

                $this->getRecord()->refresh();
                $this->viesIdentity = null;

                Notification::make()->title(__('Check result recorded'))->success()->send();
            });
    }
}
