<?php

namespace App\Support;

use App\Actions\IntakeInvoiceEmail;
use DomainException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final class InvoiceEmailPdfQr
{
    private const RENDERER = '/usr/bin/pdftoppm';

    private const LIMITER = '/usr/bin/prlimit';

    public function __construct(private ExpenseDocumentQr $qr) {}

    public function available(): bool
    {
        return app()->environment(['local', 'testing'])
            && extension_loaded('gd')
            && function_exists('proc_open')
            && is_executable(self::RENDERER)
            && is_executable(self::LIMITER);
    }

    public function read(string $pdf): ?string
    {
        if (! $this->available() || ! str_starts_with($pdf, '%PDF-') || strlen($pdf) > IntakeInvoiceEmail::MAX_PDF_KILOBYTES * 1024) {
            return null;
        }

        $directory = sys_get_temp_dir().'/contiu-invoice-email-'.bin2hex(random_bytes(16));
        if (! @mkdir($directory, 0700)) {
            return null;
        }

        $input = $directory.'/input.pdf';
        $output = $directory.'/page.png';

        try {
            if (@file_put_contents($input, $pdf) !== strlen($pdf)) {
                return null;
            }

            $process = new Process([
                self::LIMITER, '--as=268435456', '--cpu=3', '--fsize=8388608', '--',
                self::RENDERER, '-f', '1', '-l', '1', '-singlefile', '-scale-to', '1600',
                '-hide-annotations', '-png', '-q', $input, $directory.'/page',
            ], cwd: $directory, timeout: 4);
            $process->disableOutput();
            if ($process->run() !== 0) {
                return null;
            }

            $image = @file_get_contents($output, length: ExpenseDocumentQr::MAX_IMAGE_KILOBYTES * 1024);
            if ($image === false) {
                return null;
            }

            $text = $this->qr->readImage($image);
            $this->qr->parse($text);

            return $text;
        } catch (DomainException|ExceptionInterface) {
            return null;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($directory);
        }
    }
}
