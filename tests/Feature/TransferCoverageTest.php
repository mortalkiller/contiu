<?php

use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\TransferCoverage;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-20')));

function coverLine(string $date, int $amount, int $balance, string $status = 'own_transfer'): BankTransaction
{
    static $n = 0;

    return BankTransaction::create(['hash' => 'tc'.(++$n), 'booked_on' => $date, 'description' => 'TRF', 'amount' => $amount, 'balance' => $balance, 'status' => $status, 'import_batch' => 'b']);
}

function receipt(string $code, int $amount, string $date): Payment
{
    $receivable = Receivable::factory()->for(Project::factory()->create(['code' => $code]), 'billable')->create(['amount' => $amount, 'description' => 'Pagamento único']);

    return Payment::factory()->for($receivable)->create(['amount' => $amount, 'paid_on' => $date]);
}

it('says which payments a transfer covers, oldest first, the last one maybe in part', function () {
    coverLine('2026-11-01', 0, 0, 'matched'); // account opened at zero
    $first = receipt('OC-1', 30000, '2026-11-02');
    $second = receipt('OC-2', 40000, '2026-11-03');
    $transfer = coverLine('2026-11-05', 50000, 50000);

    $covers = (new TransferCoverage)->for($transfer);

    expect($covers)->toHaveCount(2)
        ->and($covers[0]->record->is($first))->toBeTrue()
        ->and($covers[0]->amount)->toBe(30000)
        ->and($covers[1]->record->is($second))->toBeTrue()
        ->and($covers[1]->amount)->toBe(20000)
        ->and($covers[1]->label())->toContain('OC-2')->toContain('em parte');
});

it('continues where the previous transfer stopped', function () {
    coverLine('2026-11-01', 0, 0, 'matched');
    receipt('OC-1', 30000, '2026-11-02');
    $second = receipt('OC-2', 40000, '2026-11-03');
    coverLine('2026-11-05', 30000, 30000);
    $later = coverLine('2026-11-10', 40000, 70000);

    $covers = (new TransferCoverage)->for($later);

    expect($covers)->toHaveCount(1)
        ->and($covers[0]->record->is($second))->toBeTrue()
        ->and($covers[0]->amount)->toBe(40000);
});

it('nets an expense paid outside the company account into the transfer', function () {
    coverLine('2026-11-01', 0, 0, 'matched');
    $payment = receipt('OC-1', 73000, '2026-11-02');
    $chatGpt = Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-03', 'spent_on' => '2026-11-03', 'description' => 'ChatGPT Pro']);
    $transfer = coverLine('2026-11-05', 64626, 64626);

    $covers = (new TransferCoverage)->for($transfer);

    expect(collect($covers)->map(fn ($cover) => $cover->record->getKey().':'.$cover->amount)->all())
        ->toBe([$payment->getKey().':73000', $chatGpt->getKey().':8374'])
        ->and($covers[1]->label())->toContain('ChatGPT Pro')->toContain('descontada');
});

it('ignores payments already linked to their own statement line and ones newer than the transfer', function () {
    $line = coverLine('2026-11-01', 10000, 10000, 'matched');
    $linked = receipt('OC-1', 10000, '2026-11-01');
    $line->links()->create(['linkable_type' => $linked->getMorphClass(), 'linkable_id' => $linked->id]);
    $newer = receipt('OC-2', 5000, '2026-11-08');
    $transfer = coverLine('2026-11-05', 5000, 15000);

    expect((new TransferCoverage)->for($transfer))->toBe([]);
});
