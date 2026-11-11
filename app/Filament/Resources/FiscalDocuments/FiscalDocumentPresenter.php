<?php

namespace App\Filament\Resources\FiscalDocuments;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationRecordState;
use App\Models\FiscalDocument;
use App\Models\IntegrationOperation;
use App\Support\Invoicing\Invoicing;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * How a fiscal document is named and described on screen. The list, the document's page and the
 * synchronisation all say it the same way because they all ask here.
 */
final class FiscalDocumentPresenter
{
    /** How many entries of a document's history its page shows. */
    public const int HISTORY_LENGTH = 8;

    /** @var WeakMap<FiscalDocument, Collection<int, IntegrationOperation>>|null */
    private static ?WeakMap $histories = null;

    /** "FT 2026/15", or "Invoice to issue" while it is a draft without a number. */
    public static function title(FiscalDocument $document): string
    {
        return $document->number ?? __(':type to issue', ['type' => $document->type->getLabel()]);
    }

    /** The title of the document's own page: "Fatura FT 2026/15", "Fatura recebida FT A/2291". */
    public static function heading(FiscalDocument $document): string
    {
        if ($document->number === null) {
            return self::title($document);
        }

        $kind = $document->direction === FiscalDocumentDirection::Received
            ? self::agree($document, ':type received', ['type' => $document->type->getLabel()])
            : $document->type->getLabel();

        return "{$kind} {$document->number}";
    }

    /**
     * "Fatura · emitida", "Recibo · emitido", "Fatura · rascunho". Portuguese agrees the state
     * with the document, so the masculine ones have their own wording.
     */
    public static function kind(FiscalDocument $document): string
    {
        $type = ['type' => $document->type->getLabel()];
        $masculine = self::isMasculine($document->type);

        return match (true) {
            $document->isDraft() => __(':type · draft', $type),
            $document->direction === FiscalDocumentDirection::Received => $masculine ? __(':type · received (masculine)', $type) : __(':type · received', $type),
            default => $masculine ? __(':type · issued (masculine)', $type) : __(':type · issued', $type),
        };
    }

    /** "Recibo" and "orçamento" are masculine in Portuguese; every other kind is feminine. */
    public static function isMasculine(FiscalDocumentType $type): bool
    {
        return in_array($type, [FiscalDocumentType::Receipt, FiscalDocumentType::Quote], true);
    }

    /**
     * A phrase about the document, agreeing with it: the key as it is for a masculine document
     * and with " (feminine)" after it for the others ("Emitido", "Emitida").
     *
     * @param  array<string, string>  $replace
     */
    public static function agree(FiscalDocument $document, string $key, array $replace = []): string
    {
        return __(self::isMasculine($document->type) ? $key : "{$key} (feminine)", $replace);
    }

    /**
     * The state shown as a badge: a draft, an accepted quote, a voided document, or simply issued
     * or recorded.
     *
     * @return array{label: string, color: string}
     */
    public static function state(FiscalDocument $document): array
    {
        return match (true) {
            $document->status === FiscalDocumentStatus::Draft => ['label' => __('Draft'), 'color' => 'gray'],
            $document->status === FiscalDocumentStatus::Voided => ['label' => __('Voided'), 'color' => 'gray'],
            $document->type === FiscalDocumentType::Quote && $document->accepted_at !== null => ['label' => __('Accepted'), 'color' => 'info'],
            $document->direction === FiscalDocumentDirection::Received => ['label' => __('Recorded'), 'color' => 'success'],
            default => ['label' => __('Issued'), 'color' => 'success'],
        };
    }

    /**
     * The badges beside the title of the document's page: its state, agreeing with the document
     * ("Fatura … Emitida"), and whether its money has come in or gone out yet.
     *
     * @return list<array{label: string, color: string}>
     */
    public static function badges(FiscalDocument $document): array
    {
        $state = match (true) {
            $document->status === FiscalDocumentStatus::Draft => ['label' => __('Draft'), 'color' => 'gray'],
            $document->status === FiscalDocumentStatus::Voided => ['label' => self::agree($document, 'Voided'), 'color' => 'danger'],
            $document->type === FiscalDocumentType::Quote && $document->accepted_at !== null => ['label' => __('Accepted'), 'color' => 'info'],
            $document->direction === FiscalDocumentDirection::Received => ['label' => self::agree($document, 'Recorded'), 'color' => 'success'],
            default => ['label' => self::agree($document, 'Issued'), 'color' => 'success'],
        };

        return array_values(array_filter([$state, self::settlement($document)]));
    }

