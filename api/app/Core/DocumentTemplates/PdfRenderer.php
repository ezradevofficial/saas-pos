<?php

namespace App\Core\DocumentTemplates;

use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;

/**
 * TPL-04: a document as a PDF through dompdf, hardened like the list
 * exports (no remote files, no PHP, no JavaScript, local files confined
 * to an empty directory). A4 and A5 use their page size; a thermal
 * receipt is one page as long as its content (measured on a first pass).
 */
class PdfRenderer
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function pdf(string $type, array $template, array $data, bool $fiscalRequired): string
    {
        $paper = isset(TemplateRenderer::WIDTHS[$template['paper'] ?? '']) ? $template['paper'] : DocumentTypes::paper($type);

        if (! DocumentTypes::isThermal($paper)) {
            return $this->render($this->renderer->html($type, $template, $data, $fiscalRequired));
        }

        // First pass on a very long page: how tall is the content?
        $margins = TemplateRenderer::margins($template['margins'] ?? null);
        $height = 0.0;
        $this->render($this->renderer->html($type, $template, $data, $fiscalRequired, 2000), function (Frame $frame) use (&$height) {
            $node = $frame->get_node();

            if ($node->nodeName === 'div' && $node->getAttribute('class') === 'doc') {
                $height = max($height, (float) $frame->get_margin_height());
            }
        });

        // Points to mm, plus the page margins and a little room for rounding.
        $heightMm = max(40, $height * 25.4 / 72 + $margins['top'] + $margins['bottom'] + 4);

        return $this->render($this->renderer->html($type, $template, $data, $fiscalRequired, $heightMm));
    }

    /** @param  (callable(Frame): void)|null  $onFrame */
    private function render(string $html, ?callable $onFrame = null): string
    {
        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'document-templates-pdf';

        if (! is_dir($sandbox)) {
            @mkdir($sandbox, 0700, true);
        }

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot($sandbox);
        $options->setTempDir($sandbox);

        $pdf = new Dompdf($options);

        if ($onFrame !== null) {
            $pdf->setCallbacks([['event' => 'end_frame', 'f' => fn (Frame $frame) => $onFrame($frame)]]);
        }

        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }
}
