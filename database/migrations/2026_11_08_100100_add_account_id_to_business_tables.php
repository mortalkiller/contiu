<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Every table of the books; each row belongs to exactly one account. */
    private const TABLES = [
        'clients', 'suppliers', 'categories', 'projects', 'retainers', 'receivables', 'payments',
        'expenses', 'recurring_expenses', 'one_off_incomes', 'bank_transactions',
        'bank_transaction_links', 'client_messages', 'settings',
    ];

    /** Uniques that become per account: [table => [old columns, new columns]]. */
    private const UNIQUES = [
        'categories' => [['type', 'name'], ['account_id', 'type', 'name']],
        'projects' => [['code'], ['account_id', 'code']],
        'retainers' => [['code'], ['account_id', 'code']],
        'one_off_incomes' => [['code'], ['account_id', 'code']],
        'settings' => [['key'], ['account_id', 'key']],
        'bank_transactions' => [['hash'], ['account_id', 'hash']],
    ];

    /**
     * The finance defaults as they were before accounts (FinanceSettings::DEFAULTS changes later;
     * the existing account must keep these). Stored as the Setting model stores them: JSON, null as NULL.
     */
    private const OLD_FINANCE_DEFAULTS = [
        'activity_started_on' => null,
        'irs_exempt' => true,
        'vat_exempt' => true,
        'vat_exemption_threshold' => 1500000,
        'ss_rate' => 0.214,
        'ss_relevant_income_share' => 0.70,
        'ss_first_year_exempt' => true,
        'payments_exempt_by_default' => true,
        'account_names' => null,
    ];

    /**
     * Columns are added nullable, existing rows go to one account, then account_id becomes
     * required (audits excepted: logins and console runs have no account).
     */
    public function up(): void
    {
        foreach ([...self::TABLES, 'audits'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('account_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $blueprint->index('account_id');
            });
        }

        $this->giveExistingDataToOneAccount();

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unsignedBigInteger('account_id')->nullable(false)->change();

                if ($unique = self::UNIQUES[$table] ?? null) {
                    $blueprint->dropUnique($unique[0]);
                    $blueprint->unique($unique[1]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([...self::TABLES, 'audits'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if ($unique = self::UNIQUES[$table] ?? null) {
                    $blueprint->dropUnique($unique[1]);
                    $blueprint->unique($unique[0]);
                }

                $blueprint->dropForeign(['account_id']);
                $blueprint->dropIndex(['account_id']);
                $blueprint->dropColumn('account_id');
            });
        }
    }

    /**
     * Pedro's books (the only ones so far) become his account: not billed, he is its owner, and
     * his email counts as verified so turning verification on cannot lock him out.
     * A fresh database gets no account.
     */
    private function giveExistingDataToOneAccount(): void
    {
        $hasData = DB::table('users')->exists()
            || collect([...self::TABLES, 'audits'])->contains(fn (string $table) => DB::table($table)->exists());

        if (! $hasData) {
            return;
        }

        $name = env('CONTIU_FIRST_ACCOUNT_NAME', 'Conta principal');
        $slug = Str::slug($name);

        // Rolling this migration back keeps the account; running it again reuses it.
        $accountId = DB::table('accounts')->where('slug', $slug)->value('id') ?? DB::table('accounts')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'billing_exempt' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([...self::TABLES, 'audits'] as $table) {
            DB::table($table)->whereNull('account_id')->update(['account_id' => $accountId]);
        }

        DB::table('users')->orderBy('id')->pluck('id')->each(fn (int $userId) => DB::table('account_user')->insertOrIgnore([
            'account_id' => $accountId,
            'user_id' => $userId,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        $existing = DB::table('settings')->pluck('key')->all();

        foreach (array_diff_key(self::OLD_FINANCE_DEFAULTS, array_flip($existing)) as $key => $value) {
            DB::table('settings')->insert([
                'account_id' => $accountId,
                'key' => $key,
                'value' => $value === null ? null : json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
