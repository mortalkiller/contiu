<?php

use App\Exports\ClientStatementExport;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Widgets\CashFlowChart;
use App\Mail\ClientStatementEmail;
use App\Mail\PaymentReminder;
use App\Models\Client;
use App\Support\ClientStatement;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Mortalkiller\FilamentCompleteUserProfile\Contracts\ProfileStorage;

pest()->group('ui');

it('saves a regional language on the user profile', function () {
    $user = actingAsAdmin();

    Livewire::test(Filament::getProfilePage(), ['section' => 'profile'])
        ->assertSet('data.language', 'pt-PT')
        ->set('data.language', 'en')
        ->call('save')
        ->assertHasErrors('data.language')
        ->set('data.language', 'en-US')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(Filament::getProfileUrl(['section' => 'profile']));

    expect($user->fresh()->language)->toBe('en-US')
        ->and($user->fresh()->preferredLocale())->toBe('en_US');
});

it('uses the regional language even when the profile package has an older locale saved', function () {
    $user = actingAsAdmin();
    $user->update(['language' => 'en-US']);
    app(ProfileStorage::class)->putMany($user, ['locale' => 'pt']);

    $this->get(Filament::getProfileUrl(['section' => 'profile']))
        ->assertOk()
        ->assertSee('lang="en-US"', escape: false);
    expect(app()->getLocale())->toBe('pt_PT');
});

it('uses the client language for the email and statement export independently of the operator', function () {
    actingAsAdmin();
    app()->setLocale('pt_PT');

    $client = Client::factory()->create(['name' => 'Sample Customer']);
    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertSet('data.language', 'pt-PT')
        ->set('data.language', 'en-US')
        ->call('save')
        ->assertHasNoErrors();
    $client->refresh();
    $statement = new ClientStatement($client);
    $reminder = new PaymentReminder($client, 'Payment reminder', 'Reviewed body');
    $statementEmail = new ClientStatementEmail($statement, 'Account statement', 'Reviewed body');

    expect(SendPaymentReminderAction::defaultMessage($client))->toContain('Hello Sample Customer')
        ->and($reminder->render())->toContain('Total outstanding', 'Reviewed body')
        ->and($statementEmail->render())->toContain('Balance', 'Reviewed body')
        ->and((new ClientStatementExport($statement))->headings())->toBe(['Date', 'Description', 'Debit', 'Credit', 'Balance', 'Note'])
        ->and(ClientStatementExport::filename($statement))->toStartWith('statement-sample-customer-')
        ->and(app()->getLocale())->toBe('pt_PT');
});

it('applies the saved user language during a web request and keeps the default for guests', function () {
    Route::middleware('web')->get('/test-language-preference', fn (): array => [
        'locale' => app()->getLocale(),
        'label' => __('Language'),
    ]);

    $this->get('/test-language-preference')->assertExactJson(['locale' => 'pt_PT', 'label' => 'Idioma']);

    $user = actingAsAdmin();
    $user->update(['language' => 'en-US']);
    $this->get('/test-language-preference')->assertExactJson(['locale' => 'en_US', 'label' => 'Language']);
    expect(app()->getLocale())->toBe('pt_PT');
});

it('shows the translated dashboard and navigation when the user selects English', function () {
    actingAsAdmin()->update(['language' => 'en-US']);

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('Today')
        ->assertSee('Revenue')
        ->assertSee('Expenses')
        ->assertSee('New expense')
        ->assertDontSee('Nova despesa')
        ->assertDontSee('Centro de ajuda');
});

it('translates Laravel validation messages and uses the field label', function () {
    $rule = fn (): string => Validator::make([], ['name' => 'required'], attributes: ['name' => __('Name')])
        ->errors()
        ->first('name');

    expect($rule())->toBe('O campo Nome é obrigatório.');

    app()->setLocale('en_US');

    expect($rule())->toBe('The Name field is required.');
});

it('renders the main resource screens in English', function (string $resource, string $heading) {
    actingAsAdmin()->update(['language' => 'en-US']);

    $response = $this->get($resource::getUrl());
    expect($response->status())->toBe(200);
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $title = $document->querySelector('main h1');

    expect($title)->not->toBeNull()
        ->and(trim($title->textContent))->toBe($heading);
})->with([
    [ClientResource::class, 'Clients'],
    [SupplierResource::class, 'Suppliers'],
    [ProjectResource::class, 'Projects'],
    [RetainerResource::class, 'Retainers'],
    [ReceivableResource::class, 'Receivables'],
    [PaymentResource::class, 'Payments'],
    [ExpenseResource::class, 'Expenses'],
    [RecurringExpenseResource::class, 'Recurring Expenses'],
    [FiscalDocumentResource::class, 'Fiscal Documents'],
    [CategoryResource::class, 'Categories'],
]);

it('keeps the localized chart options inside their complete Alpine attribute', function () {
    actingAsAdmin();
    app()->setLocale('en_US');

    $html = Livewire::test(CashFlowChart::class)->html();
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $chart = (new DOMXPath($document))->query('//*[contains(@x-data, "chart(")]')->item(0);
    $options = $chart?->getAttribute('x-data') ?? '';

    expect($options)->toContain("Intl.NumberFormat('en-US'", "type: 'bar'");
});
