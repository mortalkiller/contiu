<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->char('alpha3', 3)->unique();
            $table->char('numeric_code', 3)->unique();
            $table->string('name');
        });

        Schema::create('portuguese_administrative_areas', function (Blueprint $table): void {
            $table->string('code', 6)->primary();
            $table->string('name');
            $table->string('kind', 12);
            $table->string('parent_code', 6)->nullable();
            $table->string('vat_region', 5);
            $table->foreign('parent_code')->references('code')->on('portuguese_administrative_areas');
            $table->index(['kind', 'parent_code']);
        });

        $countries = json_decode(file_get_contents(database_path('data/iso-3166-1.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_chunk($countries, 100) as $batch) {
            DB::table('countries')->insert($batch);
        }

        $file = fopen(database_path('data/caop-2025.csv'), 'r');
        try {
            $header = fgetcsv($file, escape: '');
            $areas = [];

            while (($row = fgetcsv($file, escape: '')) !== false) {
                $area = array_combine($header, $row);
                $area['parent_code'] = $area['parent_code'] === '' ? null : $area['parent_code'];
                $areas[$area['kind']][] = $area;
            }

            foreach ($areas as $level) {
                foreach (array_chunk($level, 100) as $batch) {
                    DB::table('portuguese_administrative_areas')->insert($batch);
                }
            }
        } finally {
            fclose($file);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portuguese_administrative_areas');
        Schema::dropIfExists('countries');
    }
};
