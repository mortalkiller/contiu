<?php

namespace App\Support;

use App\Enums\PortugueseVatRegion;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocumentDraft;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressDraftDelivery
{
    private const string Provider = 'invoicexpress';

    public function __construct(
        private readonly InvoiceXpressClient $client,
        private readonly InvoiceXpressIssue $invoices,
        private readonly InvoiceXpressNoteIssue $notes,
        private readonly InvoiceXpressSandboxDrafts $sandbox,
    ) {}

    public function saveInvoice(array $inputs, string $revision, ?int $draftId = null, ?string $requestKey = null): FiscalDocumentDraft
    {
        return $this->save($inputs, $revision, $draftId, $requestKey, note: false);
    }

    public function saveNote(array $inputs, string $revision, ?int $draftId = null, ?string $requestKey = null): FiscalDocumentDraft
    {
        return $this->save($inputs, $revision, $draftId, $requestKey, note: true);
    }

    public function send(int $draftId): FiscalDocumentDraft
    {
        $reserved = $this->locked(function () use ($draftId): FiscalDocumentDraft {
            $draft = $this->find($draftId);
            $this->authorizeSandbox($draft);
            if ($draft->state === FiscalDocumentDraft::Remote && $draft->remote_id !== null) {
                return $draft;
            }

            if ($draft->state === FiscalDocumentDraft::Local && $draft->remote_id === null && $draft->fiscal_document_id === null) {
                $this->validatedPreview(
                    $draft->payload['inputs'], $draft->revision, isset(InvoiceXpressNoteTerms::Types[$draft->document_type]),
                );
                $document = $this->providerDocument($draft);
                $draft->update([
                    'state' => FiscalDocumentDraft::Sending,
                    'payload' => [
                        ...$draft->payload,
                        'reference' => $document['reference'],
                        'requestHash' => $this->requestHash($draft, $document),
                    ],
                ]);
            }

            return $draft;
        });

        if ($reserved->state === FiscalDocumentDraft::Remote && $reserved->remote_id !== null) {
            return $reserved;
        }

        // The reservation must survive a timeout or a failed read-back after the POST.
        return $this->locked(function () use ($draftId): FiscalDocumentDraft {
            $draft = $this->find($draftId);
            $this->authorizeSandbox($draft);
            if ($draft->state === FiscalDocumentDraft::Remote && $draft->remote_id !== null) {
                return $draft;
            }

            if ($draft->state !== FiscalDocumentDraft::Sending || $draft->remote_id !== null || $draft->fiscal_document_id !== null) {
                throw $this->cannotChangeDraft();
            }

            $document = $this->providerDocument($draft);
            if (! hash_equals($draft->payload['requestHash'] ?? '', $this->requestHash($draft, $document))) {
                throw $this->cannotChangeDraft();
            }

            $id = $this->sandbox->create(
                $draft->provider_account, $draft->document_type, $document, $draft->payload['itemIds'],
                $draft->send_key, $draft->preview['totals'], $draft->preview['withholding'] ?? null,
                verifiedTax: $this->verifiedTax($draft),
            );
            $this->verifyRemote($draft, $id, $document['reference']);
            $draft->update(['remote_id' => $id, 'state' => FiscalDocumentDraft::Remote]);

            return $draft;
        });
    }

    private function save(array $inputs, string $revision, ?int $draftId, ?string $requestKey, bool $note): FiscalDocumentDraft
    {
        return $this->locked(function () use ($inputs, $revision, $draftId, $requestKey, $note): FiscalDocumentDraft {
            $account = $this->client->configuration()->subdomain;
            $draft = $draftId === null ? new FiscalDocumentDraft : $this->find($draftId);
            if ($draftId === null && $requestKey !== null) {
                if (! Str::isUuid($requestKey)) {
                    throw $this->cannotChangeDraft();
                }

                $existing = FiscalDocumentDraft::query()->where('send_key', $requestKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->provider !== self::Provider || $existing->provider_account !== $account
                        || isset(InvoiceXpressNoteTerms::Types[$existing->document_type]) !== $note
                        || ! hash_equals($existing->revision, $revision)) {
                        throw $this->cannotChangeDraft();
                    }

                    return $existing;
                }

                $draft->send_key = $requestKey;
            }

            if ($draft->exists && ($draft->state !== FiscalDocumentDraft::Local || $draft->remote_id !== null
                || $draft->fiscal_document_id !== null || isset(InvoiceXpressNoteTerms::Types[$draft->document_type]) !== $note)) {
                throw $this->cannotChangeDraft();
            }

            $preview = $this->validatedPreview($inputs, $revision, $note);
            $draft->fill([
                'provider' => self::Provider,
                'provider_account' => $account,
                'document_type' => $note ? $preview['input']['kind'] : ($preview['documentType'] ?? 'invoice'),
                'revision' => $revision,
                'preview' => $preview,
                'payload' => [
                    'inputs' => $note ? $preview['input'] : $inputs,
                    'document' => $note ? $preview['payload'] : $preview['invoice'],
                    'itemIds' => $note
                        ? [$preview['input']['itemId']]
                        : array_column(array_column($preview['lines'] ?? [$preview], 'item'), 'id'),
                ],
            ])->save();

            return $draft;
        });
    }

    private function validatedPreview(array $inputs, string $revision, bool $note): array
    {
        $preview = $note ? $this->notes->preview($inputs) : $this->invoices->preview(...$inputs);
        if (! isset($preview['revision'], $preview[$note ? 'payload' : 'invoice']) || ! hash_equals($preview['revision'], $revision)) {
            throw ValidationException::withMessages(['confirmation' => __('The details have changed. Preview the document again.')]);
        }

        return $preview;
    }

    private function find(int $id): FiscalDocumentDraft
    {
        $draft = FiscalDocumentDraft::query()->where('provider', self::Provider)->lockForUpdate()->findOrFail($id);
        abort_unless($draft->provider_account === $this->client->configuration()->subdomain, 403);

        return $draft;
    }

    private function authorizeSandbox(FiscalDocumentDraft $draft): void
    {
        $preview = $draft->preview;
        $sandboxPreview = ($preview['draftAccount'] ?? null) === $draft->provider_account
            || ($preview['draftOnly'] ?? false) === true;

        abort_unless($sandboxPreview && ($preview['sequence']['name'] ?? null) === InvoiceXpressSandboxDrafts::Series
            && InvoiceXpressClient::sandboxDraftsAllowed($draft->provider_account)
            && (InvoiceXpressIssue::options()['draftOnly'] ?? false), 403);
    }

    /** @return array{id: int, name: string, region: string, basisPoints: int, code?: string}|null */
    private function verifiedTax(FiscalDocumentDraft $draft): ?array
    {
        $preview = $draft->preview;
        if (! isset(InvoiceXpressNoteTerms::Types[$draft->document_type])) {
            return isset($preview['domesticVat']) ? $preview['tax'] : null;
        }

        $line = ($preview['input']['line'] ?? 0) - 1;
        $tax = $preview['original']['lines'][$line]['tax'] ?? null;
        if (! is_array($tax)) {
            throw $this->cannotChangeDraft();
        }

        return $tax + ['region' => PortugueseVatRegion::Mainland->value];
    }

    private function providerDocument(FiscalDocumentDraft $draft): array
    {
        $payload = $draft->payload['document'];
        $payload['reference'] = 'Contiu draft '.$draft->send_key;

        return isset(InvoiceXpressNoteTerms::Types[$draft->document_type])
            ? $this->notes->providerPayload($payload)
            : $this->invoices->providerInvoice($payload);
    }

    private function requestHash(FiscalDocumentDraft $draft, array $document): string
    {
        return hash('sha256', json_encode([
            $draft->provider_account,
            [$draft->document_type => $document, 'proprietary_uid' => $draft->send_key],
            ...(isset($draft->preview['domesticVat']) ? [$draft->preview['domesticVat'], $draft->preview['tax']] : []),
        ], JSON_THROW_ON_ERROR));
    }

    private function verifyRemote(FiscalDocumentDraft $draft, int $id, string $reference): void
    {
        $type = $draft->document_type;
        $account = $draft->provider_account;
        $remote = $this->client->request('GET', "/{$type}s/{$id}.json", expectedSubdomain: $account)[$type] ?? null;
        if (is_array($remote) && InvoiceXpressIssueTerms::positiveId($remote['id'] ?? null) === $id
            && ($remote['status'] ?? null) === 'draft'
            && ($remote['archived'] ?? false) === false
            && InvoiceXpressIssueTerms::matchesAmounts($remote, $draft->preview['totals'], $draft->preview['withholding'] ?? null)) {
            $matches = isset(InvoiceXpressNoteTerms::Types[$type])
                ? $this->notes->matchesRemote($draft->preview, $remote, $id, $reference, $account)
                : $this->invoices->matchesSandboxDraft($draft->preview, $remote, $reference);
            if ($matches) {
                return;
            }
        }

        throw new InvoiceXpressException(__('The InvoiceXpress draft could not be verified. Check it in the sandbox.'));
    }

    /** @param Closure(): FiscalDocumentDraft $work */
    private function locked(Closure $work): FiscalDocumentDraft
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        return DB::transaction(function () use ($work): FiscalDocumentDraft {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

            return $work();
        });
    }

    private function cannotChangeDraft(): ValidationException
    {
        return ValidationException::withMessages(['confirmation' => __('This draft can no longer be changed. Check its delivery status.')]);
    }
}
