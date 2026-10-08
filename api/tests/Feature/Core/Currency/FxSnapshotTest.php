<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** A document with money and FX snapshot columns, on a temporary table. */
class FxTestDocument extends Model
{
    protected $table = 'fx_test_documents';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fx' => FxSnapshot::class.':fx'];
    }
}

// CUR-04: documents keep the rate they used; ADR 003 migration macros.
class FxSnapshotTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();

        // Temporary: dropped with the session, invisible to the isolation suite.
        Schema::create('fx_test_documents', function (Blueprint $table) {
            $table->temporary();
            $table->id();
            $table->money('total');
            $table->money('base_total');
            $table->money('tip', nullable: true);
            $table->fxSnapshot('fx');
        });
    }

    public function test_the_macros_create_the_documented_columns(): void
    {
        $columns = collect(DB::select("
            select column_name, data_type, character_maximum_length as length, numeric_precision as precision, numeric_scale as scale, is_nullable
            from information_schema.columns where table_name = 'fx_test_documents' and column_name <> 'id' order by ordinal_position
        "))->keyBy('column_name')->map(fn ($c) => [$c->data_type, $c->length ?? $c->precision, $c->scale, $c->is_nullable])->all();

        $this->assertSame([
            'total_minor' => ['bigint', 64, 0, 'NO'],
            'total_currency' => ['character', 3, null, 'NO'],
            'base_total_minor' => ['bigint', 64, 0, 'NO'],
            'base_total_currency' => ['character', 3, null, 'NO'],
            'tip_minor' => ['bigint', 64, 0, 'YES'],
            'tip_currency' => ['character', 3, null, 'YES'],
            'fx_rate' => ['numeric', 18, 8, 'YES'],
            'fx_rate_base' => ['character', 3, null, 'YES'],
            'fx_rate_quote' => ['character', 3, null, 'YES'],
            'fx_rate_kind' => ['character varying', 10, null, 'YES'],
            'fx_rate_effective_at' => ['timestamp with time zone', null, null, 'YES'],
        ], $columns);
    }

    /** Review focus 2: later rate changes never alter a stored document. */
    public function test_a_stored_snapshot_and_base_amount_are_frozen_when_rates_change(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '2026-10-01 08:00');
        $total = Money::ofMinor(138225, 'CDF');

        $id = $this->inTenant(function () use ($total) {
            ['base' => $base, 'snapshot' => $snapshot] = app(Converter::class)->toBase($total, $this->acme);

            return FxTestDocument::create([
                'total_minor' => $total->minor(), 'total_currency' => 'CDF',
                'base_total_minor' => $base->minor(), 'base_total_currency' => $base->currency(),
                'fx' => $snapshot,
            ])->id;
        });

        // The rate moves (a new shop rate and a reference rate).
        $this->rate('USD', 'CDF', '3000', 'shop', '-1 minute');
        $this->rate('USD', 'CDF', '3100', 'reference', '-1 minute');

        $document = $this->inTenant(fn () => FxTestDocument::findOrFail($id));

        $this->assertSame('4850', (string) $document->base_total_minor);
        $this->assertSame('USD', $document->base_total_currency);
        $this->assertInstanceOf(FxSnapshot::class, $document->fx);
        $this->assertSame('2850.00000000', $document->fx->rate);
        $this->assertSame(['USD', 'CDF'], [$document->fx->base(), $document->fx->quote()]);
        $this->assertSame('shop', $document->fx->kind);
        $this->assertTrue(CarbonImmutable::parse('2026-10-01 08:00:00Z')->equalTo($document->fx->effectiveAt));
        // Re-applying the frozen rate gives the stored amount, not today's.
        $this->assertSame('4850', $this->inTenant(fn () => $document->fx->convert($total))->minor());
        $this->assertSame('4608', $this->inTenant(fn () => app(Converter::class)->toBase($total, $this->acme))['base']->minor());
    }

    public function test_a_null_snapshot_clears_the_columns_and_reads_back_as_null(): void
    {
        $document = FxTestDocument::create([
            'total_minor' => 1, 'total_currency' => 'USD', 'base_total_minor' => 1, 'base_total_currency' => 'USD',
            'fx' => null,
        ]);

        $this->assertNull($document->fresh()->fx);
        $this->assertNull($document->fresh()->fx_rate_base);
    }

    public function test_json_shape(): void
    {
        $snapshot = new FxSnapshot('2850.00000000', 'USD', 'CDF', 'shop', CarbonImmutable::parse('2026-10-01 08:00:00Z'));

        $this->assertSame(
            '{"rate":"2850.00000000","base":"USD","quote":"CDF","kind":"shop","effective_at":"2026-10-01T08:00:00+00:00"}',
            json_encode($snapshot),
        );
    }

    public function test_convert_multiplies_from_the_base_divides_from_the_quote_and_refuses_other_currencies(): void
    {
        $snapshot = new FxSnapshot('2850.00000000', 'USD', 'CDF', 'shop', CarbonImmutable::now());

        $this->inTenant(function () use ($snapshot) {
            $this->assertSame(['138225', 'CDF'], [$snapshot->convert(Money::ofMinor(4850, 'USD'))->minor(), 'CDF']);
            $this->assertSame('CDF', $snapshot->convert(Money::ofMinor(4850, 'USD'))->currency());
            // CDF 1,000 / 2,850 = USD 0.3508... -> 0.35 half up.
            $this->assertSame(['35', 'USD'], [$snapshot->convert(Money::ofMinor(1000, 'CDF'))->minor(), $snapshot->convert(Money::ofMinor(1000, 'CDF'))->currency()]);
            $this->assertSame('500', FxSnapshot::identity('USD')->convert(Money::ofMinor(500, 'USD'))->minor());

            $this->expectException(\InvalidArgumentException::class);
            $snapshot->convert(Money::ofMinor(100, 'KES'));
        });
    }
}
