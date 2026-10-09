<?php

namespace App\Core\DocumentTemplates;

use App\Core\Configuration\PayloadSchema;

/**
 * TPL-01..TPL-03, TPL-05: what a template payload may hold.
 *
 *   {paper: 58mm|80mm|A4|A5, margins: {top, right, bottom, left} (mm),
 *    language: en|fr|both, blocks: [block], variants: [{id, name,
 *    applies_when: {customer_tags: [...], conditions: [{field, op, value}]},
 *    paper?, margins?, language?, blocks}]}
 *
 * Empty tenant texts arrive as null (the API trims empty strings).
 *
 * Blocks: text, field, logo, lines, totals, payments, qr, barcode,
 * signature, terms, spacer, divider, fiscal, and (A4/A5 only) row, a
 * two-column row of blocks. Merge fields (`{{customer.name}}`, a field
 * block's `field`, a table's columns) must be ones the type's data source
 * offers.
 *
 * Locked (TPL-03): where the company's country pack requires fiscal data
 * for the type, the fiscal block must be present (once) in the template
 * and in every variant, and so must a totals block, which always prints
 * its tax lines.
 */
final class TemplateSchema
{
    public const BLOCKS = ['text', 'field', 'logo', 'lines', 'totals', 'payments', 'qr', 'barcode', 'signature', 'terms', 'spacer', 'divider', 'fiscal', 'row'];

