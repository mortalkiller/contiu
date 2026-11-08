<?php

use App\Exceptions\NoCurrentAccount;
use App\Models\Account;
use App\Models\Concerns\BelongsToAccount;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class ScopeProbe extends Model
{
    use BelongsToAccount;

    protected $table = 'scope_probes';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('scope_probes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('account_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });
});

it('scopes queries to the current account', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();

    CurrentAccount::run($a, fn () => ScopeProbe::create(['name' => 'A']));
    CurrentAccount::run($b, fn () => ScopeProbe::create(['name' => 'B']));

    $names = CurrentAccount::run($a, fn () => ScopeProbe::query()->pluck('name')->all());

    expect($names)->toBe(['A']);
});

it('fills account_id when creating', function () {
    $a = Account::factory()->create();

    $probe = CurrentAccount::run($a, fn () => ScopeProbe::create(['name' => 'A']));

    expect($probe->account_id)->toBe($a->id)
        ->and($probe->account->is($a))->toBeTrue();
});

it('throws when there is no current account', function () {
    expect(fn () => ScopeProbe::query()->get())->toThrow(NoCurrentAccount::class);
});

it('restores the previous account after run, even on exception', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();

    CurrentAccount::set($a);

    expect(fn () => CurrentAccount::run($b, function () use ($b) {
        expect(CurrentAccount::get()->is($b))->toBeTrue();

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(CurrentAccount::get()->is($a))->toBeTrue();
});

it('falls back to the Filament tenant', function () {
    $b = Account::factory()->create();

    // Filament dispatches TenantSet with the authenticated user.
    $this->actingAs(User::factory()->create());
    Filament::setTenant($b);

    expect(CurrentAccount::get()->is($b))->toBeTrue()
        ->and(CurrentAccount::id())->toBe($b->id);
});

it('lists the owner and users of an account', function () {
    $account = Account::factory()->create();
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $account->users()->attach($member->id, ['role' => 'member']);
    $account->users()->attach($owner->id, ['role' => 'owner']);

    expect($account->users)->toHaveCount(2)
        ->and($account->owner()->is($owner))->toBeTrue();
});
