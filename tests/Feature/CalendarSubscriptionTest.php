<?php

use App\Filament\Pages\CalendarSubscriptionPage;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CalendarSubscriptions;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('discovers the owner settings page and protects it by account and membership', function () {
    $owner = ownerOf($this->account);
    $other = Account::factory()->create();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
    $path = "/{$this->account->slug}/integracoes/subscricao-calendario";

    asAWebRequest();
    $this->get($path)->assertRedirect(route('filament.admin.auth.login'));

    $response = $this->actingAs($owner)->get($path)->assertOk()->assertSeeLivewire(CalendarSubscriptionPage::class);
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshots = array_map(fn (string $snapshot): string => html_entity_decode($snapshot, ENT_QUOTES, 'UTF-8'), $matches[1]);
    $snapshot = collect($snapshots)->sole(fn (string $snapshot): bool => (json_decode($snapshot, true)['data']['accountId'] ?? null) === $this->account->id);
    $payload = [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'createSubscription', 'params' => []]],
        ]],
    ];

    asAWebRequest();
    $update = $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk();
    expect($update->json('components.0.effects.html'))->toMatch('~/api/calendario/[a-f0-9]{64}\.ics~');

    asAWebRequest();
    $this->get("/{$other->slug}/integracoes/subscricao-calendario")->assertNotFound();

    asAWebRequest();
    $this->actingAs($accountant)->get($path)->assertForbidden();

    asAWebRequest();
    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertForbidden();
});

it('creates a single protected link, copies the same link on reload and revokes it', function () {
    $owner = ownerOf($this->account);
    $this->actingAs($owner);
    Filament::setTenant($this->account);
    expect(CalendarSubscriptionPage::isConfigured())->toBeFalse();

    $page = Livewire::test(CalendarSubscriptionPage::class)->call('createSubscription')->assertStatus(200);
    preg_match('~/api/calendario/([a-f0-9]{64})\.ics~', $page->html(), $matches);
    expect($matches)->toHaveCount(2);
    [$path, $token] = $matches;

    expect(CalendarSubscriptionPage::isConfigured())->toBeTrue();
    Livewire::test(CalendarSubscriptionPage::class)
        ->assertSee($path)
        ->call('createSubscription')
        ->assertSee($path);

    $stored = DB::table('calendar_subscriptions')->where('account_id', $this->account->id)->sole();
    expect($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->encrypted_token)->not->toContain($token);

    CurrentAccount::run(Account::factory()->create(), fn () => expect(CalendarSubscriptionPage::isConfigured())->toBeFalse());

    auth()->forgetGuards();
    asAWebRequest();
    $this->get($path)->assertOk();
    $wrongToken = ($token[0] === '0' ? '1' : '0').substr($token, 1);
    $this->get("/api/calendario/{$wrongToken}.ics")->assertNotFound();

    $this->actingAs($owner);
    Filament::setTenant($this->account);
    Livewire::test(CalendarSubscriptionPage::class)->call('revokeSubscription')->assertDontSee($path);
    expect(CalendarSubscriptionPage::isConfigured())->toBeFalse();
    Livewire::test(CalendarSubscriptionPage::class)->call('createSubscription')->assertDontSee($path);

    auth()->forgetGuards();
    asAWebRequest();
    $this->get($path)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
});

it('rechecks owner access when Livewire actions run after a membership change', function () {
    $owner = ownerOf($this->account);
    $this->actingAs($owner);
    Filament::setTenant($this->account);
    $page = Livewire::test(CalendarSubscriptionPage::class)->call('createSubscription');

    $this->account->users()->updateExistingPivot($owner, ['role' => AccountantAccess::ROLE]);

    $page->call('revokeSubscription')->assertForbidden();
    expect(DB::table('calendar_subscriptions')->where('account_id', $this->account->id)->exists())->toBeTrue();
});

it('serves a valid calendar with no visible events for an account without deadlines', function () {
    $subscriptions = app(CalendarSubscriptions::class);
    $subscriptions->create();
    $url = $subscriptions->settings()['url'];

    asAWebRequest();
    $body = $this->get($url)->assertOk()->getContent();

    expect($body)->toContain(
        "BEGIN:VTIMEZONE\r\nTZID:UTC\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\n"
        ."TZOFFSETFROM:+0000\r\nTZOFFSETTO:+0000\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n",
    )->not->toContain('BEGIN:VEVENT');
});