    /**
     * Whether what the document charges was collected, or what it bills was paid. Only the kinds
     * that ask for money say it: a note or a receipt changes an invoice, it is not owed by itself.
     *
     * @return array{label: string, color: string}|null
     */
    public static function settlement(FiscalDocument $document): ?array
    {
        if ($document->status !== FiscalDocumentStatus::Active) {
            return null;
        }

        $link = $document->links;

        if ($document->direction === FiscalDocumentDirection::Received) {
            return match (true) {
                $link?->expense === null => null,
                (bool) $link->expense->is_paid => ['label' => self::agree($document, 'Paid'), 'color' => 'success'],
                default => ['label' => __('To pay'), 'color' => 'warning'],
            };
        }

        $charges = $document->type->isInvoice() || in_array($document->type, [FiscalDocumentType::DebitNote, FiscalDocumentType::ProForma], true);

        return match (true) {
            ! $charges => null,
            $link?->receivable !== null && $link->receivable->outstanding > 0 => $document->type === FiscalDocumentType::ProForma
                ? ['label' => __('Waiting for payment'), 'color' => 'warning']
                : ['label' => __('Awaiting payment'), 'color' => 'warning'],
            $link?->receivable !== null, $document->type->settlesOnIssue() && $link?->payment !== null => ['label' => self::agree($document, 'Received'), 'color' => 'success'],
            default => null,
        };
    }

    /** What the bold line of the totals is called: each kind names what its amount is. */
    public static function totalLabel(FiscalDocument $document): string
    {
        return match ($document->type) {
            FiscalDocumentType::CreditNote => __('Total credited'),
            FiscalDocumentType::DebitNote => __('Total debited'),
            FiscalDocumentType::Quote => __('Quote total'),
            FiscalDocumentType::ProForma => __('Pro forma total'),
            FiscalDocumentType::TransportGuide => __('Value of the goods'),
            FiscalDocumentType::Receipt => __('Total received'),
            default => __('Document total'),
        };
    }

    /**
     * Who issued the document and how it compares with what the provider holds.
     *
     * @return array{label: string, detail: ?string, color: ?string}
     */
    public static function issuer(FiscalDocument $document): array
    {
        $record = $document->integrationRecord;

        if ($record === null) {
            return ['label' => $document->origin, 'detail' => null, 'color' => null];
        }

        return [
            'label' => Invoicing::providerLabel() ?? $document->origin,
            'detail' => match (true) {
                $document->isDraft() => __('Draft to issue'),
                $record->state === IntegrationRecordState::Differs => __('Different total'),
                default => __('In sync'),
            },
            'color' => $record->state === IntegrationRecordState::Differs ? 'warning' : null,
        ];
    }

    /**
     * What was asked of the provider about this document, newest first. Read once for the page:
     * its header and its history both ask here.
     *
     * @return Collection<int, IntegrationOperation>
     */
    public static function history(FiscalDocument $document): Collection
    {
        self::$histories ??= new WeakMap;

        return self::$histories[$document] ??= IntegrationOperation::query()
            ->whereMorphedTo('subject', $document)
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::HISTORY_LENGTH)
            ->get();
    }

    /** When and why the document was cancelled at the provider, while that is still in its history. */
    public static function cancellation(FiscalDocument $document): ?IntegrationOperation
    {
        return $document->status === FiscalDocumentStatus::Voided
            ? self::history($document)->firstWhere('kind', 'cancelled')
            : null;
    }

    /** A credit note takes from what was billed, so its amounts read as negative. */
    public static function signed(FiscalDocument $document, int $amount): int
    {
        return $document->type === FiscalDocumentType::CreditNote ? -abs($amount) : $amount;
    }
}
