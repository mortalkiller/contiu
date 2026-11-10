<?php

namespace App\Support;

use App\Enums\FiscalDocumentType;
use chillerlan\QRCode\Common\GDLuminanceSource;
use chillerlan\QRCode\QRCode;
use DomainException;
use Throwable;

final class ExpenseDocumentQr
{
    public const MAX_LENGTH = 4096;

    public const MAX_IMAGE_KILOBYTES = 8192;

    /** @return array{document_date: string, number: string, net_amount: int, vat_amount: int, total_amount: int, atcud: ?string, party_tax_id: string, type: string} */
    public function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > self::MAX_LENGTH || ! mb_check_encoding($text, 'UTF-8') || preg_match('/\p{C}/u', $text)) {
            throw new DomainException('Lê ou cola um código QR fiscal válido.');
        }

        $fields = [];
        foreach (explode('*', $text) as $part) {
            [$key, $value] = array_pad(explode(':', $part, 2), 2, '');
            if (! preg_match('/\A[A-Z][1-9]?\z/', $key) || $value === '' || isset($fields[$key])) {
                throw new DomainException('O código QR tem campos inválidos ou repetidos.');
            }
            $fields[$key] = $value;
        }

        $issuer = $fields['A'] ?? '';
        $number = trim($fields['G'] ?? '');
        $atcud = $fields['H'] ?? '0';
        $type = match ($fields['D'] ?? null) {
            'FT', 'FS' => FiscalDocumentType::Invoice,
            'FR' => FiscalDocumentType::InvoiceReceipt,
            default => null,
        };
        $date = $fields['F'] ?? '';

        if ($type === null || ($fields['E'] ?? null) !== 'N') {
            throw new DomainException('Escolhe o QR de uma fatura, fatura simplificada ou fatura-recibo em estado normal.');
        }
        if (! preg_match('/\A[1-9][0-9]{8}\z/', $issuer) || $issuer === '999999990'
            || $number === '' || mb_strlen($number) > 255
            || ($atcud !== '0' && ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{0,47}-[0-9]{1,15}\z/', $atcud))
            || ! preg_match('/\A([1-9][0-9]{3})([0-9]{2})([0-9]{2})\z/', $date, $dateParts)
            || ! checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
            throw new DomainException('O NIF do emitente, a referência, o ATCUD ou a data do QR são inválidos.');
        }

        $total = $this->cents($fields['O'] ?? '');
        $vat = $this->cents($fields['N'] ?? '');
        if ($this->cents($fields['M'] ?? '0.00') !== 0 || $this->cents($fields['L'] ?? '0.00') !== 0) {
            throw new DomainException('Este QR contém Imposto do Selo ou um regime especial. Regista o documento por outra via.');
        }

        $net = $total - $vat;
        $vatFields = array_intersect_key($fields, array_flip(['I4', 'I6', 'I8', 'J4', 'J6', 'J8', 'K4', 'K6', 'K8']));
        $baseFields = array_intersect_key($fields, array_flip(['I2', 'I3', 'I5', 'I7', 'J2', 'J3', 'J5', 'J7', 'K2', 'K3', 'K5', 'K7']));
        if ($net < 0 || array_sum(array_map($this->cents(...), $vatFields)) !== $vat
            || ($baseFields !== [] && array_sum(array_map($this->cents(...), $baseFields)) !== $net)) {
            throw new DomainException('A base, o IVA e o total no QR não coincidem.');
        }

        return [
            'document_date' => implode('-', array_slice($dateParts, 1)),
            'number' => $number,
            'net_amount' => $net,
            'vat_amount' => $vat,
            'total_amount' => $total,
            'atcud' => $atcud === '0' ? null : $atcud,
            'party_tax_id' => $issuer,
            'type' => $type->value,
        ];
    }

    public function readImage(string $bytes): string
    {
        if (! extension_loaded('gd')) {
            throw new DomainException('A leitura de fotografias está indisponível. Podes colar o conteúdo do QR.');
        }

        $size = strlen($bytes) <= self::MAX_IMAGE_KILOBYTES * 1024 ? @getimagesizefromstring($bytes) : false;
        if ($size === false || $size[0] * $size[1] > 24_000_000 || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new DomainException('Escolhe uma fotografia JPEG, PNG ou WebP até 8 MB e 24 megapíxeis.');
        }

        try {
            $image = @imagecreatefromstring($bytes);
            if ($image !== false && max($size[0], $size[1]) > 1600) {
                $ratio = 1600 / max($size[0], $size[1]);
                $image = imagescale($image, max(1, (int) round($size[0] * $ratio)), max(1, (int) round($size[1] * $ratio)));
            }
            if ($image !== false) {
                return (string) (new QRCode)->readFromSource(new GDLuminanceSource($image));
            }
        } catch (Throwable) {
            // Report a stable message without exposing library internals or the contents of the photo.
        }

        throw new DomainException('Não foi possível ler um QR nesta fotografia. Aproxima a câmara do código ou cola o seu conteúdo.');
    }

    private function cents(string $value): int
    {
        if (! preg_match('/\A([0-9]{1,10})\.([0-9]{2})\z/', $value, $parts)) {
            throw new DomainException('O QR contém um valor fiscal inválido.');
        }

        return (int) $parts[1] * 100 + (int) $parts[2];
    }
}
