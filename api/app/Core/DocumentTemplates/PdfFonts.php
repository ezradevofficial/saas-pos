<?php

namespace App\Core\DocumentTemplates;

use Dompdf\FontMetrics;
use Dompdf\Options;

/**
 * The design system's typefaces for the PDF engine: Geist (regular, and
 * SemiBold for bold) and Geist Mono, from resources/fonts (OFL). Every PDF
 * the platform renders (documents from templates, billing invoices, the
 * manual) registers them, so a document set in "Geist" prints in Geist and
 * not in dompdf's DejaVu. The faces are copied once into the renderer's
 * font cache and reused. Fonts only change the letters: printed documents
 * stay black on white, and the locked fiscal blocks keep their content.
 */
final class PdfFonts
{
    /** family, CSS weight and style, file in resources/fonts */
    public const FACES = [
        ['Geist', 'normal', 'normal', 'Geist-Regular.ttf'],
        ['Geist', 'bold', 'normal', 'Geist-SemiBold.ttf'],
        ['Geist Mono', 'normal', 'normal', 'GeistMono-Regular.ttf'],
    ];

    public static function directory(): string
    {
        return resource_path('fonts');
    }

    /**
     * Let $options read the font files and keep the registered faces in
     * $sandbox/fonts (created when missing).
     */
    public static function configure(Options $options, string $sandbox): void
    {
        $cache = $sandbox.DIRECTORY_SEPARATOR.'fonts';

        if (! is_dir($cache)) {
            @mkdir($cache, 0700, true);
        }

        $options->setChroot([$sandbox, self::directory()]);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
    }

    /** Register the faces; false (the default font is used) when a file is missing or unreadable. */
    public static function register(FontMetrics $metrics): bool
    {
        foreach (self::FACES as [$family, $weight, $style, $file]) {
            $path = self::directory().DIRECTORY_SEPARATOR.$file;

            if (! is_file($path) || ! $metrics->registerFont(['family' => $family, 'weight' => $weight, 'style' => $style], $path)) {
                return false;
            }
        }

        return true;
    }
}
