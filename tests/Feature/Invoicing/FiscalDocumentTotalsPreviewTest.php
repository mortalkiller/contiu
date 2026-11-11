<?php

use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentDraftForm;
use Illuminate\Support\Facades\Process;

pest()->group('invoicing');

/*
 * While a document is written its totals are added up in the browser, so typing never asks the
 * server. Both sides are held to the same cases: here the server's sums, and in
 * tests/JavaScript the browser's.
 */

it('adds up what is typed as the shared cases say', function (array $lines, int $withholding, array $expected) {
    $totals = FiscalDocumentDraftForm::totals($lines, $withholding);

    expect([
        'gross' => $totals->gross, 'discount' => $totals->discount, 'net' => $totals->net, 'vat' => $totals->vat,
        'total' => $totals->total, 'withholding' => $totals->withholding, 'payable' => $totals->payable(),
    ])->toBe($expected);
})->with(fn (): array => collect(json_decode(file_get_contents(__DIR__.'/../../JavaScript/fiscal-document-totals.cases.json'), true, flags: JSON_THROW_ON_ERROR))
    ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case['lines'], $case['withholding'], $case['expected']]])
    ->all());

it('keeps the browser\'s sums equal to the server\'s', function () {
    $result = Process::path(base_path())->run(['node', '--test', 'tests/JavaScript/fiscal-document-totals.test.js']);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->skip(fn (): bool => Process::run(['node', '--version'])->failed(), 'Node is not installed.');
