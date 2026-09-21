<?php

namespace App\Services\Barcodes;

use App\Support\AppError;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Printable codes, always as SVG: sharp at any label size and independent of the GD extension (the serverless PHP
 * runtime has none).
 *
 *  - CODE128 (picqer/php-barcode-generator): shipping and shelf labels — bin codes, shipment / fulfilment-order / trip
 *    numbers, SKUs. One-dimensional, read by every handheld scanner.
 *  - QR (endroid/qr-code): runs and customers — a document number or a link into this application, read by a phone.
 *
 * The text is whatever the caller asks for: nothing is looked up here, so a label can never show a different value
 * from the one printed under it.
 */
class BarcodeService
{
    public const CODE128_MAX = 48;

    public const QR_MAX = 600;

    /** Labels per print job. */
    public const BATCH_MAX = 300;

    /** @return string standalone SVG document */
    public function code128(string $text, int $height = 64, int $module = 2): string
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > self::CODE128_MAX) {
            throw AppError::validation('BARCODE_TEXT', 'نص الباركود مطلوب وبحد أقصى '.self::CODE128_MAX.' حرفًا', 'Barcode text is required, at most '.self::CODE128_MAX.' characters');
        }
        // CODE128 carries ASCII only: an Arabic name would print as a code no scanner can read back.
        if (! preg_match('/^[\x20-\x7E]+$/', $text)) {
            throw AppError::validation('BARCODE_ASCII', 'باركود CODE128 يقبل الأحرف والأرقام اللاتينية فقط — استخدم QR للنص العربي', 'CODE128 accepts ASCII only — use a QR code for Arabic text');
        }
        $barcode = (new TypeCode128)->getBarcode($text);
        $renderer = new SvgRenderer;
        $renderer->setSvgType(SvgRenderer::TYPE_SVG_STANDALONE);

        return $renderer->render($barcode, $barcode->getWidth() * max(1, min($module, 4)), max(24, min($height, 200)));
    }

    /** @return string standalone SVG document */
    public function qr(string $text, int $size = 240): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::QR_MAX) {
            throw AppError::validation('QR_TEXT', 'نص رمز QR مطلوب وبحد أقصى '.self::QR_MAX.' حرف', 'QR text is required, at most '.self::QR_MAX.' characters');
        }
        $result = (new Builder(
            writer: new SvgWriter,
            data: $text,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: max(96, min($size, 800)),
            margin: 8,
        ))->build();

        return $result->getString();
    }
}
