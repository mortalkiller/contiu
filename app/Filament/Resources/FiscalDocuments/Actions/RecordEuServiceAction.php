<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Support\InvoiceXpressEuService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class RecordEuServiceAction
{
    public static function make(): Action
    {
        return Action::make('recordEuService')
            ->label(__('Classify EU service'))
            ->authorize(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
            ->visible(fn (FiscalDocument $record): bool => $record->status === FiscalDocumentStatus::Active
                && InvoiceXpressEuService::isDocumentCandidate($record)
                && $record->fiscalOperationEvidence === null)
            ->modalDescription(__('Confirm the details on the issued invoice. If the client\'s recorded VIES check took place after the service, enter the date and reference of the original evidence.'))
            ->modalSubmitActionLabel(__('Record evidence'))
            ->fillForm(fn (FiscalDocument $record): array => ['operationOn' => $record->operation_date?->toDateString()])
            ->schema([
                Select::make('clientId')->label(__('Client with valid VIES verification'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search): array => self::clients()->whereAny(['name', 'vat_number'], 'like', '%'.$search.'%')
                        ->orderBy('name')->limit(25)->get()
                        ->mapWithKeys(fn (Client $client): array => [$client->id => self::clientLabel($client)])->all())
                    ->getOptionLabelUsing(fn (int|string $value): ?string => ($client = self::clients()->find($value)) === null
                        ? null : self::clientLabel($client)),
                DatePicker::make('operationOn')->label(__('Transaction date'))->maxDate(today())->required(),
                DatePicker::make('historicalViesOn')->label(__('VIES evidence date'))->maxDate(today())
                    ->requiredWith('historicalViesReference'),
                TextInput::make('historicalViesReference')->label(__('VIES evidence reference'))->maxLength(255)
                    ->requiredWith('historicalViesOn'),
                Checkbox::make('confirmed')->label(__('I confirmed on the invoice the date and the reverse charge (M40) for a general-rule service supplied to a business in another Member State.'))
                    ->rule('accepted'),
            ])
            ->action(function (FiscalDocument $record, array $data, Schema $schema): void {
                FiscalDocumentResource::authorizeEdit($record);

                try {
                    $client = self::clients()->find($data['clientId']) ?? throw ValidationException::withMessages([
                        'clientId' => __('Choose a client with valid VIES verification.'),
                    ]);
                    $historical = filled($data['historicalViesOn'] ?? null) ? [
                        'checkedOn' => $data['historicalViesOn'],
                        'reference' => trim($data['historicalViesReference']),
                    ] : null;
                    InvoiceXpressEuService::recordDocumentary($record, $client, $data['operationOn'], $historical);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), $schema->getStatePath().'.'));
                }

                $record->unsetRelation('fiscalOperationEvidence');
            })
            ->successNotificationTitle(__('Fiscal evidence recorded'));
    }

    /** @return Builder<Client> */
    private static function clients(): Builder
    {
        return Client::query()
            ->whereIn('fiscal_country_code', InvoiceXpressEuService::countryCodes())
            ->where('fiscal_party_type', FiscalProfilePartyType::Business)
            ->where('vat_validation_status', FiscalProfileViesStatus::Valid)
            ->whereNotNull('vat_validated_at');
    }

    private static function clientLabel(Client $client): string
    {
        return $client->name.' · '.$client->vat_number;
    }
}
