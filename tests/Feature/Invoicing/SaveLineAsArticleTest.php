<?php

use App\Filament\Resources\FiscalDocuments\Pages\ComposeFiscalDocument;
use App\Models\Account;
use App\Models\Article;
use App\Support\CurrentAccount;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('ui', 'invoicing');

/*
 * "Save as article" on a line being written: what the Articles screen promises when it is empty.
 * The line becomes an article of the account and the line is tied to it.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    invoicingConnection();
    fakeInvoiceXpress();
    $this->undoRepeaterFake = Repeater::fake();
    $this->line = ['description' => 'Hora de desenvolvimento', 'quantity' => '2', 'unit' => 'h', 'unit_price' => '45.50', 'discount' => '0', 'vat_basis_points' => '2300'];
    $this->saveAsArticle = TestAction::make('saveAsArticle')->schemaComponent('lines')->arguments(['item' => 0]);
});

afterEach(fn () => ($this->undoRepeaterFake)());

it('saves a free line as an article of the account and ties the line to it', function () {
    $screen = Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
        ->fillForm(['lines' => [$this->line]])
        ->assertActionVisible($this->saveAsArticle)
        ->callAction($this->saveAsArticle)
        ->assertNotified(__('Saved as article'));

    $article = Article::query()->sole();

    expect($article->only(['code', 'name', 'unit', 'unit_price', 'vat_basis_points', 'is_active', 'account_id']))->toBe([
        'code' => 'ART-0001',
        'name' => 'Hora de desenvolvimento',
        'unit' => 'h',
        'unit_price' => 4550,
        'vat_basis_points' => 2300,
        'is_active' => true,
        'account_id' => $this->account->id,
    ]);

    $screen->assertSchemaStateSet(['lines.0.article_id' => $article->id], 'form')
        ->assertActionHidden($this->saveAsArticle);
});

it('is not offered on a line already taken from an article', function () {
    $article = Article::factory()->create(['name' => 'Hora de desenvolvimento']);

    Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
        ->fillForm(['lines' => [[...$this->line, 'article_id' => $article->id]]])
        ->assertActionHidden($this->saveAsArticle);
});

it('refuses a name the account already has, but not one of another account', function () {
    Article::factory()->create(['name' => 'Hora de desenvolvimento', 'code' => 'DEV-H']);
    CurrentAccount::run(Account::factory()->create(), fn () => Article::factory()->create(['name' => 'Alojamento anual']));

    Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
        ->fillForm(['lines' => [$this->line]])
        ->callAction($this->saveAsArticle)
        ->assertNotified(__('You already have an article with this name.'))
        ->assertSchemaStateSet(['lines.0.article_id' => null], 'form');

    Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
        ->fillForm(['lines' => [[...$this->line, 'description' => 'Alojamento anual']]])
        ->callAction($this->saveAsArticle)
        ->assertNotified(__('Saved as article'));

    expect(Article::query()->orderBy('id')->pluck('code', 'name')->all())
        ->toBe(['Hora de desenvolvimento' => 'DEV-H', 'Alojamento anual' => 'ART-0002']);
});

it('does nothing for a line without a description', function () {
    Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
        ->fillForm(['lines' => [[...$this->line, 'description' => '']]])
        ->callAction($this->saveAsArticle)
        ->assertHasFormErrors(['lines.0.description' => 'required']);

    expect(Article::query()->exists())->toBeFalse();
});
