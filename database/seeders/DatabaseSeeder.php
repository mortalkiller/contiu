<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $superAdminRole = Role::firstOrCreate([
            'name' => config('filament-shield.super_admin.name', 'super_admin'),
            'guard_name' => 'web',
        ]);

        // Local development admin (password: "password").
        $admin = User::firstOrCreate(
            ['email' => 'admin@pressiumize.test'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole($superAdminRole);

        // Finance data comes from Pedro's workbook, not from a seeder:
        // php artisan finance:import-excel /path/to/controlo_financeiro_receitas_despesas.xlsx --replace
        // (or Definições → Importar Excel in the panel).
    }
}
