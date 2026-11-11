<?php

namespace App\Filament\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Support\DetailPage;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;

/**
 * Page header of a fiscal document (discovered by convention), as in the mock: what it is and its
 * number with its state right after it, then one muted line saying who it is for, its dates and,
 * on a note or a receipt, the invoice it was issued against.
 */
class FiscalDocumentHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (FiscalDocument $record): string => static::titleLine($record), html: true)
                ->description(fn (FiscalDocument $record): string => static::identityLine($record), html: true),
        ]);
    }

    /** "Fatura FT 2026/15" and its badges (issued, still to be collected), as HTML. Every value is escaped. */
    public static function titleLine(FiscalDocument $document): string
    {
        return DetailPage::titleWithBadges(FiscalDocumentPresenter::heading($document), FiscalDocumentPresenter::badges($document));
    }

    /**
     * "Client · emitida a 12/11/2026 · vence a 12/12/2026", as HTML. A note or a receipt ends
     * with a link to its invoice. Every value is escaped.
     */
    public static function identityLine(FiscalDocument $document): string
    {
        $format = Language::resolve(app()->getLocale())->dateFormat();
        $date = ['date' => $document->document_date->format($format)];
        $issued = $document->direction === FiscalDocumentDirection::Issued;

        $details = [
            filled($document->party_name) ? $document->party_name : ($issued ? __('Final consumer') : null),
            match (true) {
                $document->isDraft() => __('dated :date', $date),
                ! $issued => FiscalDocumentPresenter::agree($document, 'received on :date', $date),
                $document->type->settlesOnIssue() => __('issued and paid on :date', $date),
                default => FiscalDocumentPresenter::agree($document, 'issued on :date', $date),
            },
            static::term($document, $format),
            static::departure($document, $format),
            static::cancelled($document, $format),
            $issued && ! $document->isDraft() && $document->integrationRecord === null
                ? FiscalDocumentPresenter::agree($document, 'recorded by hand')
                : null,
        ];

        return collect($details)->filter()->map(fn (string $detail): string => e($detail))
            ->push(static::original($document))
            ->filter()
            ->implode(' · ');
    }

    /** "vence a …", "válido até …" or "pagar até …", for the kinds that have a second date and while they still stand. */
    private static function term(FiscalDocument $document, string $format): ?string
    {
        if ($document->due_date === null || ! $document->type->hasTerm() || $document->status === FiscalDocumentStatus::Voided) {
            return null;
        }

        $date = ['date' => $document->due_date->format($format)];

        return match ($document->type) {
            FiscalDocumentType::Quote => __('valid until :date', $date),
            FiscalDocumentType::ProForma => __('pay by :date', $date),
            default => __('due on :date', $date),
        };
    }

    /** "os bens saem às 15:00": when the goods of a transport guide leave. */
    private static function departure(FiscalDocument $document, string $format): ?string
    {
        $startsAt = $document->type === FiscalDocumentType::TransportGuide ? ($document->transport['starts_at'] ?? null) : null;

        if (blank($startsAt)) {
            return null;
        }

        $startsAt = CarbonImmutable::parse($startsAt);

        return $startsAt->isSameDay($document->document_date)
            ? __('the goods leave at :time', ['time' => $startsAt->format('H:i')])
            : __('the goods leave on :date at :time', ['date' => $startsAt->format($format), 'time' => $startsAt->format('H:i')]);
    }

    /** "anulada a 03/11/2026", while the cancellation is still in the document's history. */
    private static function cancelled(FiscalDocument $document, string $format): ?string
    {
        $cancellation = FiscalDocumentPresenter::cancellation($document);

        return $cancellation === null
            ? null
            : FiscalDocumentPresenter::agree($document, 'voided on :date', ['date' => $cancellation->created_at->format($format)]);
    }

    /** "corrige a FT 2026/9", with the invoice's number as a link to it. Already HTML. */
    private static function original(FiscalDocument $document): ?string
    {
        $original = $document->type->requiresOriginal() ? $document->links?->relatedFiscalDocument : null;

        if ($original === null) {
            return null;
        }

        $phrase = match ($document->type) {
            FiscalDocumentType::CreditNote => __('corrects :document'),
            FiscalDocumentType::DebitNote => __('adds to :document'),
            default => $document->total_amount < $original->payableAmount() ? __('pays part of :document') : __('pays :document'),
        };

        $link = '<a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="'.e(FiscalDocumentResource::getUrl('view', ['record' => $original])).'">'.e(FiscalDocumentPresenter::title($original)).'</a>';

        return str_replace(':document', $link, e($phrase));
    }
}
