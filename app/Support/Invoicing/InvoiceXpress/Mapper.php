<?php

namespace App\Support\Invoicing\InvoiceXpress;

use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoicingProviderException;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Support\FiscalTerms;
use App\Support\Invoicing\InvoicingCatalog;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use App\Support\Invoicing\RemoteLine;
use App\Support\Invoicing\RemoteReferences;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Translates between the app's documents and InvoiceXpress's JSON. The only place that knows
 * InvoiceXpress's field names, date format, percentages and document type names.
 */
final class Mapper
{
    /** The only units InvoiceXpress accepts on a line or an article. */
    private const array UNITS = ['unit', 'hour', 'day', 'month', 'service', 'article', 'product', 'tax', 'other'];

    /**
     * The app's units and what InvoiceXpress calls them. The first of each is what a unit read
     * from there becomes.
     */
    private const array LOCAL_UNITS = [
        'un' => 'unit', 'unid' => 'unit', 'unidade' => 'unit',
        'h' => 'hour', 'hora' => 'hour', 'horas' => 'hour',
        'dia' => 'day', 'dias' => 'day',
        'mês' => 'month', 'mes' => 'month', 'meses' => 'month',
        'serviço' => 'service', 'servico' => 'service',
        'artigo' => 'article',
        'produto' => 'product',
        'taxa' => 'tax',
        'outro' => 'other',
    ];

    /**
     * The API resource, the JSON key and InvoiceXpress's own type name for each document type.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const array RESOURCES = [
        'invoice' => ['invoices', 'invoice', 'Invoice'],
        'invoice_receipt' => ['invoice_receipts', 'invoice_receipt', 'InvoiceReceipt'],
        'simplified_invoice' => ['simplified_invoices', 'simplified_invoice', 'SimplifiedInvoice'],
        'credit_note' => ['credit_notes', 'credit_note', 'CreditNote'],
        'debit_note' => ['debit_notes', 'debit_note', 'DebitNote'],
        'receipt' => ['receipts', 'receipt', 'Receipt'],
        'quote' => ['quotes', 'quote', 'Quote'],
        'pro_forma' => ['proformas', 'proforma', 'Proforma'],
        'transport_guide' => ['transports', 'transport', 'Transport'],
    ];

    /** How a payment was made, in the codes the tax authority's file format uses. */
    private const array PAYMENT_MECHANISMS = ['transfer' => 'TB', 'mbway' => 'OU', 'card' => 'CC', 'cash' => 'NU', 'other' => 'OU'];

    public static function resource(FiscalDocumentType $type): string
    {
        return self::RESOURCES[$type->value][0];
    }

    public static function key(FiscalDocumentType $type): string
    {
        return self::RESOURCES[$type->value][1];
    }

    /** @return list<string> */
    public static function invoiceTypeNames(): array
    {
        return array_map(
            fn (FiscalDocumentType $type): string => self::RESOURCES[$type->value][2],
            [...FiscalDocumentType::billing(), FiscalDocumentType::Receipt],
        );
    }

    /**
     * What InvoiceXpress needs to create or update the draft of a document.
     *
     * @return array<string, mixed>
     */
    public static function payload(FiscalDocument $document, InvoicingCatalog $catalog, RemoteReferences $references, ?string $seriesId, ?string $region): array
    {
        $type = $document->type;
        $date = $document->document_date->format('d/m/Y');
        $series = $catalog->series($seriesId);

        $body = [
            'date' => $date,
            'due_date' => ($document->due_date ?? $document->document_date)->format('d/m/Y'),
            'reference' => $document->client_reference ?: "Contiu #{$document->getKey()}",
            'observations' => (string) ($document->reason ?: $document->notes),
            'currency_code' => $document->currency,
            'client' => $references->client !== null
                ? ['id' => (int) $references->client]
                : ['name' => $document->party_name ?: __('Final consumer'), 'code' => 'CONSUMIDOR-FINAL'],
            'items' => $document->lines->map(fn (FiscalDocumentLine $line): array => self::item($line, $catalog, $region))->all(),
        ];

        if (isset($series['ids'][$type->value])) {
            $body['sequence_id'] = $series['ids'][$type->value];
        }

        if ($document->withholding_basis_points > 0) {
            $body['retention'] = self::percent($document->withholding_basis_points);
        }

        if (filled($document->vat_exemption_reason)) {
            $body['tax_exemption_reason'] = $document->vat_exemption_reason;
        }

        if ($type->isInvoice()) {
            $body['mb_reference'] = '0';
        }

        if ($type->requiresOriginal() && $references->owner !== null) {
            $body['owner_invoice_id'] = (int) $references->owner;
        }

        if ($document->transport !== null) {
            $body = [...$body, ...self::transport($document->transport)];
        }

        return [
            // Lets InvoiceXpress recognise the same document if the request is repeated.
            'proprietary_uid' => self::uid($document),
            self::key($type) => $body,
        ];
    }

