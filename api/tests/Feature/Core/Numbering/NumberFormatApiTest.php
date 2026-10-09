<?php

namespace Tests\Feature\Core\Numbering;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NUM-01: GET numbering/formats (core.numbering.view) lists the active
// types with their defaults and the formats in the user's reach; PUT sets
// one at the tenant, a company or a branch (core.numbering.edit there).
class NumberFormatApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(DocumentNumberTypes::class)->register(new DocumentNumberType(NumberingTest::TYPE, 'core', 'TD-{BRANCH}-{YYYY}-{0001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'app.name'));
        app(DocumentNumberTypes::class)->register(new DocumentNumberType(NumberingTest::RANGED, 'core', 'R-{LOCATION}-{000001}', NumberFormat::RESET_NEVER, ['BRANCH', 'LOCATION', 'DEVICE'], ranged: true, langKey: 'app.name'));
        app(DocumentNumberTypes::class)->register(new DocumentNumberType('inactive.thing', 'inactive', '{01}'));
        $this->setUpOrganisation();
    }

    private function saveFormat(array $body, ?array $headers = null)
    {
        return $this->putJson('/api/v1/numbering/formats', $body + [
            'document_type' => NumberingTest::TYPE, 'company_id' => null, 'pattern' => 'X-{YYYY}-{001}', 'reset' => 'yearly',
        ], $headers ?? $this->headersFor());
    }

    public function test_the_owner_lists_active_types_and_sets_formats_at_each_scope(): void
    {
        $this->saveFormat([])->assertOk()->assertJsonPath('data.company_id', null)->assertJsonPath('data.pattern', 'X-{YYYY}-{001}');
        $this->saveFormat(['company_id' => $this->acme->id, 'pattern' => 'C-{YY}-{001}'])->assertOk();
        $branch = $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'pattern' => 'B-{BRANCH}-{YY}-{001}'])->assertOk();
        // The same scope again updates it (audited as an update).
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'pattern' => 'B2-{BRANCH}-{YY}-{001}'])->assertOk()->assertJsonPath('data.id', $branch->json('data.id'));

        $this->inTenant(fn () => $this->assertSame(3, NumberFormat::count()));
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.number_format.update')->count()));

        $list = $this->getJson('/api/v1/numbering/formats', $this->headersFor())->assertOk();
        $this->assertSame([NumberingTest::TYPE, NumberingTest::RANGED], array_column($list->json('data'), 'document_type'));
        $type = collect($list->json('data'))->firstWhere('document_type', NumberingTest::TYPE);
        $this->assertSame(['pattern' => 'TD-{BRANCH}-{YYYY}-{0001}', 'reset' => 'yearly'], $type['default']);
        $this->assertCount(3, $type['formats']);
    }

    public function test_patterns_are_validated_for_the_type(): void
    {
        $this->saveFormat(['pattern' => 'X-{001}', 'reset' => 'yearly'])->assertUnprocessable()->assertJsonPath('code', 'numbering_yearly_needs_year');
        $this->saveFormat(['pattern' => 'X-{LOCATION}-{YYYY}-{001}'])->assertUnprocessable()->assertJsonPath('code', 'numbering_token_unavailable');
        $this->saveFormat(['pattern' => 'X {001}'])->assertUnprocessable()->assertJsonPath('code', 'numbering_pattern_invalid');
        $this->saveFormat(['document_type' => NumberingTest::RANGED, 'pattern' => 'R-{LOCATION}-{001}', 'reset' => 'never', 'gapless' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'numbering_gapless_range');
        $this->saveFormat(['document_type' => 'inactive.thing'])->assertUnprocessable()->assertJsonValidationErrors('document_type');
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->otherTenant()['branch']->id])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_the_reset_cannot_change_once_numbers_were_issued(): void
    {
        $this->saveFormat([])->assertOk();
        $this->inTenant(fn () => app(Numbering::class)->next(NumberingTest::TYPE, new NumberContext($this->acme, $this->branchA, at: CarbonImmutable::now())));

        $this->saveFormat(['pattern' => 'X-{001}', 'reset' => 'never'])->assertUnprocessable()->assertJsonPath('code', 'numbering_reset_locked');
        // The pattern alone may still change.
        $this->saveFormat(['pattern' => 'Y-{YYYY}-{0001}'])->assertOk();
    }

    public function test_formats_never_print_the_same_numbers_and_always_fit(): void
    {
        // M2: a company format continues the tenant counter it replaces.
        $this->saveFormat(['pattern' => 'X-{YYYY}-{001}'])->assertOk();
        $this->inTenant(fn () => app(Numbering::class)->next(NumberingTest::TYPE, new NumberContext($this->acme, $this->branchA, at: CarbonImmutable::now())));
        $this->saveFormat(['company_id' => $this->acme->id, 'pattern' => 'C-{YYYY}-{001}'])->assertOk();
        $year = CarbonImmutable::now('Africa/Nairobi')->format('Y');
        $this->assertSame("C-{$year}-002", $this->inTenant(fn () => app(Numbering::class)->next(NumberingTest::TYPE, new NumberContext($this->acme, $this->branchA, at: CarbonImmutable::now()))->number));

        // The same pattern elsewhere is refused, unless branches of one company print {BRANCH}.
        $other = $this->inTenant(fn () => $this->company('Other'));
        $this->saveFormat(['company_id' => $other->id, 'pattern' => 'C-{YYYY}-{001}'])->assertUnprocessable()->assertJsonPath('code', 'numbering_pattern_collision');
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'pattern' => 'S-{BRANCH}-{YYYY}-{001}'])->assertOk();
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'pattern' => 'S-{BRANCH}-{YYYY}-{001}'])->assertOk();
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'pattern' => 'C-{YYYY}-{001}'])->assertUnprocessable()->assertJsonPath('code', 'numbering_pattern_collision');

        // M5: the longest number it could print must fit 80 characters (a 70-character branch code here).
        $this->inTenant(fn () => $this->branchA->forceFill(['code' => str_repeat('A', 70)])->save());
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'pattern' => 'S-{BRANCH}-{YYYY}-{001}'])
            ->assertUnprocessable()->assertJsonPath('code', 'numbering_pattern_too_long');
        // Saved earlier, it now refuses ranges instead of failing.
        $this->inTenant(function () {
            try {
                app(Numbering::class)->reserve(NumberingTest::TYPE, new NumberContext($this->acme, $this->branchA, at: CarbonImmutable::now()), 10);
                $this->fail('a too-long range was reserved');
            } catch (ApiException $e) {
                $this->assertSame('numbering_pattern_too_long', $e->errorCode);
            }
        });
    }

    /** One number of the test type at $branch now (as a document would draw it). */
    private function issue(Branch $branch): string
    {
        return $this->inTenant(fn () => app(Numbering::class)->next(NumberingTest::TYPE, new NumberContext($this->acme->fresh(), $branch->fresh(), at: CarbonImmutable::now()))->number);
    }

    public function test_scenario_a_a_pattern_given_up_by_one_format_is_not_taken_back_by_another(): void
    {
        // Acme numbers with its own company format; another company uses the tenant's R.
        $this->saveFormat(['pattern' => 'R-{001}', 'reset' => 'never'])->assertOk();
        $this->saveFormat(['company_id' => $this->acme->id, 'pattern' => 'C-{001}', 'reset' => 'never'])->assertOk();
        $other = $this->inTenant(fn () => $this->company('Other'));
        $otherBranch = $this->inTenant(fn () => $this->branch($other, 'O'));
        $this->assertSame('R-001', $this->inTenant(fn () => app(Numbering::class)->next(NumberingTest::TYPE, new NumberContext($other, $otherBranch, at: CarbonImmutable::now()))->number));
        $this->assertSame('C-001', $this->issue($this->branchA));

        // The tenant format goes R -> X: its own counter, nothing printed twice.
        $this->saveFormat(['pattern' => 'X-{001}', 'reset' => 'never'])->assertOk();

        // Acme's format going to R would print R-002... while R-002 may be the tenant counter's
        // (and R-001 was printed under it): refused, as is a width that only looks different.
        foreach (['R-{001}', 'R-{0001}', 'R-{1}'] as $pattern) {
            $this->saveFormat(['company_id' => $this->acme->id, 'pattern' => $pattern, 'reset' => 'never'])
                ->assertUnprocessable()->assertJsonPath('code', 'numbering_prefix_used')->assertJsonValidationErrors('pattern');
        }

        // A prefix of its own is fine; the tenant format may take R back (its own history).
        $this->saveFormat(['company_id' => $this->acme->id, 'pattern' => 'RC-{001}', 'reset' => 'never'])->assertOk();
        $this->saveFormat(['pattern' => 'R-{001}', 'reset' => 'never'])->assertOk();
        $this->assertSame('RC-002', $this->issue($this->branchA));
    }

    public function test_scenario_b_swapped_or_reused_branch_codes_never_repeat_numbers(): void
    {
        // Each branch counts on its own (twin branch formats printing {BRANCH}).
        foreach ([$this->branchA, $this->branchB] as $branch) {
            $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $branch->id, 'pattern' => 'S-{BRANCH}-{001}', 'reset' => 'never'])->assertOk();
        }
        foreach ([1, 2, 3] as $n) {
            $this->assertSame("S-A-00{$n}", $this->issue($this->branchA));
        }
        $this->assertSame('S-B-001', $this->issue($this->branchB));

        // Swapping A and B: B's counter (at 2) would print S-A-002, already A's.
        $this->patchJson("/api/v1/branches/{$this->branchA->id}", ['code' => 'T'], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/branches/{$this->branchB->id}", ['code' => 'A'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'numbering_prefix_used')->assertJsonValidationErrors('code');
        $this->assertSame('B', $this->inTenant(fn () => $this->branchB->fresh()->code));

        // A code edit within a branch's own history is fine: A goes back to A.
        $this->patchJson("/api/v1/branches/{$this->branchA->id}", ['code' => 'A'], $this->headersFor())->assertOk();
        $this->assertSame('S-A-004', $this->issue($this->branchA));

        // An archived branch's code taken by a new branch on another counter (the company's).
        $this->saveFormat(['company_id' => $this->acme->id, 'pattern' => 'S-{BRANCH}-{0001}', 'reset' => 'never'])->assertOk();
        $this->inTenant(fn () => $this->branchA->fresh()->archive());
        $this->postJson("/api/v1/companies/{$this->acme->id}/branches", ['name' => 'New A', 'code' => 'A'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'numbering_prefix_used');
        $this->postJson("/api/v1/companies/{$this->acme->id}/branches", ['name' => 'New C', 'code' => 'C'], $this->headersFor())->assertCreated();

        // A location code is checked the same way: branch B ranges on its own counter, the other company on the tenant's.
        $this->saveFormat(['document_type' => NumberingTest::RANGED, 'company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'pattern' => 'R-{LOCATION}-{00001}', 'reset' => 'never'])->assertOk();
        $far = $this->inTenant(function () {
            $near = $this->location($this->branchB, 'Till row');
            $near->forceFill(['code' => 'L1'])->save();
            app(Numbering::class)->reserve(NumberingTest::RANGED, new NumberContext($this->acme, $this->branchB, $near, at: CarbonImmutable::now()), 10);
            $company = $this->company('Other');
            $branch = $this->branch($company, 'O');
            $far = $this->location($branch, 'Other row');
            $far->forceFill(['code' => 'L2'])->save();
            app(Numbering::class)->reserve(NumberingTest::RANGED, new NumberContext($company, $branch, $far, at: CarbonImmutable::now()), 10);

            return $far;
        });
        $this->patchJson("/api/v1/locations/{$far->id}", ['code' => 'L1'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'numbering_prefix_used')->assertJsonValidationErrors('code');
        $this->patchJson("/api/v1/locations/{$far->id}", ['code' => 'L3'], $this->headersFor())->assertOk();
    }

    public function test_locations_and_devices_take_a_code_for_numbers(): void
    {
        $location = $this->postJson("/api/v1/branches/{$this->branchA->id}/locations", ['name' => 'Till row', 'type' => 'outlet', 'code' => 'L02'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.code', 'L02')->json('data.id');
        $this->patchJson("/api/v1/locations/{$location}", ['code' => 'l 3'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');

        $device = $this->postJson("/api/v1/locations/{$location}/devices", ['name' => 'Till 1', 'code' => 'T1'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.code', 'T1')->json('data.id');
        $this->patchJson("/api/v1/devices/{$device}", ['code' => null], $this->headersFor())->assertOk()->assertJsonPath('data.code', null);
    }

    public function test_scope_rules_apply(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $editor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Numbering', ['core.numbering.view', 'core.numbering.edit']), Scope::branch($this->branchA->id));

            return $user;
        });

        // The branch manager template holds no numbering permission.
        $this->getJson('/api/v1/numbering/formats', $this->headersFor($manager))->assertForbidden();
        $this->saveFormat([], $this->headersFor($manager))->assertForbidden();

        // A branch editor sets its branch's format, not the tenant's, the company's or branch B's (not reached: 404).
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'pattern' => 'A-{YYYY}-{001}'], $this->headersFor($editor))->assertOk();
        $this->saveFormat([], $this->headersFor($editor))->assertForbidden();
        $this->saveFormat(['company_id' => $this->acme->id], $this->headersFor($editor))->assertForbidden();
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id], $this->headersFor($editor))->assertNotFound();

        // It sees the tenant's and its branch's formats only.
        $this->saveFormat(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'pattern' => 'B-{YYYY}-{001}'])->assertOk();
        $this->saveFormat([])->assertOk();
        $formats = collect($this->getJson('/api/v1/numbering/formats', $this->headersFor($editor))->assertOk()->json('data'))
            ->firstWhere('document_type', NumberingTest::TYPE)['formats'];
        $this->assertEqualsCanonicalizing([null, $this->branchA->id], array_column($formats, 'branch_id'));
    }
}
