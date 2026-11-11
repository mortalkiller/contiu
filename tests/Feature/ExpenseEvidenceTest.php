<?php

use App\Enums\ExpenseAttribution;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

pest()->group('ui');

function expenseEvidencePdf(string $body = 'Comprovativo'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n{$body}\n%%EOF");
}

function expenseEvidenceUrl(Expense $expense, Account $account): string
{
    return ExpenseResource::getUrl(tenant: $account).'/'.$expense->getRouteKey().'/comprovativo';
}

function expenseEvidenceFixture(): Expense
{
    $expense = Expense::factory()->create();
    $expense->proof_path = 'expense-proofs/'.$expense->account_id.'/'.Str::uuid().'.pdf';
    $expense->save();
    Storage::disk('local')->put($expense->proof_path, '%PDF-1.4');

    return $expense;
}

it('stores a private PDF and records attribution and review independently of payment', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $supplier = Supplier::factory()->create();

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'description' => 'Internet',
            'spent_on' => today()->toDateString(),
            'net_amount' => '12.34',
            'vat_rate' => '0.0000',
            'is_paid' => false,
            'invoice_number' => 'FT 11',
            'proof_reference' => 'Arquivo 5',
            'attribution' => 'mixed',
            'is_reviewed' => true,
        ])
        ->set('data.proof_upload', [expenseEvidencePdf()])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::sole();
    expect($expense->proof_path)->toStartWith('expense-proofs/'.$this->account->id.'/')
        ->and($disk->visibility($expense->proof_path))->toBe('private')
        ->and($disk->get($expense->proof_path))->toContain('Comprovativo')
        ->and($expense->invoice_number)->toBe('FT 11')
        ->and($expense->proof_reference)->toBe('Arquivo 5')
        ->and($expense->attribution)->toBe(ExpenseAttribution::Mixed)
        ->and($expense->is_reviewed)->toBeTrue()
        ->and($expense->is_paid)->toBeFalse()
        ->and($expense->net_amount)->toBe(1234);
});

it('rejects an existing object path supplied to the upload field', function () {
    actingAsAdmin();
    Storage::fake('local');
    $expense = Expense::factory()->create();
    $other = Account::factory()->create();
    $foreign = CurrentAccount::run($other, fn () => expenseEvidenceFixture());

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->set('data.proof_upload', [$foreign->proof_path])
        ->call('save')
        ->assertHasFormErrors(['proof_upload']);

    expect($expense->fresh()->proof_path)->toBeNull();
});

it('rejects a non-PDF upload with a PDF filename', function () {
    actingAsAdmin();
    Storage::fake('local');
    $expense = Expense::factory()->create();

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->set('data.proof_upload', [UploadedFile::fake()->createWithContent('fatura.pdf', 'texto')->mimeType('text/plain')])
        ->call('save')
        ->assertHasFormErrors(['proof_upload']);

    expect($expense->fresh()->proof_path)->toBeNull();
});

it('keeps the PDF on an ordinary edit and replaces or removes it only when asked', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $expense = expenseEvidenceFixture();
    $oldPath = $expense->proof_path;

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['description' => 'Corrigida', 'attribution' => 'professional'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($expense->refresh()->proof_path)->toBe($oldPath)
        ->and($expense->attribution)->toBe(ExpenseAttribution::Professional);

    $editor = Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->set('data.proof_upload', [expenseEvidencePdf('Substituição')])
        ->call('save', false)
        ->assertHasNoFormErrors();

    $newPath = $expense->refresh()->proof_path;
    expect($newPath)->not->toBe($oldPath)
        ->and($disk->get($newPath))->toContain('Substituição');
    $disk->assertMissing($oldPath);

    $editor->fillForm(['description' => 'Segunda correção'])
        ->call('save', false)
        ->assertHasNoFormErrors();
    expect($expense->refresh()->proof_path)->toBe($newPath);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['remove_proof' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($expense->refresh()->proof_path)->toBeNull();
    $disk->assertMissing($newPath);
});

it('keeps the current proof when storage cannot write its replacement', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $expense = expenseEvidenceFixture();
    $path = $expense->proof_path;
    $blockedDirectory = dirname($path);
    $disk->move($path, 'saved-proof.pdf');
    $disk->deleteDirectory($blockedDirectory);
    $disk->put($blockedDirectory, 'not a directory');

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['description' => 'Corrigida'])
        ->set('data.proof_upload', [expenseEvidencePdf()])
        ->call('save')
        ->assertHasFormErrors(['proof_upload']);

    expect($expense->refresh()->proof_path)->toBe($path)
        ->and($expense->description)->toBe('Despesa');
    $disk->assertExists('saved-proof.pdf');
});

it('downloads a proof as an attachment for an authorized read-only owner', function () {
    Storage::fake('local');
    $expense = expenseEvidenceFixture();
    $this->account->update(['trial_ends_at' => today()->subDay()]);
    $this->actingAs(ownerOf($this->account));
    asAWebRequest();

    $response = $this->get(expenseEvidenceUrl($expense, $this->account))
        ->assertOk()
        ->assertDownload('comprovativo-despesa-'.$expense->id.'.pdf');
    expect($response->streamedContent())->toBe('%PDF-1.4');
});

it('requires a signed-in user with a verified email to download a proof', function () {
    Storage::fake('local');
    $expense = expenseEvidenceFixture();
    $user = User::factory()->unverified()->create();
    $this->account->users()->attach($user, ['role' => 'owner']);
    asAWebRequest();
    $url = expenseEvidenceUrl($expense, $this->account);

    $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs($user)->get($url)->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
});

it('keeps expense evidence read-only after the account trial ends', function () {
    Storage::fake('local');
    $expense = expenseEvidenceFixture();
    $this->account->update(['trial_ends_at' => today()->subDay()]);
    $user = User::factory()->create();
    $this->account->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    asAWebRequest();

    $this->get(ExpenseResource::getUrl('edit', ['record' => $expense], tenant: $this->account))->assertForbidden();
    $this->get(ExpenseResource::getUrl('create', tenant: $this->account))->assertForbidden();
});

it('leaves the proof on the original expense when duplicating it', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $expense = expenseEvidenceFixture();
    $expense->update(['proof_reference' => 'Arquivo 5', 'is_reviewed' => true]);

    Livewire::test(ListExpenses::class)
        ->loadTable()
        ->callAction(TestAction::make('replicate')->table($expense));

    $copy = Expense::query()->whereKeyNot($expense->id)->sole();
    expect($copy->proof_path)->toBeNull()
        ->and($copy->proof_reference)->toBeNull()
        ->and($copy->is_reviewed)->toBeFalse();
    $disk->assertExists($expense->proof_path);
});

it('removes the file when the expense is deleted individually', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $expense = expenseEvidenceFixture();

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->callAction('delete');

    expect(Expense::find($expense->id))->toBeNull();
    $disk->assertMissing($expense->proof_path);
});

it('refuses both an expense outside the route account and an inconsistent proof path', function () {
    Storage::fake('local');
    $expense = Expense::factory()->create();
    $other = Account::factory()->create();
    $foreign = CurrentAccount::run($other, fn () => expenseEvidenceFixture());
    $expense->proof_path = $foreign->proof_path;
    $expense->save();
    $this->actingAs(ownerOf($this->account));
    asAWebRequest();

    $this->get(expenseEvidenceUrl($foreign, $this->account))->assertNotFound();
    $this->get(expenseEvidenceUrl($foreign, $other))->assertNotFound();
    $this->get(expenseEvidenceUrl($expense, $this->account))->assertNotFound();
});
