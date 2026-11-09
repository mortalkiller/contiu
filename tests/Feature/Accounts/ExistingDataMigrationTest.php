<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The database as it was before accounts: the migration rolled back and no account left. */
function beforeAccounts(): object
{
    $migration = require database_path('migrations/2026_11_08_100100_add_account_id_to_business_tables.php');
    $migration->down();
    DB::table('account_user')->delete();
    DB::table('accounts')->delete();

    return $migration;
}

it('gives the existing data to one billing-exempt account', function () {
    $migration = beforeAccounts();
    $userId = DB::table('users')->insertGetId(['name' => 'Pedro', 'email' => 'pedro@example.pt', 'password' => 'x', 'email_verified_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    $clientId = DB::table('clients')->insertGetId(['name' => 'PRINTEE', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('settings')->insert(['key' => 'irs_exempt', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    $account = DB::table('accounts')->sole();

    expect($account->name)->toBe('Conta principal')
        ->and($account->slug)->toBe('conta-principal')
        ->and((bool) $account->billing_exempt)->toBeTrue()
        ->and(DB::table('clients')->where('id', $clientId)->value('account_id'))->toBe($account->id)
        ->and(DB::table('account_user')->where('user_id', $userId)->value('role'))->toBe('owner')
        ->and(DB::table('account_user')->where('user_id', $userId)->value('account_id'))->toBe($account->id)
        ->and(DB::table('users')->where('id', $userId)->value('email_verified_at'))->not->toBeNull();
});

it('writes the old finance defaults the account had not changed', function () {
    $migration = beforeAccounts();
    DB::table('users')->insert(['name' => 'Pedro', 'email' => 'pedro@example.pt', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('settings')->insert(['key' => 'irs_exempt', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    $settings = DB::table('settings')->orderBy('id')->pluck('value', 'key')->all();

    expect(DB::table('settings')->whereNull('account_id')->count())->toBe(0)
        ->and($settings)->toBe([
            'irs_exempt' => 'false', // Pedro's own value stays
            'activity_started_on' => null,
            'vat_exempt' => 'true',
            'vat_exemption_threshold' => '1500000',
            'ss_rate' => '0.214',
            'ss_relevant_income_share' => '0.7',
            'ss_first_year_exempt' => 'true',
            'payments_exempt_by_default' => 'true',
            'account_names' => null,
        ]);
});

it('reuses the account when run again after a rollback', function () {
    $migration = beforeAccounts();
    DB::table('users')->insert(['name' => 'Pedro', 'email' => 'pedro@example.pt', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();

    $migration->down();
    $migration->up();

    expect(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('account_user')->count())->toBe(1)
        ->and(DB::table('settings')->count())->toBe(9);
});

it('creates no account on a fresh database', function () {
    $migration = beforeAccounts();

    $migration->up();

    expect(DB::table('accounts')->count())->toBe(0)
        ->and(DB::table('settings')->count())->toBe(0);
});

it('makes account_id required on business tables and optional on audits', function () {
    $columns = fn (string $table) => collect(Schema::getColumns($table))->keyBy('name');

    foreach (['clients', 'suppliers', 'categories', 'projects', 'retainers', 'receivables', 'payments', 'expenses', 'recurring_expenses', 'one_off_incomes', 'bank_transactions', 'bank_transaction_links', 'client_messages', 'settings'] as $table) {
        expect($columns($table)['account_id']['nullable'] ?? null)->toBeFalse("{$table}.account_id");
    }

    expect($columns('audits')['account_id']['nullable'])->toBeTrue();
});

it('drops the old exemption settings', function () {
    $now = now();
    DB::table('settings')->insert([
        ['account_id' => test()->account->id, 'key' => 'irs_exempt', 'value' => 'true', 'created_at' => $now, 'updated_at' => $now],
        ['account_id' => test()->account->id, 'key' => 'ss_first_year_exempt', 'value' => 'true', 'created_at' => $now, 'updated_at' => $now],
        ['account_id' => test()->account->id, 'key' => 'vat_exempt', 'value' => 'true', 'created_at' => $now, 'updated_at' => $now],
    ]);

    (require database_path('migrations/2026_11_09_100000_add_tax_fields.php'))->up();

    expect(DB::table('settings')->pluck('key')->all())->toBe(['vat_exempt']);
});
