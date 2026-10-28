<?php

namespace App\Core\Manual;

use App\Core\DocumentTemplates\PdfFonts;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds a printable guide from the manual's topics: a cover, a table of
 * contents with page numbers, then the topics grouped by module.
 *
 * Quiet and black on white (BR-01): text in black, captions and hairlines
 * in the light theme's `ink-muted` and `border` tokens, set in Geist (OFL,
 * resources/fonts; Helvetica only if the font files are missing). The PDF
 * engine cannot switch on OpenType features, so the figures that must line
 * up (page numbers in the contents and footer) use Geist Mono. A topic's
 * screenshot sits under its title. Pages are numbered
 * from the contents page on. The contents are filled in on a second pass,
 * once the first has shown which page each module and topic landed on.
 */
class ManualBuilder
{
    /** Used when design/tokens.json cannot be read. */
    private const FALLBACK_TOKENS = ['ink-muted' => '#65656d', 'border' => '#ebebe8'];

    public function __construct(
        private readonly TopicLibrary $library,
        private readonly string $tokensPath,
    ) {}

    /** @return Collection<int, Topic> */
    public function topics(string $locale, ?string $role): Collection
    {
        return $this->library->forRole($locale, $role);
    }

    /**
     * The guide as a PDF document. $topics and $guide replace the role's
     * topics and title when given (the in-app "my guide": exactly the topics
     * one user can see).
     *
     * @param  Collection<int, Topic>|null  $topics
     */
    public function pdf(string $locale, ?string $role, ?Collection $topics = null, ?string $guide = null): string
    {
        $topics ??= $this->topics($locale, $role);

        if ($topics->isEmpty()) {
            throw new InvalidArgumentException('There are no manual topics for '.($role ?? 'the complete guide')." in [{$locale}].");
        }

        // First pass: where does each module and topic start?
        $pages = [];
        $this->render($this->html($locale, $role, $topics, [], $guide), $locale, $role, $pages, $guide);

        // Second pass: the contents with their page numbers.
        $ignored = [];

        return $this->render($this->html($locale, $role, $topics, $pages, $guide), $locale, $role, $ignored, $guide);
    }