    /**
     * The receipt of a payment against an invoice InvoiceXpress holds.
     *
     * @return array<string, array<string, string>>
     */
    public static function payment(FiscalDocument $receipt, InvoicingCatalog $catalog, ?string $seriesId): array
    {
        $paidOn = CarbonImmutable::parse($receipt->payment['paid_on'] ?? $receipt->document_date);

        return ['partial_payment' => [
            'amount' => self::euros($receipt->total_amount),
            'payment_mechanism' => self::PAYMENT_MECHANISMS[$receipt->payment['method'] ?? 'transfer'] ?? 'OU',
            'payment_date' => $paidOn->format('d/m/Y'),
            'serie' => (string) ($catalog->series($seriesId)['name'] ?? ''),
            'note' => 'Contiu recibo '.self::uid($receipt),
        ]];
    }

    /**
     * A document as InvoiceXpress returned it, in the app's terms.
     *
     * @param  array<string, mixed>  $response  the API's envelope, or the document inside it
     */
    public static function document(array $response): RemoteDocument
    {
        $data = isset($response['id']) ? $response : (is_array($first = reset($response)) ? $first : []);
        $type = self::type($data['type'] ?? null);
        $id = $data['id'] ?? null;
        $date = self::date($data['date'] ?? null);

        if ($type === null || ! is_int($id) || $id < 1 || $date === null) {
            throw new InvoicingProviderException(__('InvoiceXpress returned a document the app cannot read.'));
        }

        $net = self::cents($data['before_taxes'] ?? $data['sum'] ?? 0);
        $vat = self::cents($data['taxes'] ?? 0);
        $retention = self::basisPoints($data['retention'] ?? 0);
        $inverted = is_string($data['inverted_sequence_number'] ?? null) ? trim($data['inverted_sequence_number']) : null;
        $issued = ! in_array($data['status'] ?? null, ['draft', null], true);

        return new RemoteDocument(
            remoteType: self::resource($type),
            remoteId: (string) $id,
            type: $type,
            state: match ($data['status'] ?? null) {
                'draft' => RemoteDocumentState::Draft,
                'canceled', 'deleted' => RemoteDocumentState::Canceled,
                default => RemoteDocumentState::Issued,
            },
            date: $date,
            net: $net,
            vat: $vat,
            // InvoiceXpress reports the total after withholding; the app keeps the document's own total.
            total: $net + $vat,
            number: $issued && filled($inverted) ? "{$type->prefix()} {$inverted}" : null,
            series: $issued && filled($inverted) ? Str::before($inverted, '/') : null,
            atcud: is_string($data['atcud'] ?? null) && $data['atcud'] !== '' ? mb_substr($data['atcud'], 0, 64) : null,
            dueDate: self::date($data['due_date'] ?? null),
            withholding: FiscalTerms::withholdingAmount($retention, $net),
            partyName: self::text($data['client']['name'] ?? null, 255),
            partyTaxId: self::text($data['client']['fiscal_id'] ?? null, 64),
            lines: array_values(array_map(self::line(...), array_filter((array) ($data['items'] ?? []), is_array(...)))),
            url: is_string($data['permalink'] ?? null) && str_starts_with($data['permalink'], 'https://') ? $data['permalink'] : null,
            ownerRemoteId: isset($data['owner_invoice_id']) && is_int($data['owner_invoice_id']) ? (string) $data['owner_invoice_id'] : null,
        );
    }

    /** @return array<string, mixed> */
    private static function item(FiscalDocumentLine $line, InvoicingCatalog $catalog, ?string $region): array
    {
        $tax = $catalog->taxName($line->vat_basis_points, $region);

        if ($tax === null) {
            throw new InvoicingProviderException(__('InvoiceXpress has no tax of :rate%. Create it there first.', ['rate' => self::percent($line->vat_basis_points)]));
        }

        return [
            // InvoiceXpress keys its catalogue by name: an article keeps its own, a free line uses its text.
            'name' => Str::limit($line->article?->name ?? $line->description, 57, '…'),
            'description' => $line->description,
            'unit_price' => self::euros($line->unit_price),
            'quantity' => self::decimal($line->quantity, FiscalDocumentLine::QUANTITY_SCALE, 3),
            'unit' => self::unit($line->unit),
            'discount' => self::percent($line->discount_basis_points),
            'tax' => ['name' => $tax],
        ];
    }

