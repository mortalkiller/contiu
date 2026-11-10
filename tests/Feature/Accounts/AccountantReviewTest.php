<?php

use App\Actions\ManageFinanceReview;
use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Pages\AccountantAccessSettings;
use App\Filament\Pages\AccountantReview;
use App\Filament\Pages\AccountantReviewRecords;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FinanceReviewItem;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\ExpenseEvidence;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function reviewMember(Account $account, string $role): User
{
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => $role]);

    return $user;
}

function signInForReview(User $user, Account $account): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($account);
}

function reviewSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    return collect($matches[1])
        ->map(fn (string $snapshot) => html_entity_decode($snapshot, ENT_QUOTES))
        ->firstOrFail(fn (string $snapshot) => json_decode($snapshot, true)['memo']['name'] === $component);
}

function callReviewSnapshot(string $snapshot, string $method, array $params = [], array $updates = []): TestResponse
{
    asAWebRequest();
    Livewire::flushState();

    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]],
    ]);
}

it('opens the review for an accountant only in accounts that explicitly granted access', function () {
    $accountant = reviewMember($this->account, 'accountant');
    $other = Account::factory()->create();
    $expense = Expense::factory()->create();
    $this->actingAs($accountant);
    asAWebRequest();

    $this->get(Dashboard::getUrl(tenant: $this->account))->assertRedirect(AccountantReview::getUrl(tenant: $this->account));
    $this->get(AccountantReview::getUrl(tenant: $this->account))->assertOk()
        ->assertSee('Revisão contabilística')
        ->assertDontSee('fi-quick-create-btn', escape: false);
    $this->get(AccountantReview::getUrl(tenant: $other))->assertNotFound();
    $this->get(ExpenseResource::getUrl('edit', ['record' => $expense], tenant: $this->account))->assertForbidden();
    $this->get(AccountantAccessSettings::getUrl(tenant: $this->account))->assertForbidden();
    $this->get(FinanceSettingsPage::getUrl(tenant: $this->account))->assertForbidden();

    expect($accountant->can('update', $expense))->toBeFalse();
});

it('blocks stale finance actions after an account role changes to accountant', function () {
    $expense = Expense::factory()->create(['description' => 'Original']);
    $user = reviewMember($this->account, 'member');
    $this->actingAs($user);
    asAWebRequest();
    $page = $this->get(ExpenseResource::getUrl('edit', ['record' => $expense], tenant: $this->account))->assertOk();
    $snapshot = reviewSnapshot($page->getContent(), EditExpense::class);
    $this->account->users()->updateExistingPivot($user, ['role' => 'accountant']);

    callReviewSnapshot($snapshot, 'save')->assertForbidden();

    $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'description' => 'Original']);
});

it('lets the owner grant and revoke existing users without changing other roles or sending email', function () {
    Mail::fake();
    $owner = reviewMember($this->account, 'owner');
    $member = reviewMember($this->account, 'member');
    $accountant = User::factory()->create();
    signInForReview($owner, $this->account);

    Livewire::test(AccountantAccessSettings::class)->callAction('grant', ['email' => $member->email])->assertHasActionErrors(['email']);
    $settings = Livewire::test(AccountantAccessSettings::class);
    $settings->callAction('grant', ['email' => $accountant->email])->assertHasNoActionErrors();
    $this->assertDatabaseHas('account_user', ['account_id' => $this->account->id, 'user_id' => $accountant->id, 'role' => 'accountant']);

    $settings->callAction(TestAction::make('revoke')->table($accountant))->assertHasNoActionErrors();

    $this->assertDatabaseMissing('account_user', ['account_id' => $this->account->id, 'user_id' => $accountant->id]);
    $this->assertDatabaseHas('account_user', ['account_id' => $this->account->id, 'user_id' => $member->id, 'role' => 'member']);
    Mail::assertNothingOutgoing();
});

it('blocks both the old review URL and a pending Livewire submission after revocation', function () {
    $accountant = reviewMember($this->account, 'accountant');
    $expense = Expense::factory()->create();
    $this->actingAs($accountant);
    asAWebRequest();
    $url = AccountantReviewRecords::getUrl(['source' => 'expense'], tenant: $this->account);
    $snapshot = reviewSnapshot($this->get($url)->assertOk()->getContent(), AccountantReviewRecords::class);
    $response = callReviewSnapshot($snapshot, 'mountAction', ['requestEvidence', [], ['table' => true, 'recordKey' => (string) $expense->id]])
        ->assertOk();
    $mounted = json_decode($response->json('components.0.snapshot'), true);
    expect($mounted['data']['mountedActions'][0][0][0]['name'])->toBe('requestEvidence');
    $this->account->users()->detach($accountant);

    callReviewSnapshot($response->json('components.0.snapshot'), 'callMountedAction', updates: ['mountedActions.0.data.body' => 'Fatura em falta'])
        ->assertNotFound();
    asAWebRequest();
    $this->get($url)->assertNotFound();
    $this->assertDatabaseCount('finance_review_items', 0);
});

