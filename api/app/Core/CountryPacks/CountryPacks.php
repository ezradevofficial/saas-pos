<?php

namespace App\Core\CountryPacks;

use App\Core\CountryPacks\Models\CountryPack;
use App\Core\CountryPacks\Models\PackTaxCode;
use App\Core\Rbac\Console\SyncPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Publishes country pack versions (CP-01, CP-03) into the global tables as
 * the schema owner (the runtime role may only read them). The same content
 * again is a no-op; changed content becomes the next version, with a
 * summary of what changed from the previous one. Earlier versions are kept.
 */
class CountryPacks
{
    /**
     * @return array{0: CountryPack, 1: bool} the version in force and whether it was just created
     */
    public function publish(PackFile $file): array
    {
        $db = DB::connection(SyncPermissions::OWNER_CONNECTION);

        return $db->transaction(function () use ($db, $file) {
            // One publisher per pack at a time; the advisory lock ends with the transaction.
            $db->select('select pg_advisory_xact_lock(hashtext(?))', ['country_pack:'.$file->code()]);

            $latest = CountryPack::on(SyncPermissions::OWNER_CONNECTION)
                ->where('code', $file->code())->orderByDesc('version')->first();

            if ($latest !== null && hash_equals($latest->content_hash, $file->hash())) {
                return [$latest, false];
            }

            $previous = $latest === null ? [] : PackTaxCode::on(SyncPermissions::OWNER_CONNECTION)
                ->where('country_pack_id', $latest->id)->get()
                ->map(fn (PackTaxCode $row) => $this->row($row->only(['code', 'kind', 'rate', 'needs_confirmation', 'effective_from', 'effective_to', 'fiscal_code'])))
                ->all();
            $current = array_map(fn (array $row) => $this->row($row), $file->taxCodes());

            $pack = CountryPack::on(SyncPermissions::OWNER_CONNECTION)->create([
                'code' => $file->code(),
                'version' => ($latest?->version ?? 0) + 1,
                'content_hash' => $file->hash(),
                'published_at' => CarbonImmutable::now(),
                'summary' => [
                    'notes' => $file->data['notes'] ?? null,
                    'sources' => array_values($file->data['sources']),
                    'todo' => array_values($file->data['todo']),
                    'tax_codes' => count(array_unique(array_column($current, 'code'))),
                    'needs_confirmation' => array_values(array_unique(array_column(array_filter($current, fn (array $row) => $row['needs_confirmation']), 'code'))),
                    'changes' => $this->changes($previous, $current),
                ],
            ]);

            foreach ($file->taxCodes() as $row) {
                PackTaxCode::on(SyncPermissions::OWNER_CONNECTION)->create([
                    'country_pack_id' => $pack->id,
                    'code' => $row['code'],
                    'kind' => $row['kind'],
                    'rate' => $row['rate'],
                    'needs_confirmation' => $row['needs_confirmation'],
                    'effective_from' => $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'fiscal_code' => $row['fiscal_code'],
                ]);
            }

            return [$pack, true];
        });
    }

    /**
     * CP-03: the change summary tenants see, by code: added, removed and
     * changed (any field of any period).
     *
     * @param  list<array<string, mixed>>  $previous
     * @param  list<array<string, mixed>>  $current
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function changes(array $previous, array $current): array
    {
        $group = function (array $rows): array {
            $byCode = [];

            foreach ($rows as $row) {
                $byCode[$row['code']][$row['effective_from']] = $row;
            }

            foreach ($byCode as &$periods) {
                ksort($periods);
            }

            ksort($byCode);

            return $byCode;
        };

        $before = $group($previous);
        $after = $group($current);

        return [
            'added' => array_values(array_diff(array_keys($after), array_keys($before))),
            'removed' => array_values(array_diff(array_keys($before), array_keys($after))),
            'changed' => array_values(array_filter(
                array_keys(array_intersect_key($after, $before)),
                fn (string $code) => $after[$code] !== $before[$code],
            )),
        ];
    }

    /** One comparable form for a row from the file or the database. */
    private function row(array $row): array
    {
        $date = fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->toDateString();

        return [
            'code' => $row['code'],
            'kind' => $row['kind'],
            'rate' => $row['rate'] === null ? null : (string) $row['rate'],
            'needs_confirmation' => (bool) $row['needs_confirmation'],
            'effective_from' => $date($row['effective_from']),
            'effective_to' => $date($row['effective_to']),
            'fiscal_code' => $row['fiscal_code'],
        ];
    }
}
