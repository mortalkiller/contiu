<?php

namespace Database\Seeders;

use App\Actions\SeedAccountDefaults;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * Local development only. Not WithoutModelEvents: business models take their account_id from
 * a "creating" listener (BelongsToAccount), so the base categories need model events.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(SeedAccountDefaults $seedDefaults): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('The demo administrator can only be seeded in local or testing.');
        }

        $superAdminRole = Role::firstOrCreate([
            'name' => config('filament-shield.super_admin.name', 'super_admin'),
            'guard_name' => 'web',
        ]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@pressiumize.test'],
            [
                'name' => 'Admin',
                'password' => config('contiu.demo_admin_password') ?: Str::password(32),
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole($superAdminRole);

        // An account to work in, exempt from billing so it never turns read-only.
        $account = Account::firstOrCreate(
            ['slug' => 'conta-de-exemplo'],
            ['name' => 'Conta de exemplo', 'billing_exempt' => true],
        );

        $account->users()->syncWithoutDetaching([$admin->getKey() => ['role' => 'owner']]);

        $seedDefaults->handle($account);

        // Finance data comes from Pedro's workbook, not from a seeder:
        // php artisan finance:import-excel /path/to/controlo_financeiro_receitas_despesas.xlsx --account=conta-de-exemplo --replace
        // (or Definições → Importar Excel in the panel).
    }
}