    public const LANGUAGES = ['en', 'fr', 'both'];

    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'empty', 'not_empty'];

    public const TOTALS = ['subtotal', 'discount', 'tax', 'total', 'dual'];

    public const MAX_BLOCKS = 80;

    private const ID = ['type' => 'string', 'pattern' => '/^[A-Za-z0-9_-]{1,40}$/'];

    private const ALIGN = ['type' => 'string', 'enum' => ['left', 'center', 'right']];

    private const MARGIN = ['type' => 'integer', 'min' => 0, 'max' => 30];

    private const MARGINS = ['type' => 'object', 'additional' => false, 'required' => ['top', 'right', 'bottom', 'left'], 'properties' => [
        'top' => self::MARGIN, 'right' => self::MARGIN, 'bottom' => self::MARGIN, 'left' => self::MARGIN,
    ]];

    /** Each block type's own properties (all have id and type). */
    private const BLOCK_SCHEMAS = [
        'text' => ['required' => ['text'], 'properties' => [
            'text' => ['type' => 'string', 'max' => 2000, 'nullable' => true],
            'align' => self::ALIGN,
            'size' => ['type' => 'string', 'enum' => ['small', 'normal', 'large']],
            'weight' => ['type' => 'string', 'enum' => ['regular', 'medium']],
        ]],
        'field' => ['required' => ['field'], 'properties' => [
            'field' => ['type' => 'string', 'max' => 120],
            'label' => ['type' => 'boolean'],
            'align' => self::ALIGN,
        ]],
        'logo' => ['properties' => ['align' => self::ALIGN, 'height' => ['type' => 'integer', 'min' => 5, 'max' => 60]]],
        'lines' => ['required' => ['columns'], 'properties' => [
            'columns' => ['type' => 'array', 'min' => 1, 'max' => 10, 'items' => ['type' => 'string', 'max' => 80]],
        ]],
        'totals' => ['properties' => [
            'show' => ['type' => 'array', 'max' => 5, 'items' => ['type' => 'string', 'enum' => self::TOTALS]],
            'tax_lines' => ['type' => 'boolean'],
        ]],
        'payments' => ['properties' => ['show_change' => ['type' => 'boolean']]],
        'qr' => ['required' => ['content'], 'properties' => [
            'content' => ['type' => 'string', 'max' => 500],
            'size' => ['type' => 'integer', 'min' => 15, 'max' => 60],
            'align' => self::ALIGN,
        ]],
        'barcode' => ['required' => ['content'], 'properties' => [
            'content' => ['type' => 'string', 'max' => 200],
            'height' => ['type' => 'integer', 'min' => 5, 'max' => 30],
            'align' => self::ALIGN,
        ]],
        'signature' => ['properties' => ['label' => ['type' => 'string', 'max' => 100, 'nullable' => true]]],
        'terms' => ['required' => ['text'], 'properties' => ['text' => ['type' => 'string', 'max' => 4000, 'nullable' => true]]],
        'spacer' => ['properties' => ['size' => ['type' => 'integer', 'min' => 1, 'max' => 40]]],
        'divider' => ['properties' => ['style' => ['type' => 'string', 'enum' => ['solid', 'dashed']]]],
        'fiscal' => ['properties' => []],
        'row' => ['required' => ['columns'], 'properties' => [
            'columns' => ['type' => 'array', 'min' => 2, 'max' => 2, 'items' => ['type' => 'array', 'max' => 30]],
        ]],
    ];

    /**
     * Problems that keep $payload from being published for $type, where
     * the fiscal block is required when $fiscalRequired.
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function problems(array $payload, string $type, bool $fiscalRequired): array
    {
        $problems = PayloadSchema::check($payload, [
            'type' => 'object',
            'additional' => false,
            'required' => ['paper', 'margins', 'language', 'blocks'],
            'properties' => [
                'paper' => ['type' => 'string', 'enum' => DocumentTypes::PAPERS],
                'margins' => self::MARGINS,
                'language' => ['type' => 'string', 'enum' => self::LANGUAGES],
                'blocks' => ['type' => 'array', 'max' => self::MAX_BLOCKS],
                'variants' => ['type' => 'array', 'max' => 10, 'unique' => 'id', 'items' => [
                    'type' => 'object',
                    'additional' => false,
                    'required' => ['id', 'name', 'applies_when', 'blocks'],
                    'properties' => [
                        'id' => self::ID,
                        'name' => ['type' => 'string', 'min' => 1, 'max' => 100],
                        'applies_when' => ['type' => 'object', 'additional' => false, 'properties' => [
                            'customer_tags' => ['type' => 'array', 'max' => 20, 'items' => ['type' => 'string', 'min' => 1, 'max' => 50]],
                            'conditions' => ['type' => 'array', 'max' => 10, 'items' => [
                                'type' => 'object',
                                'additional' => false,
                                'required' => ['field', 'op'],
                                'properties' => [
                                    'field' => ['type' => 'string', 'max' => 120],
                                    'op' => ['type' => 'string', 'enum' => self::OPERATORS],
                                    'value' => ['type' => 'any'],
                                ],
                            ]],
                        ]],
                        'paper' => ['type' => 'string', 'enum' => DocumentTypes::PAPERS],
                        'margins' => self::MARGINS,
                        'language' => ['type' => 'string', 'enum' => self::LANGUAGES],
                        'blocks' => ['type' => 'array', 'max' => self::MAX_BLOCKS],
                    ],
                ]],
            ],
        ]);

        $sources = app(DataSources::class);
        $fields = $sources->fields($type);
        $columns = $sources->columns($type);
        $fiscalAllowed = DocumentTypes::TYPES[$type]['fiscal'] ?? false;

        $layouts = [['', $payload]];

        foreach ((array) ($payload['variants'] ?? []) as $index => $variant) {
            if (is_array($variant)) {
                $layouts[] = ["variants.{$index}.", [...$payload, ...$variant]];

                foreach ((array) ($variant['applies_when']['conditions'] ?? []) as $c => $condition) {
                    if (is_array($condition) && is_string($condition['field'] ?? null) && ! self::knownPath($condition['field'], $fields)) {
                        $problems[] = PayloadSchema::problem("variants.{$index}.applies_when.conditions.{$c}.field", 'unknown_field', ['field' => $condition['field']]);
                    }
                }
            }
        }

        foreach ($layouts as [$prefix, $layout]) {
            if (! is_array($layout['blocks'] ?? null)) {
                continue;
            }

            $paper = is_string($layout['paper'] ?? null) ? $layout['paper'] : DocumentTypes::paper($type);
            $fiscalCount = 0;
            $ids = [];
            self::blocks($layout['blocks'], $prefix.'blocks', $paper, $fields, $columns, $fiscalAllowed, false, $problems, $fiscalCount, $ids);

            if ($fiscalRequired && $fiscalCount === 0) {
                $problems[] = PayloadSchema::problem($prefix.'blocks', 'fiscal_required');
            }

            // TPL-03: the tax lines print with the totals, so the totals are locked on too.
            if ($fiscalRequired && ! TemplateRenderer::hasBlock(array_values(array_filter($layout['blocks'], 'is_array')), 'totals')) {
                $problems[] = PayloadSchema::problem($prefix.'blocks', 'totals_required');
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $columns
     * @param  array<string, true>  $ids
     */
    private static function blocks(array $blocks, string $path, string $paper, array $fields, array $columns, bool $fiscalAllowed, bool $nested, array &$problems, int &$fiscalCount, array &$ids): void
    {
        foreach ($blocks as $index => $block) {
            $at = "{$path}.{$index}";

            if (! is_array($block) || ! is_string($block['type'] ?? null) || ! in_array($block['type'], self::BLOCKS, true)) {
                $problems[] = PayloadSchema::problem($at.'.type', 'unknown_block');

                continue;
            }

            $type = $block['type'];
            $schema = self::BLOCK_SCHEMAS[$type];
            $problems = [...$problems, ...PayloadSchema::check($block, [
                'type' => 'object',
                'additional' => false,
                'required' => ['id', 'type', ...($schema['required'] ?? [])],
                'properties' => ['id' => self::ID, 'type' => ['type' => 'string'], ...$schema['properties']],
            ], $at)];

            if (is_string($block['id'] ?? null)) {
                if (isset($ids[$block['id']])) {
                    $problems[] = PayloadSchema::problem($at.'.id', 'duplicate', ['value' => $block['id']]);
                }

                $ids[$block['id']] = true;
            }

            match ($type) {
                'fiscal' => $fiscalAllowed
                    ? (++$fiscalCount > 1 ? $problems[] = PayloadSchema::problem($at, 'fiscal_twice') : null)
                    : $problems[] = PayloadSchema::problem($at, 'fiscal_not_allowed'),
                'totals' => ($block['tax_lines'] ?? true) === false ? $problems[] = PayloadSchema::problem($at.'.tax_lines', 'tax_lines_locked') : null,
                'field' => is_string($block['field'] ?? null) && ! self::knownPath($block['field'], $fields)
                    ? $problems[] = PayloadSchema::problem($at.'.field', 'unknown_field', ['field' => $block['field']]) : null,
                'lines' => self::columnProblems($block, $at, $columns, $problems),
                'text', 'terms', 'qr', 'barcode' => self::mergeProblems((string) ($block['text'] ?? $block['content'] ?? ''), $at, $fields, $problems),
                'row' => self::row($block, $at, $paper, $fields, $columns, $fiscalAllowed, $nested, $problems, $fiscalCount, $ids),
                default => null,
            };
        }
    }

    private static function row(array $block, string $at, string $paper, array $fields, array $columns, bool $fiscalAllowed, bool $nested, array &$problems, int &$fiscalCount, array &$ids): void
    {
        if ($nested) {
            $problems[] = PayloadSchema::problem($at, 'row_nested');

            return;
        }

        if (DocumentTypes::isThermal($paper)) {
            $problems[] = PayloadSchema::problem($at, 'row_on_thermal');
        }

        foreach ((array) ($block['columns'] ?? []) as $c => $column) {
            if (is_array($column) && array_is_list($column)) {
                self::blocks($column, "{$at}.columns.{$c}", $paper, $fields, $columns, $fiscalAllowed, true, $problems, $fiscalCount, $ids);
            }
        }
    }

    private static function columnProblems(array $block, string $at, array $columns, array &$problems): void
    {
        foreach ((array) ($block['columns'] ?? []) as $c => $column) {
            if (is_string($column) && ! isset($columns[$column])) {
                $problems[] = PayloadSchema::problem("{$at}.columns.{$c}", 'unknown_column', ['field' => $column]);
            }
        }

        if (is_array($block['columns'] ?? null) && count(array_unique(array_filter($block['columns'], 'is_string'))) !== count($block['columns'])) {
            $problems[] = PayloadSchema::problem("{$at}.columns", 'duplicate', ['value' => implode(', ', array_diff_assoc($block['columns'], array_unique($block['columns'])))]);
        }
    }

    private static function mergeProblems(string $text, string $at, array $fields, array &$problems): void
    {
        preg_match_all(TemplateRenderer::MERGE, $text, $matches);

        foreach (array_unique($matches[1]) as $path) {
            if (! self::knownPath($path, $fields)) {
                $problems[] = PayloadSchema::problem($at, 'unknown_field', ['field' => $path]);
            }
        }
    }

    /** A catalogue path; custom fields (`*.custom.*`) of records not yet catalogued are allowed too (LAY-07). */
    public static function knownPath(string $path, array $fields): bool
    {
        return isset($fields[$path]) || preg_match('/^(company|customer)\.custom\.[a-z][a-z0-9_]{0,59}$/', $path) === 1;
    }
}
