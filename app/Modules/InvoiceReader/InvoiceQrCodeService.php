<?php

namespace App\Modules\InvoiceReader;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class InvoiceQrCodeService
{
    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function inspect(string $path, string $mime): array
    {
        if ($mime === 'application/pdf') {
            $info = $this->command(['pdfinfo', $path], 5);
            if (! $info->isSuccessful() || ! preg_match('/^Pages:\s+(\d+)$/m', $info->getOutput(), $match)
                || (int) $match[1] < 1 || (int) $match[1] > 20
                || preg_match('/^Encrypted:\s+yes/m', $info->getOutput())) {
                throw new RuntimeException('Upload a readable, unencrypted PDF with up to 20 pages.');
            }

            return ['pages' => (int) $match[1]];
        }

        $dimensions = @getimagesize($path);
        if ($dimensions === false || $dimensions['mime'] !== $mime || $dimensions[0] * $dimensions[1] > 25000000) {
            throw new RuntimeException('Upload a readable image with up to 25 megapixels.');
        }

        return ['width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    public function read(string $path, string $mime, array $dimensions): array
    {
        $detected = false;
        $parsed = [];
        try {
            $isPdf = $mime === 'application/pdf';
            $pages = $dimensions['pages'] ?? 1;
            $deadline = hrtime(true) + 15000000000;
            for ($page = 1; $page <= $pages; $page++) {
                foreach ($isPdf ? [1600, 3200] : [null] as $size) {
                    if (hrtime(true) > $deadline) {
                        throw new RuntimeException('The local QR scan exceeded its time limit.');
                    }

                    $foundOnPage = false;
                    foreach ($this->scanPage($path, $page, $size) as $payload) {
                        $detected = true;
                        $data = $this->parse($payload);
                        if ($data === null) {
                            continue;
                        }

                        $foundOnPage = true;
                        $parsed[hash('sha256', serialize($data))] = $data;
                    }

                    if ($foundOnPage) {
                        break;
                    }
                }
            }

            if (! $isPdf && $parsed === []) {
                foreach ($this->scanPhotoFallback($path) as $payload) {
                    $detected = true;
                    if (($data = $this->parse($payload)) !== null) {
                        $parsed[hash('sha256', serialize($data))] = $data;
                    }
                }
            }
        } catch (RuntimeException) {
            return ['status' => 'decoder_error', 'detected' => $detected, 'data' => null];
        }

        return [
            'status' => match (count($parsed)) {
                0 => $detected ? 'invalid' : 'not_found',
                1 => 'parsed',
                default => 'ambiguous',
            },
            'detected' => $detected,
            'data' => count($parsed) === 1 ? reset($parsed) : null,
        ];
    }

    /** @return list<string> */
    private function scanPage(string $path, int $page, ?int $size): array
    {
        $image = $size === null ? null : $this->pdfPage($path, $page, $size);

        try {
            $process = $this->command(['zbarimg', '--nodbus', '--quiet', '--raw', '-Sdisable', '-Sqrcode.enable', $image ?? $path], 4);
            if (! in_array($process->getExitCode(), [0, 4], true)) {
                throw new RuntimeException('The local QR decoder could not read the document.');
            }

            return preg_split('/\R/', trim($process->getOutput()), flags: PREG_SPLIT_NO_EMPTY);
        } finally {
            if ($image !== null) {
                File::deleteDirectory(dirname($image));
            }
        }
    }

    /** @return list<string> */
    private function scanPhotoFallback(string $path): array
    {
        $decoder = (new ExecutableFinder)->find('gst-launch-1.0');
        if ($decoder === null || ! extension_loaded('gd')) {
            return [];
        }

        $bytes = file_get_contents($path);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            return [];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $payloads = $this->scanWithZxing($decoder, $image);
        if ($height < 2 * $width || array_filter($payloads, fn (string $payload): bool => $this->parse($payload) !== null)) {
            return $payloads;
        }

        // A tall receipt often prints a small QR at the bottom centre.
        $size = min(800, max(1, intdiv($width, 2)));
        $footer = imagecrop($image, ['x' => intdiv($width - $size, 2), 'y' => $height - $size, 'width' => $size, 'height' => $size]);
        if ($footer !== false && ($enlarged = imagescale($footer, $size * 2, $size * 2, IMG_BILINEAR_FIXED)) !== false) {
            $payloads = [...$payloads, ...$this->scanWithZxing($decoder, $enlarged)];
        }

        return $payloads;
    }

    /** @return list<string> */
    private function scanWithZxing(string $decoder, \GdImage $image): array
    {
        $input = fopen('php://temp', 'w+b');
        if ($input === false) {
            return [];
        }

        try {
            if (! imagepng($image, $input) || ! rewind($input)) {
                return [];
            }

            $process = new Process([$decoder, '-m', 'fdsrc', '!', 'pngdec', '!', 'videoconvert',
                '!', 'zxing', 'format=qr_code', '!', 'fakesink'], env: ['GST_DEBUG_NO_COLOR' => '1'], input: $input, timeout: 3);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }

            preg_match_all('/type=\(string\)QRCode, symbol=\(string\)"((?:\\\\.|[^"\\\\])*)";$/m', $process->getOutput(), $matches);

            return array_map(stripcslashes(...), $matches[1]);
        } catch (RuntimeException) {
            return [];
        } finally {
            fclose($input);
        }
    }

    public function pdfPage(string $path, int $page, int $size = 1200): string
    {
        $directory = Storage::disk('local')->path('invoice-benchmark-raster/'.Str::uuid());
        File::makeDirectory($directory, 0700, true);

        try {
            $output = "{$directory}/page";
            $process = $this->command(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-scale-to', (string) $size,
                '-png', '-singlefile', $path, $output], 5);
            if ($process->isSuccessful() && is_file("{$output}.png")) {
                return "{$output}.png";
            }

            throw new RuntimeException('The PDF page could not be rendered locally.');
        } catch (RuntimeException $exception) {
            File::deleteDirectory($directory);

            throw $exception;
        }
    }