    /**
     * The unit as InvoiceXpress takes it. It only accepts the units in UNITS; anything else the
     * user wrote (a box, a kilo) goes as "other", and stays as written on the app's own document.
     */
    public static function unit(?string $unit): string
    {
        $unit = mb_strtolower(trim((string) $unit));

        return match (true) {
            in_array($unit, self::UNITS, true) => $unit,
            isset(self::LOCAL_UNITS[$unit]) => self::LOCAL_UNITS[$unit],
            default => 'other',
        };
    }

    /** A unit read from InvoiceXpress, as the app writes it. */
    private static function localUnit(?string $unit): string
    {
        return $unit === null ? 'un' : (array_search(mb_strtolower($unit), self::LOCAL_UNITS, true) ?: $unit);
    }

    /**
     * @param  array<string, mixed>  $transport
     * @return array<string, mixed>
     */
    private static function transport(array $transport): array
    {
        $address = fn (array $place): array => [
            'detail' => (string) ($place['address'] ?? ''),
            'city' => (string) ($place['city'] ?? ''),
            'postal_code' => (string) ($place['postal_code'] ?? ''),
            'country' => 'Portugal',
        ];

        return array_filter([
            'loaded_at' => isset($transport['starts_at']) ? CarbonImmutable::parse($transport['starts_at'])->format('d/m/Y H:i:s') : null,
            'license_plate' => $transport['plate'] ?? null,
            'address_from' => $address((array) ($transport['load'] ?? [])),
            'address_to' => $address((array) ($transport['unload'] ?? [])),
        ], filled(...));
    }

    /** @param array<string, mixed> $item */
    private static function line(array $item): RemoteLine
    {
        return new RemoteLine(
            description: self::text($item['description'] ?? null, 255) ?? self::text($item['name'] ?? null, 255) ?? '',
            quantity: self::scaled($item['quantity'] ?? 0, 3),
            unit: self::localUnit(self::text($item['unit'] ?? null, 16)),
            unitPrice: self::cents($item['unit_price'] ?? 0),
            discountBasisPoints: self::basisPoints($item['discount'] ?? 0),
            vatBasisPoints: self::basisPoints($item['tax']['value'] ?? 0),
        );
    }

    private static function type(mixed $name): ?FiscalDocumentType
    {
        foreach (self::RESOURCES as $value => [, , $remote]) {
            if ($remote === $name) {
                return FiscalDocumentType::from($value);
            }
        }

        return null;
    }

    /** The same id for the same document every time, so a repeated request is not a second document. */
    private static function uid(FiscalDocument $document): string
    {
        return substr(hash_hmac('sha256', "document:{$document->account_id}:{$document->getKey()}", (string) config('app.key')), 0, 32);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{2}\/[0-9]{2}\/[1-9][0-9]{3}\z/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!d/m/Y', $value);

        return $date !== false && $date->format('d/m/Y') === $value ? $date : null;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && trim($value) !== '' ? mb_substr(trim($value), 0, $limit) : null;
    }

    private static function euros(int $cents): string
    {
        return self::decimal($cents, 100, 2);
    }

    private static function percent(int $basisPoints): string
    {
        return self::decimal($basisPoints, 100, 2);
    }

    private static function decimal(int $value, int $scale, int $places): string
    {
        return sprintf('%s%d.%0'.$places.'d', $value < 0 ? '-' : '', intdiv(abs($value), $scale), abs($value) % $scale);
    }

    private static function cents(mixed $value): int
    {
        return self::scaled($value, 2);
    }

    private static function basisPoints(mixed $value): int
    {
        return self::scaled($value, 2);
    }

    /** A decimal read from InvoiceXpress as an integer with that many decimal places, rounded half up. */
    private static function scaled(mixed $value, int $places): int
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }

        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/\A(-?)([0-9]{1,12})(?:[.,]([0-9]+))?\z/', (string) $value, $parts)) {
            return 0;
        }

        $fraction = str_pad(substr($parts[3] ?? '', 0, $places + 1), $places + 1, '0');
        $scaled = (int) $parts[2] * 10 ** $places + intdiv((int) $fraction + 5, 10);

        return $parts[1] === '-' ? -$scaled : $scaled;
    }
}
