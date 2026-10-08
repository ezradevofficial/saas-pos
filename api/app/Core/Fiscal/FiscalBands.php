<?php

namespace App\Core\Fiscal;

use App\Core\MasterData\Taxes\TaxCode;

/**
 * Each line's fiscal band: the `fiscal_code` of the company tax code the
 * module applied (MD-03, set from the country pack or by the business).
 * A line without a tax code, or whose tax code has no fiscal code (or one
 * the authority does not know), stops the document with a clear reason:
 * the band is never guessed.
 */
final class FiscalBands
{
    /**
     * @param  list<string>|null  $allowed  the bands the authority knows (null: any)
     * @return array<int, array{code: string, tax_code: TaxCode}> line_no => band
     */
    public static function resolve(FiscalDocument $document, ?array $allowed = null): array
    {
        $lines = $document->data['lines'];
        $ids = array_values(array_unique(array_filter(array_map(fn (array $line) => $line['tax_code_id'] ?? null, $lines))));
        $codes = $ids === [] ? collect() : TaxCode::query()->whereKey($ids)->with('company')->get()->keyBy('id');
        $bands = [];

        foreach ($lines as $line) {
            $name = (string) $line['item_name'];
            $code = isset($line['tax_code_id']) ? $codes->get($line['tax_code_id']) : null;

            if ($code === null) {
                throw new LocalRejection('tax_code_missing', __('fiscal.errors.tax_code_missing', ['item' => $name]));
            }

            $fiscal = strtoupper(trim((string) $code->fiscal_code));

            if ($fiscal === '') {
                throw new LocalRejection('fiscal_code_missing', __('fiscal.errors.fiscal_code_missing', ['item' => $name, 'code' => $code->code]));
            }

            if ($allowed !== null && ! in_array($fiscal, $allowed, true)) {
                throw new LocalRejection('fiscal_code_unknown', __('fiscal.errors.fiscal_code_unknown', ['code' => $code->code, 'fiscal_code' => $fiscal, 'allowed' => implode(', ', $allowed)]));
            }

            $bands[(int) $line['line_no']] = ['code' => $fiscal, 'tax_code' => $code];
        }

        return $bands;
    }
}
