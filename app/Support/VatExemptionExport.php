<?php

namespace App\Support;

use App\Enums\FiscalDocumentType;
use App\Models\Client;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;

/**
 * An account in the art. 53.º exemption that exports goods loses the exemption with that sale,
 * whatever its amount and even once (IVA08; CIVA art. 53.º, n.º 1, and 58.º, n.º 2, c), 4, c) and
 * 5, c); case VAT05). The declaration of changes is due within 15 working days. A service to a
 * client abroad is not an export of goods and does not end it (VAT06).
 *
 * The lines do not say whether they are goods or services, so the warning is shown whenever an
 * exempt account that sells goods invoices a client outside the European Union, worded as "if".
 * Contiu only warns: it never switches the VAT regime.
 */
final class VatExemptionExport
{
    /** Working days to submit the declaration of changes (CIVA art. 58.º, IVA10). */
    public const int DECLARATION_WORKING_DAYS = 15;

    /**
     * Whether an invoice of this type, dated $on, to this client may be an export of goods by an
     * account exempt under art. 53.º on that date (IVA08).
     */
    public static function applies(?Client $client, FiscalDocumentType $type, CarbonImmutable $on): bool
    {
        $settings = app(FinanceSettings::class);

        return $type->isInvoice()
            && ($settings->sellsGoods() || $settings->sellsGoodsUnderIrs())
            && self::outsideEu($client)
            && (new TaxEstimator($settings, $on->year))->vatExempt($on);
    }

    /**
     * The client's tax country (or, without one, the country of its address) is known and is
     * neither Portugal nor another member state. A client without a country is not assumed abroad.
     */
    public static function outsideEu(?Client $client): bool
    {
        $country = $client?->fiscal_country_code ?? $client?->structuredAddress?->country_code;

        return $country !== null
            && $country !== 'PT'
            && ! in_array(strtoupper($country), EuServiceEvidence::countryCodes(), true);
    }

    /**
     * The last day to submit the declaration of changes after an export on $exportedOn: 15 working
     * days counted from the next day (IVA10), then moved by PortugueseDeadline (OBR03).
     */
    public static function declarationDeadline(CarbonImmutable $exportedOn): CarbonImmutable
    {
        $day = $exportedOn->startOfDay();
        $counted = 0;

        while ($counted < self::DECLARATION_WORKING_DAYS) {
            $day = $day->addDay();

            if (PortugueseDeadline::isWorkingDay($day)) {
                $counted++;
            }
        }

        return PortugueseDeadline::of($day);
    }

    /** The warning for a document being written, or null when it does not apply (IVA08, VAT05, VAT06). */
    public static function warning(?Client $client, FiscalDocumentType $type, CarbonImmutable $on): ?string
    {
        if (! self::applies($client, $type, $on)) {
            return null;
        }

        return __('If this invoice sells goods that leave the European Union, it is an export: your VAT exemption (article 53) ends with this sale, whatever the amount. Submit a declaration of changes by :date (15 working days) and charge VAT from then on. The export itself may be exempt under article 14 of the VAT code, with proof that the goods left. Contiu does not change your VAT regime: update it in Tax settings once declared.', [
            'date' => self::declarationDeadline($on)->locale(app()->getLocale())->translatedFormat(__('F j, Y')),
        ]);
    }

    /** The same warning once the document is issued, from its own date and client. */
    public static function issuedWarning(FiscalDocument $document): ?string
    {
        return self::warning($document->client, $document->type, CarbonImmutable::parse($document->document_date));
    }
}
