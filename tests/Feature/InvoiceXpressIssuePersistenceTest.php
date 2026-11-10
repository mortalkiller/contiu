<?php

use App\Models\Account;
use App\Models\InvoiceXpressIssueAttempt;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssueRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('reserves exactly one provider key for an account and business intent', function () {
    $user = actingAsAdmin();
    $repository = app(InvoiceXpressIssueRepository::class);
    $snapshot = ['invoice' => ['date' => '10/11/2026', 'client' => ['id' => 42]]];
    $target = hash('sha256', 'pilot');

    $first = $repository->reserve('receivable:42', $target, $snapshot, $user->id);
    $retry = $repository->reserve('receivable:42', $target, $snapshot, $user->id);

    expect($retry->id)->toBe($first->id)
        ->and($retry->proprietary_uid)->toBe($first->proprietary_uid)
        ->and($retry->provider_invoice_id)->toBeNull()
        ->and($retry->document_reference)->toBeNull();
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
    expect(DB::table('invoice_xpress_issue_attempts')->value('snapshot'))->not->toContain('10/11/2026');
});

it('blocks a changed snapshot or provider account for an already reserved intent', function () {
    $user = actingAsAdmin();
    $repository = app(InvoiceXpressIssueRepository::class);
    $snapshot = ['invoice' => ['date' => '10/11/2026']];
    $target = hash('sha256', 'pilot');

    $repository->reserve('receivable:42', $target, $snapshot, $user->id);

    expect(fn () => $repository->reserve('receivable:42', $target, ['invoice' => ['date' => '11/11/2026']], $user->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $repository->reserve('receivable:42', hash('sha256', 'other'), $snapshot, $user->id))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
});

it('does not resolve a reserved intent from another tenant', function () {
    $user = actingAsAdmin();
    $repository = app(InvoiceXpressIssueRepository::class);
    $attempt = $repository->reserve('receivable:42', hash('sha256', 'pilot'), ['invoice' => []], $user->id);

    $other = Account::factory()->create();
    $other->users()->attach($user, ['role' => 'owner']);
    expect(fn () => CurrentAccount::run($other, fn () => $repository->locked(
        $attempt->id,
        fn (InvoiceXpressIssueAttempt $foreign): InvoiceXpressIssueAttempt => $foreign,
    )))->toThrow(ModelNotFoundException::class);
});
