<?php

namespace App\Actions;

use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Support\FiscalDocumentRules;
use App\Support\FiscalDocumentTotals;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves a document recorded by hand or imported: its header, its links and, when they are given,
 * its lines. With lines, the taxable base, the VAT and the total are the lines' own sums; without
 * them, the header amounts are kept as typed and the total must still be the base plus the VAT.
 */
final class SaveFiscalDocument
{
    /**
     * @param  array<string, mixed>  $data  `lines` only when the caller manages them: absent, the document's lines stay as they are
     */
    public function handle(array $data, ?FiscalDocument $document = null): FiscalDocument
    {
        $lines = array_key_exists('lines', $data) ? $this->lines($data) : null;
        unset($data['lines']);

        if ($lines !== null && $lines !== []) {
            $totals = FiscalDocumentTotals::of($lines);
            $data = [...$data, 'net_amount' => $totals->net, 'vat_amount' => $totals->vat, 'total_amount' => $totals->total, 'discount_amount' => $totals->discount];
        } elseif ($lines !== null && (int) ($data['total_amount'] ?? 0) !== (int) ($data['net_amount'] ?? 0) + (int) ($data['vat_amount'] ?? 0)) {
            throw ValidationException::withMessages(['data.total_amount' => __('The total must equal the taxable base plus VAT.')]);
        }

        return DB::transaction(function () use ($data, $document, $lines): FiscalDocument {
            $document = $document === null
                ? new FiscalDocument
                : FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            $document->fill(Arr::except($data, FiscalDocumentLink::TARGET_FIELDS))->save();

            if ($lines !== null) {
                $this->storeLines($document, $lines);
            }

            $targets = array_replace(
                array_fill_keys(FiscalDocumentLink::TARGET_FIELDS, null),
                Arr::only($data, FiscalDocumentLink::TARGET_FIELDS),
            );

            if (Arr::whereNotNull($targets) !== []) {
                $document->links()->updateOrCreate([], $targets);
            } else {
                $document->links()->first()?->delete();
            }

            return $document;
        });
    }

    /**
     * The lines given, checked against the same limits as a document written in the app.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{description: string, quantity: int, unit: string, unit_price: int, vat_basis_points: int}>
     */
    private function lines(array $data): array
    {
        $type = ($data['type'] ?? null) instanceof FiscalDocumentType ? $data['type'] : (FiscalDocumentType::tryFrom((string) ($data['type'] ?? '')) ?? FiscalDocumentType::Invoice);
        $lines = array_values((array) ($data['lines'] ?? []));
        $validator = Validator::make(['lines' => $lines], FiscalDocumentRules::recordedLineRules($type));

        if ($validator->fails()) {
            throw ValidationException::withMessages(['data.lines' => $validator->errors()->first()]);
        }

        return array_map(fn (array $line): array => [
            'description' => (string) $line['description'],
            'quantity' => (int) $line['quantity'],
            'unit' => (string) $line['unit'],
            'unit_price' => (int) $line['unit_price'],
            'vat_basis_points' => (int) $line['vat_basis_points'],
        ], $lines);
    }

    /**
     * Replaces the document's lines with these, each with the amounts the sum gives it.
     *
     * @param  list<array{description: string, quantity: int, unit: string, unit_price: int, vat_basis_points: int}>  $lines
     */
    private function storeLines(FiscalDocument $document, array $lines): void
    {
        $totals = FiscalDocumentTotals::of($lines);
        $document->lines()->delete();

        foreach ($lines as $index => $line) {
            $document->lines()->create([
                ...$line,
                'sort' => $index,
                'discount_basis_points' => 0,
                'net_amount' => $totals->lines[$index]['net'],
                'vat_amount' => $totals->lines[$index]['vat'],
                'total_amount' => $totals->lines[$index]['total'],
            ]);
        }
    }
}
