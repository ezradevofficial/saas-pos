<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-01: the document types a template can be designed for; each is the
 * `key` of a `template` configuration document. `fiscal` types may carry
 * the tax authority's block (KRA eTIMS, DRC DGI) when the company's
 * country pack requires it (TPL-03, CP-01).
 *
 * Only the POS receipts have real data today; the other types ship with a
 * sample data source and a default template, for when their modules come.
 */
final class DocumentTypes
{
    public const PAPERS = ['58mm', '80mm', 'A4', 'A5'];

    public const THERMAL = ['58mm', '80mm'];

    /** key => default paper, whether the tax authority may be involved, and the module whose records it prints. */
    public const TYPES = [
        'pos.receipt' => ['paper' => '80mm', 'fiscal' => true, 'module' => 'pos'],
        'pos.refund_receipt' => ['paper' => '80mm', 'fiscal' => true, 'module' => 'pos'],
        'sales.invoice' => ['paper' => 'A4', 'fiscal' => true, 'module' => 'sales'],
        'sales.quote' => ['paper' => 'A4', 'fiscal' => false, 'module' => 'sales'],
        'procurement.po' => ['paper' => 'A4', 'fiscal' => false, 'module' => 'procurement'],
        'stores.delivery_note' => ['paper' => 'A4', 'fiscal' => false, 'module' => 'stores'],
        'payroll.payslip' => ['paper' => 'A4', 'fiscal' => false, 'module' => 'payroll'],
        'party.statement' => ['paper' => 'A4', 'fiscal' => false, 'module' => 'core'],
        'letter' => ['paper' => 'A4', 'fiscal' => false, 'module' => 'core'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function paper(string $type): string
    {
        return self::TYPES[$type]['paper'] ?? 'A4';
    }

    public static function isThermal(string $paper): bool
    {
        return in_array($paper, self::THERMAL, true);
    }

    /** The module whose records the type prints (RBAC-08: off while that module is inactive). */
    public static function module(string $type): string
    {
        return self::TYPES[$type]['module'] ?? 'core';
    }

    public static function label(string $type): string
    {
        return __('templates.types.'.str_replace('.', '_', $type));
    }
}