    public function parse(string $payload): ?array
    {
        if (strlen($payload) > 4096 || ! mb_check_encoding($payload, 'UTF-8')) {
            return null;
        }

        $fields = [];
        foreach (explode('*', $payload) as $part) {
            if (! preg_match('/\A([A-R][1-8]?|S):([^\p{C}]+)\z/u', $part, $match) || isset($fields[$match[1]])) {
                return null;
            }
            $fields[$match[1]] = trim($match[2]);
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'N', 'O', 'Q', 'R'] as $key) {
            if (blank($fields[$key] ?? null)) {
                return null;
            }
        }

        if (! preg_match('/\A[0-9]{9}\z/', $fields['A']) || ! preg_match('/\A[A-Z]{2}\z/', $fields['C'])
            || ! preg_match('/\A[A-Z0-9]{2}\z/', $fields['D']) || ! preg_match('/\A\d{8}\z/', $fields['F'])) {
            return null;
        }

        $date = substr($fields['F'], 0, 4).'-'.substr($fields['F'], 4, 2).'-'.substr($fields['F'], 6, 2);
        if (! InvoiceData::validDate($date)) {
            return null;
        }

        foreach ($fields as $key => $value) {
            if (preg_match('/\A(?:[IJK][2-8]|[LMNOP])\z/', $key) && ! preg_match('/\A-?\d{1,13}\.\d{2}\z/', $value)) {
                return null;
            }
        }

        $vat = [];
        foreach (['I', 'J', 'K'] as $prefix) {
            $region = $fields[$prefix.'1'] ?? null;
            foreach ([2 => 'exempt', 3 => 'reduced', 5 => 'intermediate', 7 => 'standard'] as $index => $category) {
                $base = $fields[$prefix.$index] ?? null;
                $amount = $category === 'exempt' ? null : ($fields[$prefix.($index + 1)] ?? null);
                if ($base !== null || $amount !== null) {
                    $vat[] = [
                        'region' => $region, 'category' => $category, 'rate' => InvoiceData::qrVatRate($region, $category, $date),
                        'taxable_amount' => $base, 'vat_amount' => $amount, 'exemption_reason' => null,
                    ];
                }
            }
        }

        return [
            'supplier_nif' => $fields['A'], 'customer_nif' => $fields['B'], 'customer_country' => $fields['C'],
            'document_type' => $fields['D'], 'issue_date' => $date, 'invoice_number' => $fields['G'],
            'atcud' => $fields['H'] === '0' ? null : $fields['H'],
            'total_tax' => $fields['N'], 'gross_total' => $fields['O'],
            'vat' => $vat === [] ? null : $vat,
        ];
    }

    private function command(array $arguments, int $timeout): Process
    {
        try {
            $process = new Process($arguments, timeout: $timeout);
            $process->run();

            return $process;
        } catch (RuntimeException) {
            throw new RuntimeException('Local document tools are unavailable or exceeded their time limit.');
        }
    }
}