it('shows tenant records and permits comments and evidence requests while only members can resolve them', function () {
    Mail::fake();
    $accountant = reviewMember($this->account, 'accountant');
    $owner = reviewMember($this->account, 'owner');
    $expense = Expense::factory()->create(['description' => 'Material da conta', 'net_amount' => 12345]);
    $document = FiscalDocument::factory()->create(['number' => 'FT REVISÃO/1']);
    $otherExpense = CurrentAccount::run(Account::factory()->create(), fn () => Expense::factory()->create(['description' => 'Material de outra conta']));
    signInForReview($accountant, $this->account);

    Livewire::test(AccountantReviewRecords::class, ['source' => 'expense'])
        ->assertCanSeeTableRecords([$expense])
        ->assertCanNotSeeTableRecords([$otherExpense]);
    expect(fn () => app(ManageFinanceReview::class)->add(FinanceReviewTarget::Expense, $otherExpense->id, FinanceReviewKind::EvidenceRequest, 'Pedido indevido'))
        ->toThrow(ModelNotFoundException::class);

    Livewire::test(AccountantReviewRecords::class, ['source' => 'expense'])->callAction(TestAction::make('requestEvidence')->table($expense), ['body' => 'Podes juntar a fatura?'])->assertHasNoActionErrors();
    Livewire::test(AccountantReviewRecords::class, ['source' => 'fiscal_document'])
        ->callAction(TestAction::make('comment')->table($document), ['body' => 'Confirmar o período.'])->assertHasNoActionErrors();

    $this->assertDatabaseCount('finance_review_items', 2);
    $this->assertDatabaseHas('finance_review_items', ['account_id' => $this->account->id, 'expense_id' => $expense->id, 'kind' => 'evidence_request', 'author_id' => $accountant->id, 'resolved_at' => null]);
    $this->assertDatabaseHas('finance_review_items', ['fiscal_document_id' => $document->id, 'kind' => 'comment', 'body' => 'Confirmar o período.']);
    $requestId = FinanceReviewItem::query()->where('expense_id', $expense->id)->sole()->getKey();

    Livewire::test(AccountantReview::class)
        ->assertSee('Pendente')
        ->assertActionHidden(TestAction::make('resolveRequest')->table($requestId))
        ->call('mountAction', 'resolveRequest', [], ['table' => true, 'recordKey' => (string) $requestId]);
    $this->assertDatabaseHas('finance_review_items', ['id' => $requestId, 'resolved_at' => null]);

    signInForReview($owner, $this->account);
    Livewire::test(AccountantReview::class)
        ->callAction(TestAction::make('resolveRequest')->table($requestId))
        ->assertSee('Resolvido');

    $this->assertDatabaseHas('finance_review_items', ['id' => $requestId, 'resolved_by' => $owner->id]);
    $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'net_amount' => 12345, 'proof_path' => null]);
    Mail::assertNothingOutgoing();
});

it('allows the existing private proof download only for the granted account', function () {
    Storage::fake(ExpenseEvidence::DISK);
    $accountant = reviewMember($this->account, 'accountant');
    $expense = Expense::factory()->create();
    $path = "expense-proofs/{$this->account->id}/".Str::uuid().'.pdf';
    $expense->forceFill(['proof_path' => $path])->save();
    Storage::disk(ExpenseEvidence::DISK)->put($path, '%PDF');
    $other = Account::factory()->create();
    $otherExpense = CurrentAccount::run($other, fn () => Expense::factory()->create());
    $this->actingAs($accountant);
    asAWebRequest();

    $this->get(ExpenseResource::getUrl('download-proof', ['record' => $expense], tenant: $this->account))
        ->assertOk()->assertDownload("comprovativo-despesa-{$expense->id}.pdf");
    $this->get(ExpenseResource::getUrl('download-proof', ['record' => $otherExpense], tenant: $this->account))->assertNotFound();
    $this->get(ExpenseResource::getUrl('download-proof', ['record' => $expense], tenant: $other))->assertNotFound();
});

it('keeps the review role local to the granted account even when the user owns another account', function () {
    $user = reviewMember($this->account, 'accountant');
    $owned = Account::factory()->create();
    $owned->users()->attach($user, ['role' => 'owner']);
    $expense = Expense::factory()->create(['description' => 'Material para rever']);
    $otherExpense = CurrentAccount::run($owned, fn () => Expense::factory()->create());
    $this->actingAs($user);
    asAWebRequest();

    $this->get('/')->assertRedirect(Dashboard::getUrl(tenant: $owned));
    $url = fn (Expense $record): string => AccountantReviewRecords::getUrl([
        'source' => 'expense',
        'record' => $record->id,
    ], tenant: $this->account);
    $this->get($url($expense))->assertOk()->assertSee('Material para rever');
    $this->get($url($otherExpense))->assertNotFound();
    $this->get(ExpenseResource::getUrl('edit', ['record' => $otherExpense], tenant: $owned))->assertOk();

    CurrentAccount::run($this->account, fn () => expect(fn () => $expense->update(['description' => 'Alteração indevida']))
        ->toThrow(AccountIsReadOnly::class));
});

it('keeps existing reviews readable after expiry and allows the owner to revoke access', function () {
    $owner = reviewMember($this->account, 'owner');
    $accountant = reviewMember($this->account, 'accountant');
    $item = FinanceReviewItem::factory()->create(['body' => 'Revisão anterior']);
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    signInForReview($accountant, $this->account);

    Livewire::test(AccountantReview::class)->assertSee('Revisão anterior');
    Livewire::test(AccountantReviewRecords::class, ['source' => 'expense'])
        ->assertActionHidden(TestAction::make('comment')->table($item->expense_id));
    expect(fn () => app(ManageFinanceReview::class)->add(FinanceReviewTarget::Expense, $item->expense_id, FinanceReviewKind::Comment, 'Novo comentário'))
        ->toThrow(HttpException::class);

    signInForReview($owner, $this->account);
    Livewire::test(AccountantAccessSettings::class)
        ->assertActionHidden('grant')
        ->callAction(TestAction::make('revoke')->table($accountant));

    $this->assertDatabaseCount('finance_review_items', 1);
    $this->assertDatabaseMissing('account_user', ['account_id' => $this->account->id, 'user_id' => $accountant->id]);
});
