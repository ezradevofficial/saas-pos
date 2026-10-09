<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-01: the template each document type prints with until a tenant
 * publishes its own. The POS receipt default lays out what the till
 * printed before templates existed (pos/src/pos/receiptHtml.js), so
 * nothing changes for a tenant that never opens the designer.
 */
final class DefaultTemplates
{
    public static function for(string $type, string $language = 'en'): array
    {
        $language = in_array($language, ['en', 'fr'], true) ? $language : 'en';
        $paper = DocumentTypes::paper($type);
        $thermal = DocumentTypes::isThermal($paper);
        $margin = $thermal ? 3 : 15;

        return [
            'paper' => $paper,
            'margins' => ['top' => $margin, 'right' => $margin, 'bottom' => $margin, 'left' => $margin],
            'language' => $language,
            'blocks' => match (true) {
                $type === 'pos.receipt' || $type === 'pos.refund_receipt' => self::receipt($type),
                default => self::a4($type),
            },
            'variants' => [],
        ];
    }

    private static function receipt(string $type): array
    {
        $refund = $type === 'pos.refund_receipt';

        return array_values(array_filter([
            ['id' => 'header', 'type' => 'text', 'text' => '{{company.legal_name}}', 'align' => 'center', 'size' => 'large', 'weight' => 'medium'],
            ['id' => 'tax-id', 'type' => 'field', 'field' => 'company.tax_id', 'label' => true],
            ['id' => 'place', 'type' => 'text', 'text' => '{{branch.name}} · {{location.name}}', 'align' => 'center', 'size' => 'small', 'weight' => 'regular'],
            ['id' => 'divider-1', 'type' => 'divider', 'style' => 'solid'],
            ['id' => 'number', 'type' => 'field', 'field' => 'document.number', 'label' => true],
            ['id' => 'date', 'type' => 'field', 'field' => 'document.date', 'label' => true],
            ['id' => 'cashier', 'type' => 'field', 'field' => 'document.cashier', 'label' => true],
            ['id' => 'customer', 'type' => 'field', 'field' => 'customer.name', 'label' => true],
            $refund ? ['id' => 'reference', 'type' => 'field', 'field' => 'document.reference', 'label' => true] : null,
            $refund ? ['id' => 'reason', 'type' => 'field', 'field' => 'document.reason', 'label' => true] : null,
            ['id' => 'divider-2', 'type' => 'divider', 'style' => 'solid'],
            ['id' => 'lines', 'type' => 'lines', 'columns' => ['item_name', 'qty', 'unit_price', 'discount', 'total']],
            ['id' => 'divider-3', 'type' => 'divider', 'style' => 'solid'],
            ['id' => 'totals', 'type' => 'totals', 'show' => ['subtotal', 'discount', 'total', 'dual'], 'tax_lines' => true],
            ['id' => 'divider-4', 'type' => 'divider', 'style' => 'solid'],
            ['id' => 'payments', 'type' => 'payments', 'show_change' => true],
            ['id' => 'fiscal', 'type' => 'fiscal'],
        ]));
    }

    private static function a4(string $type): array
    {
        $columns = array_keys(app(DataSources::class)->columns($type));
        $documentFields = array_keys(app(DataSources::class)->get($type)->documentFields());
        $hasTotals = ! in_array($type, ['letter', 'stores.delivery_note'], true);

        $right = [['id' => 'number', 'type' => 'field', 'field' => 'document.number', 'label' => true], ['id' => 'date', 'type' => 'field', 'field' => 'document.date', 'label' => true]];

        foreach ($documentFields as $index => $path) {
            $right[] = ['id' => 'doc-'.($index + 1), 'type' => 'field', 'field' => $path, 'label' => true];
        }

        $party = match ($type) {
            'payroll.payslip', 'procurement.po' => [],
            default => [
                ['id' => 'customer', 'type' => 'field', 'field' => 'customer.name', 'label' => true],
                ['id' => 'customer-tax', 'type' => 'field', 'field' => 'customer.tax_id', 'label' => true],
                ['id' => 'customer-address', 'type' => 'field', 'field' => 'customer.address', 'label' => false],
            ],
        };

        return array_values(array_filter([
            ['id' => 'head', 'type' => 'row', 'columns' => [
                [
                    ['id' => 'logo', 'type' => 'logo', 'align' => 'left', 'height' => 15],
                    ['id' => 'company', 'type' => 'text', 'text' => '{{company.legal_name}}', 'align' => 'left', 'size' => 'large', 'weight' => 'medium'],
                    ['id' => 'company-tax', 'type' => 'field', 'field' => 'company.tax_id', 'label' => true],
                    ['id' => 'company-address', 'type' => 'field', 'field' => 'company.address', 'label' => false],
                ],
                $right,
            ]],
            ['id' => 'divider-1', 'type' => 'divider', 'style' => 'solid'],
            ...$party,
            ['id' => 'spacer-1', 'type' => 'spacer', 'size' => 4],
            $columns === [] || $type === 'letter' ? null : ['id' => 'lines', 'type' => 'lines', 'columns' => $columns],
            $type === 'letter' ? ['id' => 'body', 'type' => 'text', 'text' => '', 'align' => 'left', 'size' => 'normal', 'weight' => 'regular'] : null,
            $hasTotals ? ['id' => 'totals', 'type' => 'totals', 'show' => $type === 'sales.invoice' || $type === 'sales.quote' || $type === 'procurement.po' ? ['subtotal', 'discount', 'tax', 'total'] : ['total'], 'tax_lines' => true] : null,
            ['id' => 'spacer-2', 'type' => 'spacer', 'size' => 6],
            in_array($type, ['sales.invoice', 'sales.quote', 'procurement.po'], true) ? ['id' => 'terms', 'type' => 'terms', 'text' => ''] : null,
            in_array($type, ['payroll.payslip', 'stores.delivery_note', 'letter'], true) ? ['id' => 'signature', 'type' => 'signature', 'label' => ''] : null,
            DocumentTypes::TYPES[$type]['fiscal'] ? ['id' => 'fiscal', 'type' => 'fiscal'] : null,
        ]));
    }
}
