<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\FiscalProfileViesStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Support\CurrentAccount;
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
            $this->viesVerificationAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
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
            ->label('Registar verificação VIES')
            ->visible(fn (): bool => ! CurrentAccount::readOnly() && $this->getRecord()->viesIdentity() !== null)
            ->mountUsing(function (Schema $schema): void {
                $this->viesIdentity = $this->getRecord()->viesIdentity();
                $schema->fill();
            })
            ->modalDescription(fn (): string => $this->viesIdentity
                ? "Consulta no VIES o número {$this->viesIdentity['vat']} ({$this->viesIdentity['country']}) e regista o resultado."
                : 'Guarda primeiro os dados fiscais do cliente.')
            ->schema([
                Actions::make([
                    Action::make('openVies')->label('Consultar VIES oficial')
                        ->url('https://ec.europa.eu/taxation_customs/vies/#/vat-validation', shouldOpenInNewTab: true),
                ]),
                Select::make('result')->label('Resultado da consulta')->required()
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
                            $schema->getStatePath().'.result' => 'Os dados fiscais mudaram. Fecha esta janela e volta a abrir a verificação.',
                        ]);
                    }

                    $client->forceFill([
                        'vat_validation_status' => FiscalProfileViesStatus::from($data['result']),
                        'vat_validated_at' => now(),
                    ])->save();
                });

                $this->getRecord()->refresh();
                $this->viesIdentity = null;

                Notification::make()->title('Resultado da verificação registado')->success()->send();
            });
    }
}
