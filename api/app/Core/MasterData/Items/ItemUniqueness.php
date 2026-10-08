<?php

namespace App\Core\MasterData\Items;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Review focus 4: an item's code (case-insensitive) and barcodes
 * (normalised) are unique among active items in the sharing scope: the
 * tenant when items are shared, the company when kept per company. Checked
 * by writers under the items sharing lock, so the scope cannot change in
 * between; the partial unique indexes catch the remaining race (two
 * writers at once), turned into the same validation errors.
 */
class ItemUniqueness
{
    /**
     * @param  list<string>  $barcodes  normalised, in request order
     *
     * @throws ValidationException code_taken | barcode_taken
     */
    public function assert(?string $companyId, string $code, array $barcodes, ?string $exceptItemId): void
    {
        $errors = [];

        $codeTaken = $this->inScope(Item::query()->active(), $companyId)
            ->where('code', $code)
            ->when($exceptItemId !== null, fn (Builder $q) => $q->whereKeyNot($exceptItemId))
            ->exists();

        if ($codeTaken) {
            $errors['code'] = [__('core.item.code_taken', ['code' => $code])];
        }

        if ($barcodes !== []) {
            $taken = $this->inScope(ItemBarcode::query()->whereNull('item_archived_at'), $companyId)
                ->whereIn('barcode', $barcodes)
                ->when($exceptItemId !== null, fn (Builder $q) => $q->where('item_id', '!=', $exceptItemId))
                ->pluck('barcode')->all();

            foreach ($barcodes as $index => $barcode) {
                if (in_array($barcode, $taken, true)) {
                    $errors["barcodes.{$index}.barcode"] = [__('core.item.barcode_taken', ['barcode' => $barcode])];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** The validation error for a unique index that caught a race. */
    public function fromViolation(UniqueConstraintViolationException $e): ValidationException
    {
        return str_contains($e->getMessage(), 'item_barcodes_')
            ? ValidationException::withMessages(['barcodes' => [__('core.item.barcode_taken_race')]])
            : ValidationException::withMessages(['code' => [__('core.item.code_taken_race')]]);
    }

    private function inScope(Builder $query, ?string $companyId): Builder
    {
        return $companyId === null ? $query->whereNull('company_id') : $query->where('company_id', $companyId);
    }
}
