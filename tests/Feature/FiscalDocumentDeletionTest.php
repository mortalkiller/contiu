<?php

use App\Actions\RecordOneOffIncome;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use Illuminate\Database\QueryException;

pest()->group('integration', 'fiscal');

it('preserves all instalments when a fiscal document blocks deleting the project', function () {
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-01']);
    $second = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-31']);
    FiscalDocument::factory()->create()->links()->create(['receivable_id' => $second->id]);

    expect(fn () => $project->delete())->toThrow(QueryException::class);

    expect($project->fresh())->not->toBeNull()
        ->and($first->fresh())->not->toBeNull()
        ->and($second->fresh())->not->toBeNull();
});

it('preserves a one-off payment when a fiscal document blocks deleting its commission', function () {
    $income = app(RecordOneOffIncome::class)->handle([
        'received_on' => '2026-12-01',
        'title' => 'Plataforma',
        'gross_amount' => 10000,
        'commission' => 1000,
        'commission_supplier_id' => Supplier::factory()->create()->id,
    ]);
    $payment = Payment::query()->sole();
    $receivable = $payment->receivable;
    $commission = Expense::query()->sole();
    FiscalDocument::factory()->create()->links()->create(['expense_id' => $commission->id]);

    expect(fn () => $payment->delete())->toThrow(QueryException::class);

    expect($income->fresh())->not->toBeNull()
        ->and(Payment::find($payment->id))->not->toBeNull()
        ->and($receivable->fresh())->not->toBeNull()
        ->and($commission->fresh())->not->toBeNull();
});
