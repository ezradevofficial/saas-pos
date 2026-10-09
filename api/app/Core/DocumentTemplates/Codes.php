<?php

namespace App\Core\DocumentTemplates;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Throwable;

/**
 * TPL-01: QR codes (bacon/bacon-qr-code) and Code 128 barcodes
 * (picqer/php-barcode-generator) as SVG data URIs, black on white, for
 * both the HTML and the PDF (dompdf reads SVG images).
 */
class Codes
{
    public function qrSvg(string $content): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($content);
    }

    public function qrDataUri(string $content): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->qrSvg($content));
    }

    /** Null when $content can't be encoded in Code 128 (outside printable ASCII). */
    public function code128DataUri(string $content): ?string
    {
        if (preg_match('/^[\x20-\x7E]{1,80}$/', $content) !== 1) {
            return null;
        }

        try {
            $barcode = (new TypeCode128)->getBarcode($content);
            $svg = (new SvgRenderer)->render($barcode, max(60, $barcode->getWidth() * 2), 40);
        } catch (Throwable) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
