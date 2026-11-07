<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<int, string>> money columns, nullable ones marked with "?" */
    private const COLUMNS = [
        'projects' => ['net_amount'],
        'retainers' => ['net_amount'],
        'recurring_expenses' => ['net_amount'],
        'expenses' => ['net_amount'],
        'one_off_incomes' => ['net_amount'],
        'receivables' => ['amount'],
        'payments' => ['amount'],
        'bank_transactions' => ['amount', '?balance'],
    ];

    private const SETTINGS = ['current_bank_balance', 'vat_exemption_threshold'];

    /**
     * Money is stored as integer cents, as banks do: SQLite keeps "decimal" columns as floating
     * point, so sums and comparisons were never exact. Existing values are converted, not lost.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $name = ltrim($column, '?');
                DB::table($table)->whereNotNull($name)->update([$name => DB::raw("CAST(ROUND($name * 100) AS INTEGER)")]);

                Schema::table($table, function (Blueprint $blueprint) use ($column, $name) {
                    $definition = $blueprint->bigInteger($name);
                    str_starts_with($column, '?') ? $definition->nullable()->change() : $definition->change();
                });
            }
        }

        DB::table('settings')->whereIn('key', self::SETTINGS)->whereNotNull('value')->get()
            ->each(fn (object $row) => DB::table('settings')->where('id', $row->id)->update(['value' => (string) (int) round((float) $row->value * 100)]));
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $name = ltrim($column, '?');

                Schema::table($table, function (Blueprint $blueprint) use ($column, $name) {
                    $definition = $blueprint->decimal($name, 12, 2);
                    str_starts_with($column, '?') ? $definition->nullable()->change() : $definition->change();
                });

                DB::table($table)->whereNotNull($name)->update([$name => DB::raw("$name / 100.0")]);
            }
        }

        DB::table('settings')->whereIn('key', self::SETTINGS)->whereNotNull('value')->get()
            ->each(fn (object $row) => DB::table('settings')->where('id', $row->id)->update(['value' => (string) round((int) $row->value / 100, 2)]));
    }
};
