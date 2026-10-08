<?php

namespace Tests\Feature\Core\CountryPacks;

use App\Core\CountryPacks\Models\CountryPack;
use App\Core\CountryPacks\Models\PackTaxCode;
use App\Core\CountryPacks\PackFile;
use App\Core\CountryPacks\PackLabels;
use App\Core\Rbac\Console\SyncPermissions;
use Database\Seeders\CountryPackCatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CP-01, CP-02, CP-03: country packs are versioned data published by the
// schema owner; the shipped KE and CD packs hold structure only, never an
// invented rate; the runtime role cannot write the global pack tables.
class PublishCountryPackTest extends TestCase
{
    use RefreshTenantDatabase;

    /** Test packs use the user-assigned code ZZ and are removed after each test (the owner commits outside the test transaction). */
    private const CODE = 'ZZ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(fn () => DB::connection(SyncPermissions::OWNER_CONNECTION)
            ->table('country_packs')->where('code', self::CODE)->delete());
    }

    private function pack(array $overrides = [], array $taxCodes = []): array
    {
        return array_merge([
            'code' => self::CODE,
            'notes' => 'Test pack.',
            'sources' => [],
            'todo' => ['VAT_STD: the standard rate.'],
            'tax_codes' => $taxCodes ?: [
                ['code' => 'VAT_STD', 'kind' => 'vat', 'rate' => null, 'needs_confirmation' => true, 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null],
                ['code' => 'VAT_ZERO', 'kind' => 'zero_rated', 'rate' => 0, 'needs_confirmation' => false, 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null],
            ],
        ], $overrides);
    }

    private function file(array $pack, int $flags = JSON_PRETTY_PRINT): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pack');
        file_put_contents($path, json_encode($pack, $flags | JSON_UNESCAPED_UNICODE));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function publish(array $pack, int $flags = JSON_PRETTY_PRINT): int
    {
        return $this->artisan('country-packs:publish', ['code' => self::CODE, '--file' => $this->file($pack, $flags)])->run();
    }

    public function test_the_shipped_packs_are_published_by_the_seeder_without_invented_rates(): void
    {
        foreach (['KE' => 4, 'CD' => 3] as $code => $count) {
            $pack = CountryPack::latest($code);
            $this->assertNotNull($pack, "{$code} is not published");
            $this->assertSame(1, $pack->version);
            $this->assertSame([], $pack->summary['sources']);
            $this->assertNotEmpty($pack->summary['todo']);

            $codes = $pack->taxCodes()->get()->keyBy('code');
            $this->assertCount($count, $codes);

            // Nothing is stated that cannot be confirmed: the standard rate is null and flagged.
            $this->assertNull($codes['VAT_STD']->rate);
            $this->assertTrue($codes['VAT_STD']->needs_confirmation);
            $this->assertSame('0.0000', $codes['VAT_ZERO']->rate);
            $this->assertFalse($codes['VAT_ZERO']->needs_confirmation);
            $this->assertNull($codes['VAT_EXEMPT']->rate);
            $this->assertSame('exempt', $codes['VAT_EXEMPT']->kind);

            foreach ($codes as $row) {
                $this->assertNull($row->fiscal_code);
                $this->assertSame('2026-01-01', $row->effective_from->toDateString());
                $this->assertTrue($row->kind === 'exempt' || $row->rate !== null || $row->needs_confirmation, "{$code} {$row->code}");
            }

            $this->assertSame(['VAT_STD', 'VAT_WHT'], CountryPack::latest('KE')->summary['needs_confirmation']);
        }

        // Only rates that are 0 by definition are filled in, in every shipped file.
        foreach (['KE', 'CD'] as $code) {
            $data = json_decode(file_get_contents(base_path("country-packs/{$code}/pack.json")), true);

            foreach ($data['tax_codes'] as $row) {
                $this->assertContains($row['rate'], [null, '0', 0], "{$code} {$row['code']} states a rate");
            }
        }
    }

    public function test_publishing_the_same_content_again_keeps_the_version(): void
    {
        $this->assertSame(0, $this->publish($this->pack()));
        $this->assertSame(1, CountryPack::latest(self::CODE)->version);

        // Same content, other formatting and key order: still the same version.
        $this->assertSame(0, $this->publish(array_reverse($this->pack(), true), 0));
        $this->artisan('country-packs:publish', ['code' => 'KE'])->expectsOutputToContain('unchanged')->assertSuccessful();

        $this->assertSame(1, CountryPack::where('code', self::CODE)->count());
        $this->assertSame(1, CountryPack::where('code', 'KE')->count());
        $this->assertSame(2, PackTaxCode::whereIn('country_pack_id', CountryPack::where('code', self::CODE)->select('id'))->count());
    }

    public function test_changed_content_is_the_next_version_with_a_change_summary(): void
    {
        $this->publish($this->pack());
        $first = CountryPack::latest(self::CODE);

        $changed = $this->pack(taxCodes: [
            ['code' => 'VAT_STD', 'kind' => 'vat', 'rate' => null, 'needs_confirmation' => true, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'fiscal_code' => null],
            ['code' => 'VAT_STD', 'kind' => 'vat', 'rate' => '12.5', 'needs_confirmation' => false, 'effective_from' => '2026-07-01', 'effective_to' => null, 'fiscal_code' => 'B'],
            ['code' => 'VAT_EXEMPT', 'kind' => 'exempt', 'rate' => null, 'needs_confirmation' => false, 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null],
        ]);
        $this->artisan('country-packs:publish', ['code' => self::CODE, '--file' => $this->file($changed)])
            ->expectsOutputToContain('version 2')->assertSuccessful();

        $second = CountryPack::latest(self::CODE);
        $this->assertSame(2, $second->version);
        // jsonb keeps its own key order.
        $this->assertEquals(['added' => ['VAT_EXEMPT'], 'removed' => ['VAT_ZERO'], 'changed' => ['VAT_STD']], $second->summary['changes']);
        $this->assertSame(['VAT_STD'], $second->summary['needs_confirmation']);
        $this->assertSame(['12.5000'], $second->taxCodes()->whereNotNull('rate')->pluck('rate')->all());

        // The earlier version is kept as it was.
        $this->assertSame(2, $first->taxCodes()->count());
    }

    public function test_a_pack_that_would_invent_or_contradict_a_rate_is_refused(): void
    {
        $row = ['code' => 'X', 'kind' => 'vat', 'rate' => null, 'needs_confirmation' => false, 'effective_from' => '2026-01-01', 'effective_to' => null, 'fiscal_code' => null];

        foreach ([
            'null rate not flagged' => [$row],
            'exempt with a rate' => [['kind' => 'exempt', 'rate' => '5'] + $row],
            'zero-rated not zero' => [['kind' => 'zero_rated', 'rate' => '1', 'needs_confirmation' => false] + $row],
            'rate above 100' => [['rate' => '101', 'needs_confirmation' => false] + $row],
            'float-like rate' => [['rate' => '12.50001', 'needs_confirmation' => false] + $row],
            'unknown kind' => [['kind' => 'sales_tax', 'needs_confirmation' => true] + $row],
            'ends before it starts' => [['needs_confirmation' => true, 'effective_to' => '2025-01-01'] + $row],
            'rate as a JSON float' => [['rate' => 12.5, 'needs_confirmation' => false] + $row],
            'overlapping periods' => [
                ['rate' => '10', 'needs_confirmation' => false, 'effective_to' => '2026-06-30'] + $row,
                ['rate' => '12.5', 'needs_confirmation' => false, 'effective_from' => '2026-06-30'] + $row,
            ],
            'two open-ended periods' => [
                ['rate' => '10', 'needs_confirmation' => false] + $row,
                ['rate' => '12.5', 'needs_confirmation' => false, 'effective_from' => '2026-07-01'] + $row,
            ],
            'kind changes between periods' => [
                ['rate' => '10', 'needs_confirmation' => false, 'effective_to' => '2026-06-30'] + $row,
                ['kind' => 'excise', 'rate' => '12.5', 'needs_confirmation' => false, 'effective_from' => '2026-07-01'] + $row,
            ],
        ] as $case => $codes) {
            $this->assertSame(1, $this->publish($this->pack(taxCodes: $codes)), $case);
        }

        $this->assertSame(1, $this->publish($this->pack(['code' => 'KE'])), 'a file for another pack');
        $this->assertSame(1, $this->publish($this->pack(['sources' => null])), 'sources missing');
        $this->assertSame(0, CountryPack::where('code', self::CODE)->count());
    }

    public function test_labels_live_in_translation_files_not_in_the_pack(): void
    {
        // Platform-core-spec Conventions, CP-01: a pack carrying names is refused.
        $this->assertSame(1, $this->publish($this->pack(['name' => 'Test country'])), 'pack name');
        $row = $this->pack()['tax_codes'][0];
        $this->assertSame(1, $this->publish($this->pack(taxCodes: [['label' => 'VAT'] + $row])), 'code label');
        $this->assertSame(0, CountryPack::where('code', self::CODE)->count());

        // Every shipped pack and code has a label in every supported language.
        foreach (['KE', 'CD'] as $code) {
            $this->assertSame([], PackLabels::missing(PackFile::read(PackFile::path($code))), $code);
        }

        $this->assertSame('TVA, taux normal', PackLabels::taxCode('CD', 'VAT_STD', 'fr'));
        $this->assertSame('VAT, standard rate', PackLabels::taxCode('KE', 'VAT_STD', 'en'));
        $this->assertSame('République démocratique du Congo', CountryPack::latest('CD')->name('fr'));
        // A label nobody wrote shows the code; publishing warns.
        $this->assertSame('VAT_STD', PackLabels::taxCode(self::CODE, 'VAT_STD', 'en'));
        $this->artisan('country-packs:publish', ['code' => self::CODE, '--file' => $this->file($this->pack())])
            ->expectsOutputToContain('Label missing')->assertSuccessful();
    }

    public function test_rates_as_strings_or_integers_and_consecutive_periods_are_accepted(): void
    {
        $row = ['code' => 'VAT_STD', 'kind' => 'vat', 'needs_confirmation' => false, 'effective_to' => null, 'fiscal_code' => null];

        $this->assertSame(0, $this->publish($this->pack(taxCodes: [
            ['rate' => 10, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30'] + $row,
            ['rate' => '12.5', 'effective_from' => '2026-07-01'] + $row,
        ])));
        $this->assertSame(['10.0000', '12.5000'], CountryPack::latest(self::CODE)->taxCodes()->orderBy('effective_from')->pluck('rate')->all());
    }

    public function test_the_seeder_fails_when_a_pack_does_not_publish(): void
    {
        $seeder = new class extends CountryPackCatalogueSeeder
        {
            // No file exists for this pack: the command exits non-zero.
            public const PACKS = ['ZZ'];
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publishing the ZZ country pack failed');
        $seeder->run();
    }

    public function test_the_runtime_role_cannot_write_the_pack_tables(): void
    {
        $pack = CountryPack::latest('KE');

        foreach ([
            fn () => DB::update("update country_pack_tax_codes set rate = 12.5 where code = 'VAT_STD'"),
            fn () => DB::update('update country_packs set version = 9'),
            fn () => DB::delete('delete from country_pack_tax_codes'),
            fn () => DB::delete('delete from country_packs'),
            fn () => DB::insert("insert into country_packs (id, code, version, content_hash, published_at, summary) values (gen_random_uuid(), 'XX', 1, repeat('0', 64), now(), '{}')"),
            fn () => DB::statement('truncate country_packs cascade'),
        ] as $statement) {
            try {
                DB::transaction($statement);
                $this->fail('The runtime role wrote to a global pack table');
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }

        $this->assertNull(PackTaxCode::where('country_pack_id', $pack->id)->where('code', 'VAT_STD')->value('rate'));

        foreach (['country_packs', 'country_pack_tax_codes'] as $table) {
            $this->assertTrue((bool) DB::selectOne("select has_table_privilege(current_user, '{$table}', 'SELECT') as p")->p);

            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertFalse((bool) DB::selectOne("select has_table_privilege(current_user, '{$table}', '{$privilege}') as p")->p, "{$privilege} on {$table}");
            }
        }
    }
}
