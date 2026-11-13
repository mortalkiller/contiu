<?php

namespace App\Modules\InvoiceReader\Benchmark;

use Illuminate\Http\UploadedFile;

class InvoiceBenchmarkFixtures
{
    public static function all(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../resources/fixtures/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function file(string $key): UploadedFile
    {
        $name = self::all()[$key]['file'];

        return new UploadedFile(__DIR__.'/../resources/fixtures/'.$name, $name, test: true);
    }

    public static function expected(string $key): array
    {
        return self::all()[$key]['expected'];
    }

    public static function output(string $key): array
    {
        $output = self::expected($key);
        if ($key === 'poor-photo') {
            $output['invoice_number'] = 'FT DEMO/OO1';
            $output['supplier_nif'] = null;
        }

        return $output;
    }
}
