<?php

use App\Actions\ManageCustomerAdvance;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Account;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\ClientStatement;
use App\Support\CurrentAccount;
use App\Support\MonthReview;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('records an advance once as cash and applies it to a later charge on a separate date', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-19'));
    actingAsAdmin();
    $client = Client::factory()->create();
    Livewire::test(ManagePayments::class)->callAction('recordCustomerAdvance', data: [
        'client_id' => $client->id, 'paid_on' => '2027-01-31', 'method' => PaymentMethod::Transfer->value, 'amount' => '200,03',
    ])->assertHasNoActionErrors();
    $payment = Payment::query()->sole();

    expect($payment->amount)->toBe(20003)
        ->and($payment->receivable_id)->toBeNull()
        ->and($payment->allocations()->count())->toBe(0)
        ->and((new MonthReview(CarbonImmutable::parse('2027-01-31')))->received())->toBe(20003)
        ->and((new ClientStatement($client))->balance())->toBe(-20003);

    $charge = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 60000, 'due_on' => '2027-02-19']);
    Livewire::test(ManagePayments::class)->call('loadTable')
        ->callAction(TestAction::make('applyCustomerAdvance')->table($payment), ['receivable_id' => $charge->id, 'allocated_on' => '2027-02-08'])
        ->assertHasNoActionErrors();

    expect($charge->fresh()->outstanding)->toBe(39997)
        ->and($payment->allocations()->sole()->allocated_on->toDateString())->toBe('2027-02-08')
        ->and(Payment::query()->count())->toBe(1)
        ->and((new MonthReview(CarbonImmutable::parse('2027-02-19')))->received())->toBe(0)
        ->and((new ClientStatement($client))->balance())->toBe(39997)
        ->and(fn () => app(ManageCustomerAdvance::class)->apply($payment->id, $charge->id, '2027-02-08'))->toThrow(ValidationException::class);
})->group('ui');

it('refuses a different client or account and a date before the advance', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-19'));
    actingAsAdmin();
    $client = Client::factory()->create();
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => Receivable::factory()->create());
    $unrelated = Receivable::factory()->create();
    $same = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create();
    $service = app(ManageCustomerAdvance::class);
    $payment = $service->record($client->id, 20003, '2027-02-02', PaymentMethod::Transfer);

    expect(fn () => $service->apply($payment->id, $foreign->id, '2027-02-08'))->toThrow(ValidationException::class)
        ->and(fn () => $service->apply($payment->id, $unrelated->id, '2027-02-08'))->toThrow(ValidationException::class)
        ->and(fn () => $service->apply($payment->id, $same->id, '2027-02-01'))->toThrow(ValidationException::class)
        ->and($payment->allocations()->count())->toBe(0);
})->group('integration');
