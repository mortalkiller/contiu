<?php

namespace App\Support;

use DomainException;

/**
 * A photo as a one-page PDF, so that the photo of an invoice can be kept where supporting
 * documents are kept (PDF only). The page takes the photo's proportions at A4 width.
 */
final class ImageToPdf
{
    private const int MAX_SIDE = 2400;

    private const float PAGE_WIDTH = 595.0;

    public static function convert(string $bytes): string
    {
        $image = extension_loaded('gd') ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            throw new DomainException(__('Could not keep this photo as a supporting document.'));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > self::MAX_SIDE) {
            $ratio = self::MAX_SIDE / max($width, $height);
            $image = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
            [$width, $height] = [imagesx($image), imagesy($image)];
        }

        // A JPEG has no transparency: what was see-through becomes white paper.
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($flat, null, 85);
        $jpeg = (string) ob_get_clean();

        $pageHeight = round(self::PAGE_WIDTH * $height / $width, 2);
        $content = sprintf("q\n%.2F 0 0 %.2F 0 0 cm\n/Im0 Do\nQ\n", self::PAGE_WIDTH, $pageHeight);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>', self::PAGE_WIDTH, $pageHeight),
            sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $width, $height, strlen($jpeg)).$jpeg."\nendstream",
            sprintf("<< /Length %d >>\nstream\n", strlen($content)).$content.'endstream',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }
}
