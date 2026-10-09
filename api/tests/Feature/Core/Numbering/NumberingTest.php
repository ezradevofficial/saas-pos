<?php

namespace Tests\Feature\Core\Numbering;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Numbering\NumberSequence;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NUM-01: formats per document type and tenant, company or branch (most
// specific wins); the tenant default is seeded on first use; yearly or
// never reset; gapless numbers only inside the document's transaction;
// NUM-02 blocks reserved from the same counter with a frozen pattern.
class NumberingTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    public const TYPE = 'core.test_document';

    public const RANGED = 'core.test_receipt';

    protected function setUp(): void
    {
        parent::setUp();

        $types = app(DocumentNumberTypes::class);
        $types->register(new DocumentNumberType(self::TYPE, 'core', 'TD-{BRANCH}-{YYYY}-{0001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'app.name'));
        $types->register(new DocumentNumberType(self::RANGED, 'core', 'R-{LOCATION}-{000001}', NumberFormat::RESET_NEVER, ['BRANCH', 'LOCATION', 'DEVICE'], ranged: true, langKey: 'app.name'));

        $this->setUpOrganisation();
        app(TenantContext::class)->set($this->owner->tenant_id);
    }

    private function context(string $at = '2026-10-08 10:00:00', ?string $branch = 'A'): NumberContext
    {
        $branchModel = $branch === null ? null : ($branch === 'A' ? $this->branchA : $this->branchB);

        return new NumberContext($this->acme, $branchModel, at: CarbonImmutable::parse($at, 'UTC'));
    }

    public function test_the_tenant_default_is_seeded_once_and_audited(): void
    {
        $numbering = app(Numbering::class);

        $this->assertSame('TD-A-2026-0001', $numbering->next(self::TYPE, $this->context())->number);
        $this->assertSame('TD-B-2026-0002', $numbering->next(self::TYPE, $this->context(branch: 'B'))->number);

        $format = NumberFormat::where('document_type', self::TYPE)->sole();
        $this->assertNull($format->company_id);
        $this->assertSame(1, AuditEntry::where('action', 'core.number_format.create')->where('auditable_id', $format->id)->count());
    }

    public function test_the_most_specific_format_applies_and_counts_on_its_own(): void
    {
        $numbering = app(Numbering::class);
        NumberFormat::create(['document_type' => self::TYPE, 'company_id' => $this->acme->id, 'pattern' => 'CO-{YY}-{001}', 'reset' => 'yearly']);
        NumberFormat::create(['document_type' => self::TYPE, 'company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'pattern' => 'BR-{BRANCH}-{YYYY}{MM}-{01}', 'reset' => 'yearly']);

        $this->assertSame('CO-26-001', $numbering->next(self::TYPE, $this->context())->number);
        $this->assertSame('BR-B-202610-01', $numbering->next(self::TYPE, $this->context(branch: 'B'))->number);
        $this->assertSame('CO-26-002', $numbering->next(self::TYPE, $this->context())->number);
        $this->assertSame('BR-B-202610-02', $numbering->next(self::TYPE, $this->context(branch: 'B'))->number);
    }

    public function test_a_yearly_format_restarts_in_the_new_local_year_and_a_never_format_does_not(): void
    {
        $numbering = app(Numbering::class);

        $this->assertSame('TD-A-2026-0001', $numbering->next(self::TYPE, $this->context('2026-12-31 20:00:00'))->number);
        // 21:30 UTC on 31 December is already 1 January in Nairobi (UTC+3).
        $issued = $numbering->next(self::TYPE, $this->context('2026-12-31 21:30:00'));
        $this->assertSame(['TD-A-2027-0001', '2027'], [$issued->number, $issued->period]);
        $this->assertSame('TD-A-2027-0002', $numbering->next(self::TYPE, $this->context('2027-03-01 08:00:00'))->number);

        NumberFormat::create(['document_type' => self::TYPE, 'company_id' => $this->acme->id, 'pattern' => 'N-{0001}', 'reset' => 'never']);
        $this->assertSame(['N-0001', NumberSequence::ALL], [($n = $numbering->next(self::TYPE, $this->context('2027-12-31 08:00:00')))->number, $n->period]);
        $this->assertSame('N-0002', $numbering->next(self::TYPE, $this->context('2028-01-02 08:00:00'))->number);
    }

    public function test_a_gapless_number_is_drawn_only_inside_a_transaction_and_a_rollback_returns_it(): void
    {
        $numbering = app(Numbering::class);
        NumberFormat::create(['document_type' => self::TYPE, 'pattern' => 'G-{YYYY}-{0001}', 'reset' => 'yearly', 'gapless' => true]);

        // The test itself runs in a transaction: leave it to prove the guard.
        $db = DB::connection(TenantContext::CONNECTION);
        $level = $db->transactionLevel();
        $this->assertGreaterThan(0, $level, 'RefreshDatabase wraps the test in a transaction');

        try {
            $db->transaction(function () use ($numbering) {
                $this->assertSame('G-2026-0001', $numbering->next(self::TYPE, $this->context())->number);

                throw new \RuntimeException('document failed');
            });
        } catch (\RuntimeException) {
        }

        // The rolled-back document's number is issued again: no gap.
        $this->assertSame('G-2026-0001', $db->transaction(fn () => $numbering->next(self::TYPE, $this->context())->number));
        $this->assertSame('G-2026-0002', $db->transaction(fn () => $numbering->next(self::TYPE, $this->context())->number));
    }

    public function test_reserve_takes_a_block_from_the_counter_with_place_codes_frozen(): void
    {
        $numbering = app(Numbering::class);
        $this->locationA->forceFill(['code' => 'L01'])->save();
        $device = Device::create(['location_id' => $this->locationA->id, 'name' => 'Till']);
        $context = new NumberContext($this->acme, $this->branchA, $this->locationA, $device, CarbonImmutable::parse('2026-10-08 10:00:00'));

        $first = $numbering->reserve(self::RANGED, $context, 500);
        $second = $numbering->reserve(self::RANGED, $context, 500);

        $this->assertSame([1, 500, 'all', 'R-L01-{000001}'], [$first->from, $first->to, $first->period, $first->pattern]);
        $this->assertSame([501, 1000], [$second->from, $second->to]);
        $this->assertSame($first->sequenceId, $second->sequenceId);
        $this->assertSame('R-L01-000501', Numbering::renderFrozen($second->pattern, $context, 501));

        // A location without a code prints the last six hex digits of its id.
        $other = new NumberContext($this->acme, $this->branchB, $this->locationB, null, CarbonImmutable::parse('2026-10-08'));
        $this->assertSame('R-'.NumberContext::fallback($this->locationB->id).'-{000001}', $numbering->reserve(self::RANGED, $other, 10)->pattern);
    }

    public function test_reserve_freezes_the_year_of_a_yearly_format_and_refuses_a_gapless_one(): void
    {
        $numbering = app(Numbering::class);
        NumberFormat::create(['document_type' => self::RANGED, 'pattern' => 'R-{BRANCH}-{YY}{MM}-{0001}', 'reset' => 'yearly']);

        $block = $numbering->reserve(self::RANGED, $this->context('2026-10-08 10:00:00'), 50);
        $this->assertSame(['2026', 'R-A-26{MM}-{0001}'], [$block->period, $block->pattern]);

        NumberFormat::where('document_type', self::RANGED)->update(['gapless' => true]);

        try {
            $numbering->reserve(self::RANGED, $this->context(), 50);
            $this->fail('a gapless format was ranged');
        } catch (ApiException $e) {
            $this->assertSame('numbering_gapless_range', $e->errorCode);
        }
    }

    public function test_a_place_token_the_document_lacks_is_refused(): void
    {
        NumberFormat::create(['document_type' => self::RANGED, 'pattern' => 'R-{DEVICE}-{0001}', 'reset' => 'never']);

        try {
            app(Numbering::class)->reserve(self::RANGED, $this->context(), 5);
            $this->fail('a device token was filled without a device');
        } catch (ApiException $e) {
            $this->assertSame('numbering_token_unavailable', $e->errorCode);
        }
    }
}
