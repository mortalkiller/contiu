<?php

use App\Enums\IntegrationRecordState;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\ManageArticles;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\Account;
use App\Models\Article;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\IntegrationRecord;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->group('invoicing', 'tenancy');

/*
 * The articles catalogue: what is billed often, kept per account, and never deleted from under a
 * document that uses it.
 */

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
});

/** A document line that took the article. */
function lineUsing(Article $article, ?FiscalDocument $document = null): FiscalDocumentLine
{
    return FiscalDocumentLine::factory()->for($document ?? FiscalDocument::factory()->create())->create(['article_id' => $article->id]);
}

it('opens under the fiscal documents trail, with the list beside how articles work', function () {
    invoicingConnection();
    Article::factory()->create(['name' => 'Hora de desenvolvimento', 'code' => 'DEV-H', 'unit' => 'h', 'unit_price' => 4500, 'vat_basis_points' => 2300]);

    Livewire::test(ManageArticles::class)
        ->assertSeeHtml(FiscalDocumentResource::getUrl())
        ->assertSeeInOrder(['Hora de desenvolvimento', 'DEV-H', __('Hour'), '45,00', '23%', __('Sent when issuing'), __('How they work'), 'InvoiceXpress'])
        ->assertActionExists('create');
})->group('ui');

it('creates an article from the modal, in cents and leaving the VAT to the document', function () {
    Livewire::test(ManageArticles::class)
        ->callAction('create', [
            'name' => 'Hora de desenvolvimento',
            'code' => 'DEV-H',
            'unit' => 'h',
            'unit_price' => '45.50',
        ])
        ->assertHasNoFormErrors();

    $article = Article::query()->sole();

    expect($article->only(['name', 'code', 'unit', 'unit_price', 'vat_basis_points', 'is_active', 'account_id']))->toBe([
        'name' => 'Hora de desenvolvimento',
        'code' => 'DEV-H',
        'unit' => 'h',
        'unit_price' => 4550,
        'vat_basis_points' => null,
        'is_active' => true,
        'account_id' => $this->account->id,
    ]);
});

it('keeps an exempt article apart from one whose VAT the document decides', function () {
    $article = Article::factory()->create(['vat_basis_points' => null]);

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->callAction(TestAction::make('edit')->table($article), ['vat_basis_points' => '0'])
        ->assertHasNoFormErrors();

    expect($article->fresh()->vat_basis_points)->toBe(0);

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->callAction(TestAction::make('edit')->table($article), ['vat_basis_points' => null])
        ->assertHasNoFormErrors();

    expect($article->fresh()->vat_basis_points)->toBeNull();
});

it('refuses a code the account already uses but accepts one used by another account', function () {
    $existing = Article::factory()->create(['code' => 'DEV-H']);
    CurrentAccount::run(Account::factory()->create(), fn () => Article::factory()->create(['code' => 'MAN-M']));

    $input = ['name' => 'Manutenção mensal', 'unit' => 'mês', 'unit_price' => '80'];

    Livewire::test(ManageArticles::class)
        ->callAction('create', [...$input, 'code' => 'DEV-H'])
        ->assertHasFormErrors(['code' => 'unique']);

    Livewire::test(ManageArticles::class)
        ->callAction('create', [...$input, 'code' => 'MAN-M'])
        ->assertHasNoFormErrors();

    // Saving an article again does not trip over its own code.
    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->callAction(TestAction::make('edit')->table($existing), ['name' => 'Hora de desenvolvimento'])
        ->assertHasNoFormErrors();

    expect(Article::query()->pluck('name', 'code')->all())->toBe(['DEV-H' => 'Hora de desenvolvimento', 'MAN-M' => 'Manutenção mensal']);
});

it('neither lists nor edits an article of another account', function () {
    $mine = Article::factory()->create(['name' => 'Alojamento anual']);
    $other = Account::factory()->create();
    $theirs = CurrentAccount::run($other, fn () => Article::factory()->create(['name' => 'Artigo alheio']));

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSee('Artigo alheio');

    expect(fn () => Livewire::test(ManageArticles::class)->loadTable()->callAction(TestAction::make('edit')->table($theirs), ['name' => 'Tomado']))
        ->toThrow(ActionNotResolvableException::class);

    expect(CurrentAccount::run($other, fn () => $theirs->fresh()->name))->toBe('Artigo alheio')
        ->and(ArticleResource::canEdit($theirs))->toBeFalse()
        ->and(ArticleResource::canDelete($theirs))->toBeFalse();
});

it('deletes an unused article from its edit form but not one used on a document line', function () {
    $used = Article::factory()->create();
    $unused = Article::factory()->create();
    lineUsing($used);

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->mountAction(TestAction::make('edit')->table($used))
        ->assertActionHidden(TestAction::make('delete')->table($used));

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->mountAction(TestAction::make('edit')->table($unused))
        ->assertActionVisible(TestAction::make('delete')->table($unused))
        ->callAction(TestAction::make('delete')->table($unused));

    expect(Article::query()->pluck('id')->all())->toBe([$used->id]);
});

it('shows how often each article was used and whether the provider has it, without a query per row', function () {
    $connection = invoicingConnection();
    $articles = Article::factory()->count(3)->create();
    $document = FiscalDocument::factory()->create();

    foreach ($articles as $index => $article) {
        for ($times = 0; $times < $index; $times++) {
            lineUsing($article, $document);
        }
    }

    IntegrationRecord::query()->forceCreate([
        'integration_connection_id' => $connection->id,
        'record_type' => $articles[1]->getMorphClass(),
        'record_id' => $articles[1]->id,
        'remote_type' => 'items',
        'remote_id' => '501',
        'state' => IntegrationRecordState::InSync,
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $screen = Livewire::test(ManageArticles::class)->assertCanSeeTableRecords($articles);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
    }

    $reading = fn (string $table) => $queries->filter(fn (string $query): bool => str_contains($query, "from \"{$table}\"") && ! str_contains($query, 'from "articles"'));

    // The uses and the provider's record ride on the articles query itself: one to count the list, one for its page.
    expect($reading('fiscal_document_lines'))->toBeEmpty()
        ->and($reading('integration_records'))->toBeEmpty()
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'from "articles"')))->toHaveCount(2);

    $screen
        ->assertTableColumnStateSet('lines_count', null, $articles[0])
        ->assertTableColumnStateSet('lines_count', 2, $articles[2])
        ->assertTableColumnStateSet('at_provider', __('Sent when issuing'), $articles[0])
        ->assertTableColumnStateSet('at_provider', __('In sync'), $articles[1]);
})->group('performance');

it('leaves out the provider column while no provider is connected', function () {
    Article::factory()->create();

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->assertTableColumnHidden('at_provider');
});

it('lets neither an accountant nor a read-only account create or edit', function () {
    $article = Article::factory()->create();
    $accountant = User::factory()->create(['email_verified_at' => now()]);
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);

    $this->actingAs($accountant);

    expect(ArticleResource::canCreate())->toBeFalse()
        ->and(ArticleResource::canEdit($article))->toBeFalse();
    Livewire::test(ManageArticles::class)->assertForbidden();

    $this->account->update(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    $this->actingAs(ownerOf($this->account));

    Livewire::test(ManageArticles::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$article])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($article));
});