it('serves a private all-day calendar to guests with stable event IDs and optional open receivables', function () {
    $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
    $this->account->forceFill(['created_at' => '2026-10-01', 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    $receivable = Receivable::factory()->create([
        'due_on' => '2026-12-24',
        'description' => 'Private client and project name',
        'amount' => 119876,
    ]);
    $paid = Receivable::factory()->create(['due_on' => '2026-12-25', 'amount' => 10000]);
    Payment::factory()->for($paid)->create(['amount' => 10000, 'paid_on' => '2026-11-11']);
    Expense::factory()->create(['spent_on' => '2026-12-26']);
    CurrentAccount::run(Account::factory()->create(), fn () => Receivable::factory()->create(['due_on' => '2026-12-27']));

    $owner = ownerOf($this->account);
    $owner->update(['language' => 'en-US']);
    $this->actingAs($owner);
    Filament::setTenant($this->account);
    $page = Livewire::test(CalendarSubscriptionPage::class)->call('createSubscription');
    preg_match('~/api/calendario/[a-f0-9]{64}\.ics~', $page->html(), $matches);
    expect($matches)->toHaveCount(1);
    [$path] = $matches;

    auth()->forgetGuards();
    asAWebRequest();
    $fiscal = $this->get($path)->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-RateLimit-Limit', '60')
        ->assertHeaderMissing('Set-Cookie')
        ->getContent();
    expect($fiscal)
        ->toContain("BEGIN:VCALENDAR\r\n")
        ->toContain("DTSTART;VALUE=DATE:20261031\r\nDTEND;VALUE=DATE:20261101\r\nSUMMARY:Quarterly Social Security return\r\n")
        ->toContain("DTSTART;VALUE=DATE:20261120\r\nDTEND;VALUE=DATE:20261121\r\nSUMMARY:Social Security\r\n")
        ->not->toContain('DTSTART;VALUE=DATE:20261224')
        ->not->toContain('DESCRIPTION:')
        ->not->toContain('URL:')
        ->not->toContain($this->account->name);

    $this->actingAs($owner);
    Filament::setTenant($this->account);
    Livewire::test(CalendarSubscriptionPage::class)->call('setIncludeReceivables', true)->assertSee($path);

    auth()->forgetGuards();
    asAWebRequest();
    $withReceivables = $this->get($path)->assertOk()->getContent();
    expect($withReceivables)
        ->toContain("DTSTART;VALUE=DATE:20261224\r\nDTEND;VALUE=DATE:20261225\r\nSUMMARY:Receivable due\r\n")
        ->not->toContain('DTSTART;VALUE=DATE:20261225')
        ->not->toContain('DTSTART;VALUE=DATE:20261226')
        ->not->toContain('DTSTART;VALUE=DATE:20261227')
        ->not->toContain($receivable->description)
        ->not->toContain('1,198.76');
    preg_match_all('/^UID:(.+)\r$/m', $fiscal, $fiscalUids);
    preg_match_all('/^UID:(.+)\r$/m', $withReceivables, $allUids);
    expect($fiscalUids[1])->not->toBeEmpty()
        ->and(array_diff($fiscalUids[1], $allUids[1]))->toBeEmpty()
        ->and($allUids[1])->toHaveCount(count($fiscalUids[1]) + 1);

    CurrentAccount::run($this->account, fn () => $receivable->update(['due_on' => '2026-12-23']));
    $owner->update(['language' => 'pt-PT']);
    $updated = $this->get($path)->assertOk()->getContent();
    preg_match_all('/^UID:(.+)\r$/m', $updated, $updatedUids);
    expect($updatedUids[1])->toEqualCanonicalizing($allUids[1])
        ->and($updated)->toContain('DTSTART;VALUE=DATE:20261223', 'SUMMARY:Segurança Social');
});

it('escapes translated text and folds calendar lines at UTF-8 character boundaries', function () {
    $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
    $this->account->forceFill(['created_at' => '2026-10-01', 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);
    $owner = ownerOf($this->account);
    $this->actingAs($owner);
    Filament::setTenant($this->account);
    $page = Livewire::test(CalendarSubscriptionPage::class)->call('createSubscription');
    preg_match('~/api/calendario/[a-f0-9]{64}\.ics~', $page->html(), $matches);
    expect($matches)->toHaveCount(1);

    app('translator')->load('*', '*', 'pt_PT');
    app('translator')->addLines(['*.Social Security' => str_repeat('á', 65).", ; \\ \r\nInjected"], 'pt_PT');

    auth()->forgetGuards();
    asAWebRequest();
    $body = $this->get($matches[0])->assertOk()->getContent();
    $unfolded = str_replace("\r\n ", '', $body);

    expect($body)->toContain("\r\n ")->toEndWith("END:VCALENDAR\r\n")
        ->and(str_replace("\r\n", '', $body))->not->toMatch('/[\r\n]/')
        ->and($unfolded)->toContain('SUMMARY:'.str_repeat('á', 65).'\, \; \\\\ \nInjected'."\r\n");

    foreach (explode("\r\n", $body) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
});
