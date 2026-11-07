<?php

use App\Filament\Resources\Clients\Pages\ClientStatementPage;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Mail\PaymentReminder;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    $this->user = actingAsAdmin();
    Mail::fake();

    $this->client = Client::factory()->create(['name' => 'Gonçalo Teixeira, Lda', 'email' => 'goncalo@example.pt']);
    $project = Project::factory()->for($this->client)->create(['code' => 'OC-2026-1', 'title' => 'Site']);

    $this->overdue = Receivable::factory()->for($project, 'billable')->create(['description' => 'Prestação 1/2', 'amount' => 50000, 'due_on' => '2026-10-01']);
    $this->upcoming = Receivable::factory()->for($project, 'billable')->create(['description' => 'Prestação 2/2', 'amount' => 30000, 'due_on' => '2026-12-15']);
    $paid = Receivable::factory()->for($project, 'billable')->create(['description' => 'Adjudicação', 'amount' => 10000, 'due_on' => '2026-09-01']);
    Payment::factory()->for($paid)->create(['amount' => 10000]);
});

it('lists the client open amounts, flags the overdue ones and signs off', function () {
    $mail = new PaymentReminder($this->client, subjectLine: 'Lembrete', body: 'Olá Gonçalo');

    expect($mail->items->pluck('description')->all())->toBe(['Prestação 1/2', 'Prestação 2/2'])
        ->and($mail->totalOpen)->toBe(80000)
        ->and($mail->totalOverdue)->toBe(50000);

    $mail->assertSeeInHtml('OC-2026-1')
        ->assertSeeInHtml('em atraso')
        ->assertSeeInHtml('01/10/2026')
        ->assertSeeInHtml('Olá Gonçalo')
        ->assertSeeInHtml(config('app.name'))
        ->assertDontSeeInHtml('Adjudicação');
});

it('sends the reviewed reminder to the edited address and logs it', function () {
    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->mountAction('sendPaymentReminder')
        ->assertActionDataSet(['to' => 'goncalo@example.pt', 'subject' => 'Lembrete de pagamento — Gonçalo Teixeira, Lda'])
        ->setActionData(['to' => 'contabilidade@example.pt', 'message' => 'Mensagem revista'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    Mail::assertSent(PaymentReminder::class, fn (PaymentReminder $mail) => $mail->hasTo('contabilidade@example.pt')
        && $mail->body === 'Mensagem revista'
        && $mail->items->count() === 2);

    expect(ClientMessage::sole())
        ->client_id->toBe($this->client->id)
        ->type->toBe('payment_reminder')
        ->to->toBe('contabilidade@example.pt')
        ->user_id->toBe($this->user->id)
        ->sent_at->not->toBeNull();
});

it('mentions the overdue amount in the suggested message', function () {
    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->mountAction('sendPaymentReminder')
        ->assertActionDataSet(fn (array $data) => str_contains($data['message'], '500,00'));
});

it('cannot remind a client without email', function () {
    $this->client->update(['email' => null]);

    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->assertActionDisabled('sendPaymentReminder')
        ->assertActionVisible('addClientEmail');

    Mail::assertNothingSent();
});

it('reminds the client from an open amount on the receivables list and shows when', function () {
    Livewire::test(ListReceivables::class)->loadTable()
        ->callTableAction('sendPaymentReminder', $this->overdue, data: ['to' => 'goncalo@example.pt'])
        ->assertHasNoTableActionErrors();

    Mail::assertSent(PaymentReminder::class, fn (PaymentReminder $mail) => $mail->highlightedId === $this->overdue->id);

    Livewire::test(ListReceivables::class)->loadTable()->assertSee('Lembrete enviado a 06/11');
});

it('offers the reminder on the statement page too', function () {
    Livewire::test(ClientStatementPage::class, ['record' => $this->client->getRouteKey()])
        ->callAction('sendPaymentReminder')
        ->assertHasNoActionErrors();

    Mail::assertSent(PaymentReminder::class, 1);
});

it('never sends emails on its own', function () {
    $this->travelTo(Carbon::parse('2026-12-01'));

    $this->artisan('finance:generate-recurring')->assertSuccessful();
    $this->artisan('finance:send-reminders')->assertSuccessful();

    Mail::assertNothingSent();
    expect(ClientMessage::count())->toBe(0);
});
