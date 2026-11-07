<?php

use App\Exports\ClientStatementExport;
use App\Filament\Resources\Clients\Pages\ClientStatementPage;
use App\Mail\ClientStatementEmail;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\ClientStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    actingAsAdmin();

    $this->client = Client::factory()->create(['name' => 'Mariana Sousa', 'email' => 'mariana@example.pt']);
    $project = Project::factory()->for($this->client)->create(['code' => 'MINFRA', 'quoted_on' => '2026-01-01']);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['description' => 'Jan 2026', 'amount' => 2000, 'due_on' => '2026-01-01']);
    Payment::factory()->for($receivable)->create(['paid_on' => '2026-01-03', 'amount' => 2000]);
    Receivable::factory()->for($project, 'billable')->create(['description' => 'Dez 2026', 'amount' => 2000, 'due_on' => '2026-12-01']);
});

function readXlsx(string $binary): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $binary);

    return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false);
}

it('exports the statement rows with money as numbers', function () {
    $rows = readXlsx(Excel::raw(new ClientStatementExport(new ClientStatement($this->client)), ExcelWriter::XLSX));

    // Money columns are numeric cells in euros (read back as int|float), not formatted text.
    expect($rows[0])->toBe(['Data', 'Descrição', 'Débito', 'Crédito', 'Saldo', 'Nota'])
        ->and($rows[1])->toEqual(['01/01/2026', 'MINFRA · Jan 2026', 20, null, 20, null])
        ->and($rows[2][3])->toEqual(20)
        ->and($rows[2][4])->toEqual(0)
        ->and($rows[3])->toEqual(['01/12/2026', 'MINFRA · Dez 2026', 20, null, 20, 'a vencer'])
        ->and($rows[1][2])->toBeNumeric();
});

it('downloads the statement as Excel from the statement page', function () {
    Livewire::test(ClientStatementPage::class, ['record' => $this->client->getRouteKey()])
        ->callAction('exportExcel')
        ->assertFileDownloaded('extrato-mariana-sousa-2026-11-06.xlsx');
});

it('emails the statement with the Excel attached and logs it', function () {
    Mail::fake();

    Livewire::test(ClientStatementPage::class, ['record' => $this->client->getRouteKey()])
        ->callAction('sendStatement', data: ['to' => 'mariana@example.pt'])
        ->assertHasNoActionErrors();

    Mail::assertSent(ClientStatementEmail::class, function (ClientStatementEmail $mail) {
        $attachment = collect($mail->attachments())->sole();

        return $mail->hasTo('mariana@example.pt')
            && $attachment->as === 'extrato-mariana-sousa-2026-11-06.xlsx'
            // header + "Saldo anterior" (the page filters from 1 Jan by default) + 3 entries
            && count(readXlsx($attachment->attachWith(fn () => null, fn ($data) => $data()))) === 5;
    });

    expect(ClientMessage::sole()->type)->toBe('statement');
});