    /**
     * The guide as one HTML document.
     *
     * @param  Collection<int, Topic>|null  $topics
     * @param  array<string, int>  $pages  page of each anchor (`m-{module}`, `t-{id}`); empty on the first pass
     */
    public function html(string $locale, ?string $role, ?Collection $topics = null, array $pages = [], ?string $guide = null): string
    {
        $topics ??= $this->topics($locale, $role);
        $tokens = $this->tokens();
        $name = $this->appName();
        $guide ??= $this->guideLabel($locale, $role);
        $modules = $topics->groupBy('module');

        $contents = '';
        $chapters = '';

        foreach ($modules as $module => $moduleTopics) {
            $moduleLabel = $this->moduleLabel((string) $module, $locale);
            $contents .= '<tr class="toc-module"><td><a href="#m-'.e($module).'">'.e($moduleLabel).'</a></td><td class="toc-page">'.$this->pageLabel($pages, 'm-'.$module).'</td></tr>';
            $chapters .= '<h1 id="m-'.e($module).'" class="chapter">'.e($moduleLabel).'</h1>';

            foreach ($moduleTopics as $topic) {
                $contents .= '<tr><td class="toc-topic"><a href="#t-'.e($topic->id).'">'.e($topic->title).'</a></td><td class="toc-page">'.$this->pageLabel($pages, 't-'.$topic->id).'</td></tr>';
                $chapters .= '<h2 id="t-'.e($topic->id).'">'.e($topic->title).'</h2>'.$this->image($topic, $locale).$this->markdown($topic, $name, $locale);
            }
        }

        $title = e(Lang::get('manual.title', [], $locale));
        $contentsTitle = e(Lang::get('manual.contents', [], $locale));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
        <meta charset="utf-8">
        <title>{$title} - {$this->escape($guide)}</title>
        <style>
        @page { size: A4; margin: 22mm 20mm 24mm 20mm; }
        body { font-family: "Geist", Helvetica, Arial, sans-serif; font-size: 10pt; line-height: 1.55; color: #000000; }
        a { color: #000000; text-decoration: none; }
        .cover { padding-top: 70mm; }
        .cover .app { font-size: 11pt; color: {$tokens['ink-muted']}; margin: 0 0 6mm 0; }
        .cover .title { font-size: 30pt; font-weight: bold; line-height: 1.15; margin: 0 0 4mm 0; }
        .cover .guide { font-size: 14pt; color: {$tokens['ink-muted']}; margin: 0; }
        .cover .rule { border-top: 0.5pt solid {$tokens['border']}; margin: 10mm 0 0 0; width: 40mm; }
        .contents { page-break-before: always; }
        .contents h1 { font-size: 16pt; font-weight: bold; margin: 0 0 6mm 0; }
        table.toc { width: 100%; border-collapse: collapse; }
        table.toc td { padding: 1.6mm 0; border-bottom: 0.5pt solid {$tokens['border']}; vertical-align: top; }
        table.toc tr.toc-module td { padding-top: 5mm; font-weight: bold; }
        table.toc td.toc-topic { padding-left: 5mm; }
        table.toc td.toc-page { width: 14mm; text-align: right; color: {$tokens['ink-muted']}; font-family: "Geist Mono", "Courier New", monospace; font-size: 9pt; }
        img.shot { width: 100%; border: 0.5pt solid {$tokens['border']}; margin: 0 0 4mm 0; page-break-inside: avoid; }
        h1.chapter { page-break-before: always; font-size: 20pt; font-weight: bold; margin: 0 0 8mm 0; }
        h2 { page-break-after: avoid; font-size: 13pt; font-weight: bold; margin: 9mm 0 3mm 0; }
        h3 { page-break-after: avoid; font-size: 10.5pt; font-weight: bold; margin: 5mm 0 1.5mm 0; }
        p { margin: 0 0 3mm 0; }
        ol, ul { margin: 0 0 3mm 0; padding-left: 6mm; }
        li { margin: 0 0 1.5mm 0; }
        table.grid { width: 100%; border-collapse: collapse; margin: 0 0 3mm 0; }
        table.grid th { text-align: left; font-weight: bold; color: {$tokens['ink-muted']}; padding: 1.5mm 2mm 1.5mm 0; border-bottom: 0.5pt solid {$tokens['border']}; }
        table.grid td { padding: 1.5mm 2mm 1.5mm 0; border-bottom: 0.5pt solid {$tokens['border']}; vertical-align: top; }
        code { font-family: "Geist Mono", "Courier New", monospace; font-size: 9pt; }
        </style>
        </head>
        <body>
        <div class="cover">
        <p class="app">{$this->escape($name)}</p>
        <p class="title">{$title}</p>
        <p class="guide">{$this->escape($guide)}</p>
        <div class="rule"></div>
        </div>
        <div class="contents">
        <h1>{$contentsTitle}</h1>
        <table class="toc">{$contents}</table>
        </div>
        {$chapters}
        </body>
        </html>
        HTML;
    }

    /** The topic's screenshot as an `img` under its title (embedded, so the PDF needs no file access), or nothing. */
    public function image(Topic $topic, string $locale): string
    {
        $path = $this->library->imagePath($topic, $locale);

        if ($path === null) {
            return '';
        }

        $alt = $topic->imageAlt ?? Lang::get('manual.screenshot_alt', ['title' => $topic->title], $locale);

        return '<img class="shot" alt="'.e($alt).'" src="data:image/png;base64,'.base64_encode((string) file_get_contents($path)).'">';
    }

    /** The topic's body as safe HTML (raw HTML escaped, unsafe links off), with the product name filled in. */
    public function markdown(Topic $topic, string $name, string $locale): string
    {
        $body = str_replace('{{app_name}}', $name, $topic->body);

        if (preg_match('/\{\{\s*([^}]*?)\s*\}\}/', $body, $unknown)) {
            throw new InvalidArgumentException("Manual topic [{$topic->id}] uses the placeholder [{$unknown[1]}]; only {{app_name}} is known.");
        }

        $html = Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        if ($locale === 'fr') {
            // French typography: no line break inside « guillemets » or before : ; ? !
            $html = preg_replace(['/«\s+/u', '/\s+»/u', '/ ([:;?!])(?=\s|<|$)/u'], ["«\u{00A0}", "\u{00A0}»", "\u{00A0}$1"], $html);
        }

        return str_replace('<table>', '<table class="grid">', $html);
    }

    /**
     * Render $html and note, for every module and topic heading, the page it
     * started on. Returns the PDF with a footer on every page after the cover.
     *
     * @param  array<string, int>  $pages  filled with anchor => page
     */
    private function render(string $html, string $locale, ?string $role, array &$pages, ?string $guide = null): string
    {
        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'manual-pdf';

        if (! is_dir($sandbox)) {
            @mkdir($sandbox, 0700, true);
        }

        $options = new Options;
        $options->setDefaultFont('Helvetica');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setTempDir($sandbox);
        // The registered faces are copied into the sandbox once and reused by every build.
        PdfFonts::configure($options, $sandbox);

        $pdf = new Dompdf($options);
        $geist = PdfFonts::register($pdf->getFontMetrics());
        $pdf->setCallbacks([[
            'event' => 'begin_frame',
            'f' => function (Frame $frame, $canvas) use (&$pages) {
                $node = $frame->get_node();

                if ($node instanceof \DOMElement && $node->hasAttribute('id')) {
                    $id = $node->getAttribute('id');

                    if (str_starts_with($id, 'm-') || str_starts_with($id, 't-')) {
                        $pages[$id] ??= $canvas->get_page_number();
                    }
                }
            },
        ]]);

        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $metrics = $pdf->getFontMetrics();
        $font = $metrics->getFont($geist ? 'Geist' : 'Helvetica');
        $mono = $metrics->getFont($geist ? 'Geist Mono' : 'Helvetica');
        $color = $this->rgb($this->tokens()['ink-muted']);
        $guide ??= $this->guideLabel($locale, $role);
        $left = $this->appName().'  ·  '.$guide;
        $size = 8;

        // Page numbers from the contents page on; the cover stays clean.
        $canvas->page_script(function (int $number, int $count, $canvas, $metrics) use ($font, $mono, $color, $left, $size, $locale) {
            if ($number < 2) {
                return;
            }

            $label = Lang::get('manual.page', ['number' => $number], $locale);
            $y = $canvas->get_height() - 40;
            $margin = 57;

            $canvas->text($margin, $y, $left, $font, $size, $color);
            $canvas->text($canvas->get_width() - $margin - $metrics->getTextWidth($label, $mono, $size), $y, $label, $mono, $size, $color);
        });

        $pdf->addInfo('Title', Lang::get('manual.title', [], $locale).' - '.$guide);

        return (string) $pdf->output();
    }

    private function pageLabel(array $pages, string $anchor): string
    {
        // The first pass reserves the same width as the final numbers.
        return isset($pages[$anchor]) ? (string) $pages[$anchor] : '00';
    }

    private function escape(string $text): string
    {
        return e($text);
    }

    private function appName(): string
    {
        return (string) config('app.name');
    }

    private function guideLabel(string $locale, ?string $role): string
    {
        if ($role === null) {
            return Lang::get('manual.guide_all', [], $locale);
        }

        $key = 'rbac.templates.'.$role;
        $name = Lang::has($key, $locale) ? Lang::get($key, [], $locale) : Str::headline($role);

        return Lang::get('manual.guide_for', ['role' => $name], $locale);
    }

    public function moduleLabel(string $module, string $locale): string
    {
        $key = 'manual.modules.'.$module;

        return Lang::has($key, $locale) ? Lang::get($key, [], $locale) : Str::headline($module);
    }

    /** @return array{ink-muted: string, border: string} */
    private function tokens(): array
    {
        $found = self::FALLBACK_TOKENS;
        $source = is_file($this->tokensPath) ? json_decode((string) file_get_contents($this->tokensPath), true) : null;

        foreach ($source['color']['tokens'] ?? [] as $token) {
            if (isset($found[$token['name'] ?? '']) && preg_match('/^#[0-9a-f]{6}$/i', (string) ($token['value']['light'] ?? ''))) {
                $found[$token['name']] = $token['value']['light'];
            }
        }

        return $found;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
    }
}
