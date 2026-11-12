<?php

use App\Actions\SaveFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\FiscalDocument;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicing', 'fiscal', 'ui');

/*
 * A document recorded by hand may list what it charged. With lines, its amounts are their sums,
 * worked out again on the server whatever the header says; without them, the header is kept as
 * typed, as before.
 */

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->undoRepeaterFake = Repeater::fake();
    $this->header = [
        'type' => 'invoice',
        'direction' => 'received',
        'number' => 'FT A/2291',
        'origin' => 'Registo manual',
        'document_date' => '2026-11-10',
        'currency' => 'EUR',
        'party_name' => 'Papelaria Central, Lda.',
    ];
    $this->lines = [
        ['description' => 'Resma de papel A4', 'quantity' => '2.5', 'unit_price' => '4.99', 'vat_basis_points' => '2300'],
        ['description' => 'Livro técnico', 'quantity' => '1', 'unit_price' => '30', 'vat_basis_points' => '600'],
    ];
});

afterEach(fn () => ($this->undoRepeaterFake)());

it('records a document with lines, its amounts worked out from them', function () {
    Livewire::test(CreateFiscalDocument::class)
        ->fillForm([...$this->header, 'lines' => $this->lines])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = FiscalDocument::query()->sole();

    // 2.5 × 4,99 € = 12,48 € at 23 % (2,87 €), and 30,00 € at 6 % (1,80 €).
    expect([$document->net_amount, $document->vat_amount, $document->total_amount])->toBe([4248, 467, 4715])
        ->and($document->lines->map->only(['sort', 'description', 'quantity', 'unit_price', 'vat_basis_points', 'net_amount', 'vat_amount', 'total_amount'])->all())->toBe([
            ['sort' => 0, 'description' => 'Resma de papel A4', 'quantity' => 2500, 'unit_price' => 499, 'vat_basis_points' => 2300, 'net_amount' => 1248, 'vat_amount' => 287, 'total_amount' => 1535],
            ['sort' => 1, 'description' => 'Livro técnico', 'quantity' => 1000, 'unit_price' => 3000, 'vat_basis_points' => 600, 'net_amount' => 3000, 'vat_amount' => 180, 'total_amount' => 3180],
        ]);
});

it('takes the amounts from the lines, not from a header that disagrees with them', function () {
    Livewire::test(CreateFiscalDocument::class)
        ->fillForm([...$this->header, 'net_amount' => '1', 'vat_amount' => '1', 'total_amount' => '999', 'lines' => $this->lines])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(FiscalDocument::query()->sole()->only(['net_amount', 'vat_amount', 'total_amount']))
        ->toBe(['net_amount' => 4248, 'vat_amount' => 467, 'total_amount' => 4715]);
});

it('still records a document with only its header amounts', function () {
    Livewire::test(CreateFiscalDocument::class)
        ->fillForm([...$this->header, 'net_amount' => '100.05', 'vat_amount' => '23.01', 'total_amount' => '123.06'])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = FiscalDocument::query()->sole();

    expect([$document->net_amount, $document->vat_amount, $document->total_amount])->toBe([10005, 2301, 12306])
        ->and($document->lines)->toBeEmpty();
});

it('requires the header amounts when there are no lines', function () {
    Livewire::test(CreateFiscalDocument::class)
        ->fillForm($this->header)
        ->call('create')
        ->assertHasFormErrors(['net_amount' => 'required', 'vat_amount' => 'required', 'total_amount' => 'required']);

    $this->assertDatabaseEmpty('fiscal_documents');
});

it('shows the lines when editing, and keeps the header as typed once they are all removed', function () {
    Livewire::test(CreateFiscalDocument::class)
        ->fillForm([...$this->header, 'lines' => $this->lines])
        ->call('create');
    $document = FiscalDocument::query()->sole();

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->assertSchemaStateSet(['lines.0.description' => 'Resma de papel A4', 'lines.0.quantity' => '2.5', 'lines.1.vat_basis_points' => '600'], 'form')
        ->fillForm(['lines' => [[...$this->lines[1], 'quantity' => '2']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->refresh()->only(['net_amount', 'vat_amount', 'total_amount']))->toBe(['net_amount' => 6000, 'vat_amount' => 360, 'total_amount' => 6360])
        ->and($document->lines()->count())->toBe(1);

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->fillForm(['lines' => [], 'net_amount' => '60', 'vat_amount' => '3.60', 'total_amount' => '63.60'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->refresh()->total_amount)->toBe(6360)
        ->and($document->lines()->count())->toBe(0);
});

it('refuses on the server a total that is not the base plus the VAT, and a line it cannot record', function (array $data, string $field) {
    expect(fn () => app(SaveFiscalDocument::class)->handle([
        'type' => 'invoice', 'direction' => 'received', 'number' => 'FT A/1', 'origin' => 'Registo manual',
        'document_date' => '2026-11-10', 'currency' => 'EUR', 'net_amount' => 10000, 'vat_amount' => 2300, 'total_amount' => 12300,
        ...$data,
    ]))->toThrow(function (ValidationException $exception) use ($field) {
        expect($exception->errors())->toHaveKey($field);
    });

    $this->assertDatabaseEmpty('fiscal_documents');
})->with([
    'a total that does not add up' => [['total_amount' => 12299, 'lines' => []], 'data.total_amount'],
    'a VAT rate Portugal does not have' => [['lines' => [['description' => 'Serviço', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 100, 'vat_basis_points' => 2100]]], 'data.lines'],
    'a line without a description' => [['lines' => [['description' => '', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 100, 'vat_basis_points' => 2300]]], 'data.lines'],
    'lines on a receipt' => [['type' => 'receipt', 'lines' => [['description' => 'Serviço', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 100, 'vat_basis_points' => 2300]]], 'data.lines'],
]);
