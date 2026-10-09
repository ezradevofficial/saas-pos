<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-01..TPL-03: renders a template (TemplateSchema) with data (the
 * DataSource shape) to a printable HTML document.
 *
 * - Black on white in every theme: the document carries its own print
 *   colours and never reads the tenant theme or the design tokens.
 * - Every value is escaped; merge fields (`{{customer.name}}`) are filled
 *   before escaping. Tenant texts (text, terms, signature labels) print
 *   as typed (single-language); the app's own wording comes from
 *   `templates.print` in English, French or both side by side ("Total /
 *   Total", TPL-02). Business record names print as entered.
 * - Locked (TPL-03): when the country pack requires fiscal data and the
 *   template has no fiscal block (an old version), one is added at the
 *   end; the totals always print their tax lines.
 * - Upgrade-safe (LAY-07): an unknown block type or field is skipped.
 *
 * The till renders the same JSON with a JS port of this class
 * (pos/src/pos/templates/renderTemplate.js); both render the shared
 * fixtures in tests/Fixtures/templates to the same text.
 */
final class TemplateRenderer
{
    public const MERGE = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    /** Paper width in mm. */
    public const WIDTHS = ['58mm' => 58, '80mm' => 80, 'A5' => 148, 'A4' => 210];

    public const NUMERIC_COLUMNS = ['qty', 'unit_price', 'discount', 'tax_rate', 'tax', 'total', 'amount', 'debit', 'credit', 'balance'];

    private const FISCAL_ROWS = ['invoice_number', 'receipt_number', 'control_unit_id', 'receipt_signature', 'internal_data', 'authority_time'];

    private string $language = 'en';

    private string $type = '';

    private string $paper = '80mm';

    private array $data = [];

    /** @var array{en: array, fr: array} */
    private array $labels;

    /** @var array<string, string> custom field labels by path (tenant text) */
    private array $customLabels = [];

    public function __construct(private readonly Codes $codes) {}

    /**
     * The document as HTML. $heightMm fixes the page height (thermal PDFs,
     * PdfRenderer); null leaves it to the printer.
     */
    public function html(string $type, array $template, array $data, bool $fiscalRequired, ?float $heightMm = null): string
    {
        $this->type = $type;
        $this->data = $data;
        $this->language = in_array($template['language'] ?? null, TemplateSchema::LANGUAGES, true) ? $template['language'] : 'en';
        $this->paper = isset(self::WIDTHS[$template['paper'] ?? '']) ? $template['paper'] : DocumentTypes::paper($type);
        $this->labels = self::printLabels();
        $this->customLabels = app(DataSources::class)->customLabels();

        $blocks = array_values(array_filter((array) ($template['blocks'] ?? []), 'is_array'));

        if ($fiscalRequired && ! self::hasFiscal($blocks)) {
            $blocks[] = ['id' => 'fiscal', 'type' => 'fiscal'];
        }

        $margins = self::margins($template['margins'] ?? null);
        $title = self::escape(trim($this->label('numbers.'.self::typeKey($type)).' '.($data['document']['number'] ?? '')));

        return '<!doctype html>'."\n"
            .'<html lang="'.($this->language === 'fr' ? 'fr' : 'en').'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.'</title><style>'.self::css($this->paper, $margins, $heightMm).'</style></head>'
            .'<body><div class="doc">'.$this->blocks($blocks, false).'</div></body></html>';
    }

    /** The app's printed wording in both languages (TPL-02). */
    public static function printLabels(): array
    {
        return ['en' => (array) __('templates.print', [], 'en'), 'fr' => (array) __('templates.print', [], 'fr')];
    }

    public static function typeKey(string $type): string
    {
        return str_replace('.', '_', $type);
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array{top: int, right: int, bottom: int, left: int} */
    public static function margins(mixed $margins): array
    {
        $out = [];

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = is_array($margins) ? ($margins[$side] ?? 0) : 0;
            $out[$side] = is_int($value) ? max(0, min(30, $value)) : 0;
        }

        return $out;
    }

    public static function css(string $paper, array $margins, ?float $heightMm = null): string
    {
        $width = self::WIDTHS[$paper];
        $thermal = DocumentTypes::isThermal($paper);
        $content = $width - $margins['left'] - $margins['right'];
        $size = match (true) {
            $heightMm !== null => "{$width}mm ".round($heightMm, 1).'mm',
            $thermal => "{$width}mm auto",
            default => $paper,
        };
        $font = $thermal ? '9pt' : '10pt';

        return "@page { size: {$size}; margin: {$margins['top']}mm {$margins['right']}mm {$margins['bottom']}mm {$margins['left']}mm; }"
            .'html, body { margin: 0; padding: 0; background: #fff; color: #000; }'
            ."body { font-family: \"Geist\", \"DejaVu Sans\", Arial, sans-serif; font-size: {$font}; line-height: 1.35; font-variant-numeric: tabular-nums; }"
            ."@media screen { .doc { max-width: {$content}mm; margin: 0 auto; padding: {$margins['top']}mm 0; } }"
            .'.b { margin: 0 0 1.5mm; }'
            .'.t-left { text-align: left; } .t-center { text-align: center; } .t-right { text-align: right; }'
            .'.s-small { font-size: 0.85em; } .s-large { font-size: 1.3em; } .w-medium { font-weight: bold; }'
            .'table { width: 100%; border-collapse: collapse; }'
            .'td, th { padding: 0.4mm 0; vertical-align: top; text-align: left; }'
            .'th { font-weight: bold; border-bottom: 0.2mm solid #000; }'
            .'.lines td, .lines th { padding-right: 1.5mm; } .lines td:last-child, .lines th:last-child { padding-right: 0; }'
            .'.num { text-align: right; white-space: nowrap; }'
            .'.strong td { font-weight: bold; }'
            .'.divider { border-top: 0.2mm solid #000; height: 0; } .divider.dashed { border-top-style: dashed; }'
            .'.fiscal { border-top: 0.2mm solid #000; padding-top: 1.5mm; text-align: center; }'
            .'.sig { padding-top: 10mm; } .sig-line { border-top: 0.2mm solid #000; width: 60mm; max-width: 100%; }'
            .'.terms { white-space: pre-line; }'
            .'.row2 td { width: 50%; padding-right: 4mm; } .row2 td:last-child { padding-right: 0; }'
            .'.code img { display: inline-block; }';
    }

    // ---- Blocks -----------------------------------------------------------

    private function blocks(array $blocks, bool $nested): string
    {
        $html = '';

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $html .= match ($block['type'] ?? null) {
                'text' => $this->text($block),
                'field' => $this->field($block),
                'logo' => $this->logo($block),
                'lines' => $this->lines($block),
                'totals' => $this->totals($block),
                'payments' => $this->payments($block),
                'qr' => $this->qr($block),
                'barcode' => $this->barcode($block),
                'signature' => $this->signature($block),
                'terms' => $this->terms($block),
                'spacer' => '<div class="spacer" style="height: '.self::int($block['size'] ?? 4, 1, 40).'mm"></div>',
                'divider' => '<div class="b divider'.(($block['style'] ?? 'solid') === 'dashed' ? ' dashed' : '').'"></div>',
                'fiscal' => $this->fiscal(),
                'row' => $nested ? '' : $this->row($block),
                default => '',
            };
        }

        return $html;
    }

    private function text(array $block): string
    {
        $text = $this->merge((string) ($block['text'] ?? ''));

        if (trim($text) === '') {
            return '';
        }

        return '<div class="b '.$this->align($block, 'left').' s-'.self::pick($block['size'] ?? null, ['small', 'normal', 'large'], 'normal')
            .(($block['weight'] ?? null) === 'medium' ? ' w-medium' : '').'">'.nl2br(self::escape($text), false).'</div>';
    }

    private function field(array $block): string
    {
        $path = (string) ($block['field'] ?? '');
        $value = $this->display($this->value($path), $path);

        if ($value === '') {
            return '';
        }

        if (($block['label'] ?? true) === true) {
            return '<table class="b kv"><tr><td>'.self::escape($this->fieldLabel($path)).'</td><td class="num">'.self::escape($value).'</td></tr></table>';
        }

        return '<div class="b '.$this->align($block, 'left').'">'.self::escape($value).'</div>';
    }

    private function logo(array $block): string
    {
        $logo = $this->data['company']['logo'] ?? null;

        if (! is_string($logo) || preg_match('#^data:image/(png|jpeg|svg\+xml);base64,[A-Za-z0-9+/=]+$#', $logo) !== 1) {
            return '';
        }

        return '<div class="b code '.$this->align($block, 'left').'"><img src="'.self::escape($logo).'" alt="" style="height: '.self::int($block['height'] ?? 15, 5, 60).'mm"></div>';
    }

    private function lines(array $block): string
    {
        $rows = array_values(array_filter((array) ($this->data['lines'] ?? []), 'is_array'));
        $columns = array_values(array_filter((array) ($block['columns'] ?? []), 'is_string'));

        if ($rows === [] || $columns === []) {
            return '';
        }

        if (DocumentTypes::isThermal($this->paper)) {
            return $this->thermalLines($rows, $columns);
        }

        $head = '';

        foreach ($columns as $column) {
            $head .= '<th'.(in_array($column, self::NUMERIC_COLUMNS, true) ? ' class="num"' : '').'>'.self::escape($this->columnLabel($column)).'</th>';
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr>';

            foreach ($columns as $column) {
                $body .= '<td'.(in_array($column, self::NUMERIC_COLUMNS, true) ? ' class="num"' : '').'>'.self::escape($this->cell($row, $column)).'</td>';
            }

            $body .= '</tr>';
        }

        return '<table class="b lines"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /** Narrow paper: the name on its own line, then the details and the amount. */
    private function thermalLines(array $rows, array $columns): string
    {
        $name = in_array('item_name', $columns, true) ? 'item_name' : (in_array('description', $columns, true) ? 'description' : null);
        $amount = in_array('total', $columns, true) ? 'total' : (in_array('amount', $columns, true) ? 'amount' : null);
        $html = '';

        foreach ($rows as $row) {
            if ($name !== null) {
                $html .= '<tr><td colspan="2">'.self::escape($this->cell($row, $name)).'</td></tr>';
            }

            $parts = [];

            if (in_array('qty', $columns, true) && in_array('unit_price', $columns, true)) {
                $parts[] = $this->cell($row, 'qty').' × '.$this->cell($row, 'unit_price');
            }

            foreach ($columns as $column) {
                if (in_array($column, [$name, $amount, 'discount'], true)
                    || (in_array($column, ['qty', 'unit_price'], true) && in_array('qty', $columns, true) && in_array('unit_price', $columns, true))) {
                    continue;
                }

                $parts[] = $this->cell($row, $column);
            }

            $parts = array_values(array_filter($parts, fn (string $part) => $part !== ''));
            $html .= '<tr><td>'.self::escape(implode(' · ', $parts)).'</td><td class="num">'.self::escape($amount === null ? '' : $this->cell($row, $amount)).'</td></tr>';

            if (in_array('discount', $columns, true) && self::isMoney($row['discount'] ?? null) && ! self::isZero($row['discount'])) {
                $html .= '<tr><td>'.self::escape($this->label('totals.discount')).'</td><td class="num">'.self::escape($this->money(self::negate($row['discount']))).'</td></tr>';
            }
        }

        return '<table class="b lines">'.$html.'</table>';
    }

    private function totals(array $block): string
    {
        $totals = $this->data['totals'] ?? null;

        if (! is_array($totals)) {
            return '';
        }

        $show = is_array($block['show'] ?? null) ? $block['show'] : TemplateSchema::TOTALS;
        $rows = '';
        $row = fn (string $label, string $value, bool $strong = false) => '<tr'.($strong ? ' class="strong"' : '').'><td>'.self::escape($label).'</td><td class="num">'.self::escape($value).'</td></tr>';

        if (in_array('subtotal', $show, true) && self::isMoney($totals['subtotal'] ?? null)) {
            $rows .= $row($this->label('totals.subtotal'), $this->money($totals['subtotal']));
        }

        if (in_array('discount', $show, true) && self::isMoney($totals['discount'] ?? null) && ! self::isZero($totals['discount'])) {
            $rows .= $row($this->label('totals.discount'), $this->money(self::negate($totals['discount'])));
        }

        // TPL-03: tax lines are locked on.
        foreach ((array) ($totals['tax_lines'] ?? []) as $line) {
            if (is_array($line) && self::isMoney($line['tax'] ?? null)) {
                $name = (string) ($line['name'] ?? '');
                $rate = $line['rate'] ?? null;
                $label = $rate === null || $rate === '' ? $name : $this->label('totals.tax_line', ['name' => $name, 'rate' => (string) $rate]);
                $rows .= $row(trim($label), $this->money($line['tax']));
            }
        }

        if (in_array('tax', $show, true) && self::isMoney($totals['tax'] ?? null)) {
            $rows .= $row($this->label('totals.tax'), $this->money($totals['tax']));
        }

        if (in_array('total', $show, true) && self::isMoney($totals['total'] ?? null)) {
            $rows .= $row($this->label('totals.total'), $this->money($totals['total']), true);
        }

        if (in_array('dual', $show, true) && self::isMoney($totals['dual'] ?? null)) {
            $rows .= $row($this->label('totals.total_in', ['currency' => $totals['dual']['currency']]), $this->money($totals['dual']));
        }

        return $rows === '' ? '' : '<table class="b kv">'.$rows.'</table>';
    }

    private function payments(array $block): string
    {
        $rows = '';

        foreach ((array) ($this->data['payments'] ?? []) as $payment) {
            if (! is_array($payment) || ! self::isMoney($payment['amount'] ?? null)) {
                continue;
            }

            $label = implode(' · ', array_filter([(string) ($payment['method'] ?? '') ?: $this->label('payments.payment'), (string) ($payment['reference'] ?? '')], fn ($p) => $p !== ''));
            $rows .= '<tr><td>'.self::escape($label).'</td><td class="num">'.self::escape($this->money($payment['amount'])).'</td></tr>';
        }

        $change = $this->data['change'] ?? null;

        if (($block['show_change'] ?? true) === true && self::isMoney($change) && ! self::isZero($change)) {
            $rows .= '<tr><td>'.self::escape($this->label('payments.change')).'</td><td class="num">'.self::escape($this->money($change)).'</td></tr>';
        }

        return $rows === '' ? '' : '<table class="b kv">'.$rows.'</table>';
    }

    private function qr(array $block): string
    {
        $content = trim($this->merge((string) ($block['content'] ?? '')));

        if ($content === '') {
            return '';
        }

        $size = self::int($block['size'] ?? 25, 15, 60);

        return '<div class="b code '.$this->align($block, 'center').'"><img src="'.$this->codes->qrDataUri($content).'" alt="" style="width: '.$size.'mm; height: '.$size.'mm"></div>';
    }

    private function barcode(array $block): string
    {
        $content = trim($this->merge((string) ($block['content'] ?? '')));
        $uri = $content === '' ? null : $this->codes->code128DataUri($content);

        if ($uri === null) {
            return '';
        }

        return '<div class="b code '.$this->align($block, 'center').'"><img src="'.$uri.'" alt="" style="height: '.self::int($block['height'] ?? 10, 5, 30).'mm; max-width: 100%"><div class="s-small">'.self::escape($content).'</div></div>';
    }

    private function signature(array $block): string
    {
        $label = trim((string) ($block['label'] ?? ''));

        return '<div class="b sig"><div class="sig-line"></div><div>'.self::escape($label === '' ? $this->label('signature') : $label).'</div></div>';
    }

    private function terms(array $block): string
    {
        $text = trim($this->merge((string) ($block['text'] ?? '')));

        return $text === '' ? '' : '<div class="b terms s-small">'.nl2br(self::escape($text), false).'</div>';
    }

    /** TPL-03: the tax authority's block, locked. Nothing when the company's country needs none. */
    private function fiscal(): string
    {
        $fiscal = $this->data['fiscal'] ?? null;

        if (! is_array($fiscal)) {
            return '';
        }

        $authority = (string) ($fiscal['authority'] ?? 'other');
        $status = self::pick($fiscal['status'] ?? null, ['waiting', 'pending', 'accepted', 'rejected', 'off'], 'pending');
        $html = '<div class="w-medium">'.self::escape($this->label('fiscal.authority.'.$authority, [], $this->label('fiscal.authority.other'))).'</div>'
            .'<div>'.self::escape($this->label('fiscal.status.'.$status)).'</div>';

        foreach (self::FISCAL_ROWS as $key) {
            $value = trim((string) ($fiscal[$key] ?? ''));

            if ($value !== '') {
                $html .= '<div>'.self::escape($this->label('fields.fiscal.'.$key).' '.$value).'</div>';
            }
        }

        $qr = trim((string) ($fiscal['qr'] ?? ''));

        if ($status === 'accepted' && $qr !== '') {
            $html .= '<div class="code"><img src="'.$this->codes->qrDataUri($qr).'" alt="" style="width: 25mm; height: 25mm"></div>';
        }

        if ($status !== 'accepted') {
            $html .= '<div class="s-small">'.self::escape($this->label('fiscal.help.'.$status)).'</div>';
        }

        return '<div class="b fiscal">'.$html.'</div>';
    }

    private function row(array $block): string
    {
        $columns = array_values(array_filter((array) ($block['columns'] ?? []), 'is_array'));

        if (DocumentTypes::isThermal($this->paper)) {
            return implode('', array_map(fn (array $column) => $this->blocks($column, true), $columns));
        }

        $cells = '';

        foreach (array_slice($columns, 0, 2) as $column) {
            $cells .= '<td>'.$this->blocks($column, true).'</td>';
        }

        return '<table class="b row2"><tr>'.$cells.'</tr></table>';
    }

    // ---- Values -----------------------------------------------------------

    private function value(string $path): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** A value as printed: money with its currency code first, rates with %, other scalars as they are. */
    private function display(mixed $value, string $key = ''): string
    {
        return match (true) {
            self::isMoney($value) => $this->money($value),
            is_bool($value) => $value ? '✓' : '',
            is_scalar($value) => str_ends_with($key, '_rate') && (string) $value !== '' ? $value.'%' : (string) $value,
            default => '',
        };
    }

    private function cell(array $row, string $column): string
    {
        $value = $row;

        foreach (explode('.', $column) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return $this->display($value, $column);
    }

    private function merge(string $text): string
    {
        return (string) preg_replace_callback(self::MERGE, fn (array $m) => $this->display($this->value($m[1]), $m[1]), $text);
    }

    /** CUR-01: "KES 1,125.00", "CDF 135,000" (en), "KES 1 125,00" (fr); the currency code first. */
    private function money(array $money): string
    {
        $currency = (string) $money['currency'];
        $decimals = (int) ($this->data['currencies'][$currency] ?? 2);
        $minor = (string) $money['minor'];
        $negative = str_starts_with($minor, '-');
        $digits = ltrim(ltrim($minor, '-'), '0');
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = $decimals > 0 ? substr($digits, 0, -$decimals) : $digits;
        $fraction = $decimals > 0 ? substr($digits, -$decimals) : '';
        [$group, $point] = $this->language === 'fr' ? [' ', ','] : [',', '.'];
        $whole = strrev(implode($group, str_split(strrev($whole), 3)));

        return $currency.' '.($negative ? '-' : '').$whole.($fraction === '' ? '' : $point.$fraction);
    }

    // ---- Labels -----------------------------------------------------------

    /** The app's wording for $key in the template's language; both side by side when they differ (TPL-02). */
    private function label(string $key, array $replace = [], ?string $fallback = null): string
    {
        $one = function (string $language) use ($key, $replace, $fallback): string {
            $text = data_get($this->labels[$language], $key);

            if (! is_string($text)) {
                return $fallback ?? '';
            }

            foreach ($replace as $name => $value) {
                $text = str_replace(':'.$name, $value, $text);
            }

            return $text;
        };

        if ($this->language !== 'both') {
            return $one($this->language);
        }

        $en = $one('en');
        $fr = $one('fr');

        return $en === $fr || $fr === '' ? $en : "{$en} / {$fr}";
    }

    private function fieldLabel(string $path): string
    {
        if (isset($this->customLabels[$path])) {
            return $this->customLabels[$path];
        }

        if ($path === 'document.number') {
            return $this->label('numbers.'.self::typeKey($this->type));
        }

        return $this->label('fields.'.$path, [], $path);
    }

    private function columnLabel(string $column): string
    {
        return $this->customLabels[$column] ?? $this->label('columns.'.$column, [], $column);
    }

    private function align(array $block, string $default): string
    {
        return 't-'.self::pick($block['align'] ?? null, ['left', 'center', 'right'], $default);
    }

    // ---- Helpers ----------------------------------------------------------

    public static function hasFiscal(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'fiscal') {
                return true;
            }

            if (($block['type'] ?? null) === 'row') {
                foreach ((array) ($block['columns'] ?? []) as $column) {
                    if (is_array($column) && self::hasFiscal($column)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function isMoney(mixed $value): bool
    {
        return is_array($value) && is_string($value['currency'] ?? null) && preg_match('/^-?\d+$/', (string) ($value['minor'] ?? '')) === 1;
    }

    private static function isZero(array $money): bool
    {
        return ltrim(ltrim((string) $money['minor'], '-'), '0') === '';
    }

    private static function negate(array $money): array
    {
        $minor = (string) $money['minor'];

        return [...$money, 'minor' => str_starts_with($minor, '-') ? substr($minor, 1) : '-'.$minor];
    }

    private static function pick(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function int(mixed $value, int $min, int $max): int
    {
        return is_int($value) ? max($min, min($max, $value)) : $min;
    }
}
