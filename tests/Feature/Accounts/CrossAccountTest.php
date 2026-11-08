<?php

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Livewire\GlobalSearch;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * What the panel offers across screens — global search, relationship selects, Excel exports —
 * only ever shows the account being viewed, even when another account has look-alike records.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-08'));
    $this->other = Account::factory()->create();

    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);

    $this->mine = Client::factory()->create(['name' => 'Acme Lisboa']);
    $this->theirs = CurrentAccount::run($this->other, fn () => Client::factory()->create(['name' => 'Acme Porto']));
});

it('finds only the current account\'s records in the global search', function () {
    Livewire::test(GlobalSearch::class)
        ->set('search', 'Acme')
        ->assertSee('Acme Lisboa')
        ->assertDontSee('Acme Porto');
});

it('offers only the account\'s own clients on the project form and refuses another account\'s', function () {
    $project = Project::factory()->for($this->mine)->create();

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldExists('client_id', function (Select $field): bool {
            return array_keys($field->getOptions()) === [$this->mine->id]
                && array_keys($field->getSearchResults('Acme')) === [$this->mine->id];
        })
        ->fillForm(['client_id' => $this->theirs->id])
        ->call('save')
        ->assertHasFormErrors(['client_id']);

    expect($project->fresh()->client_id)->toBe($this->mine->id);
});

it('exports to Excel only the account\'s own records', function () {
    $component = Livewire::test(ListClients::class)->loadTable()
        ->callTableAction('export')
        ->assertFileDownloaded('clientes-2026-11-08.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, base64_decode($component->effects['download']['content']));
    $cells = collect(IOFactory::load($path)->getActiveSheet()->toArray())->flatten()->filter()->implode(' | ');
    unlink($path);

    expect($cells)->toContain('Acme Lisboa')->not->toContain('Acme Porto');
});
