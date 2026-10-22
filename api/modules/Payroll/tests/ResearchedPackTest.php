<?php

namespace Modules\Payroll\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\HR\Events\LeaveApproved;
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\Settings;
use Modules\Payroll\Statutory\MissingValues;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Statutory\PayrollPackSchema;
use Modules\Payroll\Statutory\StatutoryEngine;
use Modules\Payroll\Statutory\StatutoryInput;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * CP-01, CP-02, PAY-07, PAY-08 on the SHIPPED packs (researched values,
 * docs/country-packs-research-2026-10.md), worked by hand from the
 * documented rules:
 * - KE at KES 100,000 gross, NSSF year 3 against year 4 by pay date, the
 *   pension + NSSF deduction limit;
 * - CD at CDF 1,000,000, the 30% cap and the CDF 2,000 minimum;
 * - low-confidence components still block (INPP), dependants give no
 *   reduction until confirmed, and the audited "not applicable" mark;
 * - every run records the researched values it used.
 */
class ResearchedPackTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    private static function shipped(string $country): array
    {
        return json_decode((string) file_get_contents(base_path("country-packs/{$country}/payroll.json")), true, 32, JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll(pack: self::shipped('KE'));
    }

    /** @return array<string, array<string, mixed>> statutory lines by code */
    private function statutory(array $line): array
    {
        return collect($line['statutory_lines'])->keyBy('code')->all();
    }

    public function test_kenya_worked_example_at_kes_100000(): void
    {
        $amina = $this->payrollEmployee('10000000');
        $id = $this->calculatedRun('2026-10-15');
        $line = $this->lines($id)[$amina->number];
        $by = $this->statutory($line);

        // NSSF year 4 (pay date 2026-10-28): 9,000 × 6% + (100,000 − 9,000) × 6% = 6,000 each side.
        $this->assertSame(['600000', '600000'], [$by['nssf']['employee']['amount_minor'], $by['nssf']['employer']['amount_minor']]);
        // SHIF 2.75% = 2,750; housing levy 1.5% + 1.5% = 1,500 each side; NITA 50 (employer).
        $this->assertSame('275000', $by['shif']['employee']['amount_minor']);
        $this->assertSame(['150000', '150000'], [$by['ahl']['employee']['amount_minor'], $by['ahl']['employer']['amount_minor']]);
        $this->assertSame(['0', '5000'], [$by['nita']['employee']['amount_minor'], $by['nita']['employer']['amount_minor']]);
        // Taxable pay: 100,000 − 6,000 − 2,750 − 1,500 = 89,750.
        $this->assertSame('8975000', $line['taxable_pay']['amount_minor']);
        // PAYE: 24,000 × 10% + 8,333 × 25% + 57,417 × 30% = 2,400 + 2,083.25 + 17,225.10 = 21,708.35; − relief 2,400 = 19,308.35.
        $this->assertSame('1930835', $by['paye']['employee']['amount_minor']);
        // Net: 100,000 − 6,000 − 2,750 − 1,500 − 19,308.35 = 70,441.65; employer 6,000 + 1,500 + 50.
        $this->assertSame('7044165', $line['net']['amount_minor']);
        $this->assertSame('755000', $line['employer']['amount_minor']);

        // CP-01: the run records the researched values it used, and says so.
        $run = $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.uses_researched_values', true)->json('data.statutory');
        $researched = collect($run['researched'])->keyBy('component');
        $this->assertEqualsCanonicalizing(['paye', 'personal_relief', 'nssf', 'shif', 'ahl', 'nita'], $researched->keys()->all());
        $this->assertSame(['2026-02-01', 'medium', '2026-10-17'], [$researched['nssf']['effective_from'], $researched['nssf']['confidence'], $researched['nssf']['researched_on']]);
        $this->assertNotEmpty($researched['paye']['source']);
        // Conditional components no employee used are not recorded.
        $this->assertArrayNotHasKey('insurance_relief', $researched->all());
        // S1: assumptions are recorded and worded on the run.
        $this->assertSame(['given to every employee (residency not recorded)'], $researched['personal_relief']['assumed_names']);
    }

    public function test_leave_with_an_unconfirmed_pay_rate_blocks_the_line_and_half_pay_days_are_flagged(): void
    {
        $amina = $this->payrollEmployee('10000000');
        $baraka = $this->payrollEmployee('10000000', ['first_name' => 'Baraka']);
        $dates = [['date' => '2026-10-12', 'fraction' => '1.00'], ['date' => '2026-10-13', 'fraction' => '1.00'], ['date' => '2026-10-14', 'fraction' => '1.00']];
        // CD-style maternity leave whose pay rate is not confirmed (paid null).
        event(new LeaveApproved($amina->tenant_id, (string) Str::uuid7(), 'LV-1', $amina->employee_id, $this->acme->id, (string) Str::uuid7(), 'MATERNITY', null, '2026-10-12', '2026-10-14', false, false, '3.00', $dates));
        // KE sick leave with 1.5 days at half pay (the last days of the request).
        $sick = (string) Str::uuid7();
        event(new LeaveApproved($baraka->tenant_id, $sick, 'LV-2', $baraka->employee_id, $this->acme->id, (string) Str::uuid7(), 'SICK', true, '2026-10-12', '2026-10-14', false, false, '3.00', $dates, '1.50'));

        $id = $this->calculatedRun();
        $lines = $this->lines($id);
        $this->assertSame(['leave_pay_unconfirmed'], array_column($lines[$amina->number]['errors'], 'code'));
        $this->assertStringContainsString('Leave pay rate not confirmed', $lines[$amina->number]['errors'][0]['message']);
        // Not deducted as unpaid leave either.
        $this->assertFalse(collect($lines[$amina->number]['earnings'])->contains('code', 'unpaid_leave'));
        $this->assertSame([], $lines[$baraka->number]['errors']);

        $run = $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk();
        $this->assertSame(1, $run->json('data.lines_with_errors'));
        $this->assertSame(['days' => '1.50', 'employees' => 1], $run->json('data.statutory.half_pay_paid_in_full'));
        $this->assertSame([['2026-10-13', '0.50'], ['2026-10-14', '1.00']], $this->inTenant(fn () => LeaveDay::query()->where('request_id', $sick)->where('kind', 'half_pay')->orderBy('date')->get()->map(fn (LeaveDay $d) => [$d->date->toDateString(), (string) $d->fraction])->all()));

        // HR recounts (an earlier request was cancelled): no half-pay day left.
        event(new LeaveApproved($baraka->tenant_id, $sick, 'LV-2', $baraka->employee_id, $this->acme->id, (string) Str::uuid7(), 'SICK', true, '2026-10-12', '2026-10-14', false, false, '3.00', $dates, '0'));
        $this->assertSame(0, $this->inTenant(fn () => LeaveDay::query()->where('request_id', $sick)->count()));
    }

    public function test_nssf_year_3_and_year_4_by_pay_date(): void
    {
        $amina = $this->payrollEmployee('10000000', ['hire_date' => '2025-12-01']);

        // Pay date 2026-01-28: year 3, 8,000 × 6% + 64,000 × 6% = 4,320.
        $january = $this->lines($this->calculatedRun('2026-01-15'))[$amina->number];
        $by = $this->statutory($january);
        $this->assertSame(['432000', '432000'], [$by['nssf']['employee']['amount_minor'], $by['nssf']['employer']['amount_minor']]);
        // Taxable 100,000 − 4,320 − 2,750 − 1,500 = 91,430; PAYE 2,400 + 2,083.25 + 59,097 × 30% − 2,400 = 19,812.35.
        $this->assertSame('9143000', $january['taxable_pay']['amount_minor']);
        $this->assertSame('1981235', $by['paye']['employee']['amount_minor']);

        // Pay date 2026-02-28: year 4, 6,000.
        $february = $this->statutory($this->lines($this->calculatedRun('2026-02-15'))[$amina->number]);
        $this->assertSame('600000', $february['nssf']['employee']['amount_minor']);

        // The same, straight from the engine at the two dates.
        $input = new StatutoryInput(gross: '10000000', taxableGross: '10000000', contributoryGross: '10000000', basic: '10000000');
        $engine = new StatutoryEngine;
        $at = fn (string $date) => collect($engine->compute(PayrollPack::inForce(self::shipped('KE'), CarbonImmutable::parse($date)), $input)->components)->keyBy('code');
        $this->assertSame('432000', $at('2026-01-31')['nssf']['employee_minor']);
        $this->assertSame('600000', $at('2026-02-01')['nssf']['employee_minor']);
    }

    public function test_pension_and_nssf_are_deducted_up_to_30000(): void
    {
        $item = $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'PENSION', 'name' => 'Pension', 'kind' => 'deduction', 'pre_tax' => true, 'pre_tax_category' => 'pension'], $this->headersFor($this->officer))
            ->assertCreated()->assertJsonPath('data.pre_tax_category', 'pension')->json('data.id');
        // S3: in Kenya a pre-tax deduction says which statutory limit applies.
        $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'SACCO', 'name' => 'Sacco', 'kind' => 'deduction', 'pre_tax' => true], $this->headersFor($this->officer))
            ->assertUnprocessable()->assertJsonPath('code', 'pre_tax_category_required');
        $this->patchJson("/api/v1/payroll/items/{$item}", ['pre_tax_category' => null], $this->headersFor($this->officer))
            ->assertUnprocessable()->assertJsonPath('code', 'pre_tax_category_required');
        // A category needs a pre-tax deduction.
        $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'LOAN', 'name' => 'Loan', 'kind' => 'deduction', 'pre_tax_category' => 'pension'], $this->headersFor($this->officer))
            ->assertUnprocessable()->assertJsonPath('code', 'pre_tax_category_needs_pre_tax');
        $amina = $this->payrollEmployee('10000000');
        $this->postJson("/api/v1/payroll/employees/{$amina->id}/items", ['item_id' => $item, 'amount_minor' => '3000000', 'currency' => 'KES', 'recurrence' => 'once', 'period_date' => '2026-10-10'], $this->headersFor($this->officer))->assertCreated();

        $line = $this->lines($this->calculatedRun())[$amina->number];
        // Pension 30,000 + NSSF 6,000 = 36,000 claimed; 30,000 allowed, so 6,000 stays taxable:
        // 100,000 − 30,000 − 6,000 − 2,750 − 1,500 + 6,000 = 65,750.
        $this->assertSame('6575000', $line['taxable_pay']['amount_minor']);
        // PAYE 2,400 + 2,083.25 + 33,417 × 30% − 2,400 = 12,108.35.
        $this->assertSame('1210835', $this->statutory($line)['paye']['employee']['amount_minor']);
    }

    /** The shipped CD pack with INPP back to "needs confirmation" (no brackets), to exercise the "not applicable" mark. */
    private static function inppUnconfirmed(): array
    {
        $pack = self::shipped('CD');
        $i = array_search('inpp', array_column($pack['components'], 'code'), true);
        $pack['components'][$i]['periods'] = [['effective_from' => '2026-01-01', 'effective_to' => null, 'needs_confirmation' => true, 'brackets' => null, 'rounding' => null, 'status' => 'needs_confirmation']];

        return $pack;
    }

    /** Acme moved to the DR Congo (CDF) with the shipped CD pack (or $pack). */
    private function congo(?array $pack = null): void
    {
        $this->inTenant(function () {
            $this->acme->forceFill(['base_currency' => 'CDF', 'country' => 'CD', 'timezone' => 'Africa/Kinshasa'])->save();
            app(TenantCurrencies::class)->provisionFor($this->acme);
            TenantCurrency::query()->whereIn('code', ['CDF'])->update(['active' => true]);
        });
        app(PayrollPack::class)->fake('CD', $pack ?? self::shipped('CD'));
    }

    public function test_congo_worked_example_with_the_low_confidence_values(): void
    {
        // Owner decision 2026-10-22: INPP 3/2/1%, the dependants reduction and CNSS deducted from the IPR base, low confidence.
        $this->congo();
        $joseph = $this->payrollEmployee('1000000', ['first_name' => 'Joseph'], 'CDF', ['dependants' => 2]);
        $id = $this->calculatedRun();
        $line = $this->lines($id)[$joseph->number];
        $by = $this->statutory($line);
        // CNSS 5% + 5%, risks 1.5%, family 6.5%, ONEM 0.2% on the taxable base; INPP 3% of gross (one employee, up to 50).
        $this->assertSame(['50000', '50000'], [$by['cnss']['employee']['amount_minor'], $by['cnss']['employer']['amount_minor']]);
        $this->assertSame(['15000', '65000', '2000', '30000'], [$by['cnss_risks']['employer']['amount_minor'], $by['cnss_family']['employer']['amount_minor'], $by['onem']['employer']['amount_minor'], $by['inpp']['employer']['amount_minor']]);
        // CNSS now reduces the IPR base: taxable 1,000,000 − 50,000 = 950,000.
        $this->assertSame('950000', $line['taxable_pay']['amount_minor']);
        // IPR: 162,000 × 3% + 788,000 × 15% = 4,860 + 118,200 = 123,060; 2 dependants × 2% = 4,922.40 off: 118,137.60 → 118,138.
        $this->assertSame('118138', $by['ipr']['employee']['amount_minor']);
        $this->assertSame('831862', $line['net']['amount_minor']);
        $this->assertSame('162000', $line['employer']['amount_minor']);

        // CP-01: the run records the low-confidence values and says so.
        $run = $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.uses_researched_values', true)->assertJsonPath('data.uses_low_confidence_values', true)->json('data.statutory');
        $low = collect($run['researched'])->where('confidence', 'low')->pluck('component')->sort()->values()->all();
        $this->assertSame(['cnss', 'inpp', 'ipr', 'ipr_dependants'], $low);
        $this->assertSame([], $run['not_applied']);
        $this->getJson("/api/v1/payroll/statutory/{$this->acme->id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.low_confidence', true);
    }

    public function test_kenya_runs_show_no_low_confidence_notice(): void
    {
        $this->payrollEmployee('10000000');
        $id = $this->calculatedRun();
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.uses_researched_values', true)->assertJsonPath('data.uses_low_confidence_values', false);
        $this->getJson("/api/v1/payroll/statutory/{$this->acme->id}", $this->headersFor())->assertOk()->assertJsonPath('data.low_confidence', false);
    }

    public function test_congo_maternity_is_paid_at_two_thirds(): void
    {
        $this->congo();
        $marie = $this->payrollEmployee('3000000', ['first_name' => 'Marie'], 'CDF');
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", ['monthly_days' => '25'], $this->headersFor($this->officer))->assertOk();
        $dates = [['date' => '2026-10-12', 'fraction' => '1.00'], ['date' => '2026-10-13', 'fraction' => '1.00'], ['date' => '2026-10-14', 'fraction' => '1.00']];
        // HR's maternity type from the CD pack: paid at 2/3 (Code du travail art. 130).
        event(new LeaveApproved($marie->tenant_id, (string) Str::uuid7(), 'LV-1', $marie->employee_id, $this->acme->id, (string) Str::uuid7(), 'MATERNITY', true, '2026-10-12', '2026-10-14', false, false, '3.00', $dates, '0', '2/3'));

        $line = $this->lines($this->calculatedRun())[$marie->number];
        $this->assertSame([], $line['errors']);
        $reduced = collect($line['earnings'])->firstWhere('code', 'reduced_pay_leave');
        // Daily rate CDF 3,000,000 ÷ 25 = 120,000; a third withheld for each of the 3 days = 120,000.
        $this->assertSame(['-120000', '3.00'], [$reduced['amount']['amount_minor'], $reduced['quantity']]);
        $this->assertSame('2880000', $line['gross']['amount_minor']);
        $this->assertStringContainsString('2/3', $reduced['name']);
    }

    public function test_congo_inpp_blocks_until_marked_not_applicable_which_is_audited(): void
    {
        // A pack where INPP is not confirmed (as before the owner decision of 2026-10-22).
        $this->congo(self::inppUnconfirmed());
        $joseph = $this->payrollEmployee('1000000', ['first_name' => 'Joseph'], 'CDF', ['dependants' => 2]);

        // INPP unconfirmed: the run is refused.
        $id = $this->createRun()->assertCreated()->json('data.id');
        $refused = $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertUnprocessable()
            ->assertJsonPath('code', 'statutory_rates_missing')->json('missing');
        $this->assertSame(['inpp'], array_column($refused, 'component'));

        // A component every company owes cannot be marked; a reason is required.
        $url = "/api/v1/payroll/statutory/{$this->acme->id}/not-applicable";
        $this->postJson($url, ['component' => 'cnss', 'reason' => 'We do not owe it'], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'component_always_applies');
        $this->postJson($url, ['component' => 'inpp'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->getJson("/api/v1/payroll/statutory/{$this->acme->id}", $this->headersFor())->assertOk()->assertJsonPath('data.ready', false);

        // The Owner marks INPP not applicable for this company: recorded and audited.
        $this->postJson($url, ['component' => 'inpp', 'reason' => 'Exempted by INPP letter 2026/14'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.not_applicable.inpp.reason', 'Exempted by INPP letter 2026/14')
            ->assertJsonPath('data.not_applicable.inpp.marked_by', $this->owner->id);
        $this->inTenant(function () {
            $entry = AuditEntry::query()->where('action', 'payroll.statutory.not_applicable')->sole();
            $this->assertSame(['inpp', 'CD', 'Exempted by INPP letter 2026/14', $this->owner->id], [$entry->after['component'], $entry->after['country'], $entry->after['reason'], $entry->user_id]);
        });
        // The statutory rates page: INPP no longer missing (only the family allowance, for employees with children).
        $pack = $this->getJson("/api/v1/payroll/statutory/{$this->acme->id}", $this->headersFor())->assertOk()->json('data');
        $this->assertSame(['family_allowance'], array_column(array_filter($pack['components'], fn ($c) => $c['missing'] !== []), 'code'));
        $this->assertTrue($pack['researched']);
        $inpp = collect($pack['components'])->firstWhere('code', 'inpp');
        $this->assertSame(['needs_confirmation', null, true], [$inpp['status'], $inpp['confidence'], $inpp['may_not_apply']]);
        $this->assertSame('Exempted by INPP letter 2026/14', $inpp['not_applicable']['reason']);

        // Marked not applicable: the run is calculated without INPP.
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $line = $this->lines($id)[$joseph->number];
        $by = $this->statutory($line);
        $this->assertArrayNotHasKey('inpp', $by);
        $this->assertSame('118138', $by['ipr']['employee']['amount_minor']);
        $this->assertSame('132000', $line['employer']['amount_minor']);

        $notices = $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk()->json('data.statutory');
        $this->assertSame([], $notices['not_applied']);
        $this->assertSame('inpp', $notices['not_applicable'][0]['component']);
        $cnss = collect($notices['researched'])->firstWhere('component', 'cnss');
        $this->assertSame(['rounding', 'base_cap_minor'], $cnss['assumed']);
        $this->assertContains('no ceiling on the base', $cnss['assumed_names']);

        // Undoing the mark is audited and blocks again.
        $this->deleteJson("/api/v1/payroll/statutory/{$this->acme->id}/not-applicable/inpp", [], $this->headersFor())->assertOk();
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'payroll.statutory.applicable')->count()));
        $this->inTenant(fn () => $this->assertNull(Settings::query()->where('company_id', $this->acme->id)->value('not_applicable')));
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'statutory_rates_missing');
    }

    public function test_only_holders_of_payroll_statutory_exempt_mark_not_applicable(): void
    {
        $this->congo(self::inppUnconfirmed());
        $url = "/api/v1/payroll/statutory/{$this->acme->id}/not-applicable";
        $viewer = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $this->postJson($url, ['component' => 'inpp', 'reason' => 'Not owed'], $this->headersFor($viewer))->assertForbidden();
        // The Payroll Officer manages payroll settings but does not exempt the company (Owner and Admin only).
        $this->postJson($url, ['component' => 'inpp', 'reason' => 'Not owed'], $this->headersFor($this->officer))->assertForbidden();
        $this->deleteJson("{$url}/inpp", [], $this->headersFor($this->officer))->assertForbidden();
        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::query()->where('action', 'payroll.statutory.not_applicable')->count()));

        $admin = $this->userWith('admin', Scope::tenant());
        $this->postJson($url, ['component' => 'inpp', 'reason' => 'Not owed'], $this->headersFor($admin))->assertOk()
            ->assertJsonPath('data.not_applicable.inpp.period_from', '2026-01-01');
    }

    public function test_a_mark_is_voided_and_audited_once_its_period_becomes_usable(): void
    {
        $this->congo(self::inppUnconfirmed());
        $this->payrollEmployee('1000000', ['first_name' => 'Joseph'], 'CDF');
        $this->postJson("/api/v1/payroll/statutory/{$this->acme->id}/not-applicable", ['component' => 'inpp', 'reason' => 'Exempt'], $this->headersFor())->assertOk();
        $id = $this->calculatedRun();

        // The owner confirms INPP's rates for the period the mark was made against: the mark no longer holds.
        app(PayrollPack::class)->fake('CD', FixturePacks::cd());
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $this->assertTrue(collect(collect($this->lines($id))->first()['statutory_lines'])->contains('code', 'inpp'));
        $this->inTenant(function () {
            $this->assertNull(Settings::query()->where('company_id', $this->acme->id)->value('not_applicable'));
            $entry = AuditEntry::query()->where('action', 'payroll.statutory.not_applicable_voided')->sole();
            $this->assertSame(['inpp', 'period_confirmed'], [$entry->after['component'], $entry->after['reason']]);
            $this->assertSame('Exempt', $entry->before['not_applicable']['reason']);
        });

        // Unconfirmed again (a later correction): the run blocks until someone marks it again.
        app(PayrollPack::class)->fake('CD', self::inppUnconfirmed());
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertUnprocessable()
            ->assertJsonPath('code', 'statutory_rates_missing');
        // A usable period cannot be marked.
        app(PayrollPack::class)->fake('CD', FixturePacks::cd());
        $this->postJson("/api/v1/payroll/statutory/{$this->acme->id}/not-applicable", ['component' => 'inpp', 'reason' => 'Exempt'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'component_confirmed');
    }

    public function test_the_pack_schema_limits_may_not_apply_and_not_applied(): void
    {
        foreach (['KE', 'CD'] as $country) {
            PayrollPackSchema::check(self::shipped($country));
        }

        $cd = self::shipped('CD');
        $cnss = array_search('cnss', array_column($cd['components'], 'code'), true);
        $cd['components'][$cnss]['may_not_apply'] = true;

        try {
            PayrollPackSchema::check($cd);
            $this->fail('may_not_apply on a required contribution outside the allow-list was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cnss', $e->getMessage());
        }

        $cd = self::shipped('CD');
        $ipr = array_search('ipr', array_column($cd['components'], 'code'), true);
        $cd['components'][$ipr]['unconfirmed'] = 'not_applied';
        $this->expectException(\InvalidArgumentException::class);
        PayrollPackSchema::check($cd);
    }

    public function test_congo_cap_and_minimum_and_low_confidence_blocks(): void
    {
        $pack = self::shipped('CD');
        $on = CarbonImmutable::parse('2026-10-31');
        $components = PayrollPack::applicable(PayrollPack::inForce($pack, $on), ['inpp']);
        $engine = new StatutoryEngine;
        $ipr = fn (string $pay, int $dependants = 0) => collect($engine->compute($components, new StatutoryInput(gross: $pay, taxableGross: $pay, contributoryGross: $pay, basic: $pay, dependants: $dependants))->components)->keyBy('code')['ipr'];

        // Since 2026-10-22 CNSS (5%) reduces the IPR base: taxable pay is 95% of pay below.
        // 10,000,000 → 9,500,000: bands give 4,860 + 245,700 + 540,000 + 2,360,000 = 3,150,560, capped at 30% = 2,850,000.
        $this->assertSame('2850000', $ipr('10000000')['employee_minor']);
        // 20,000 → 19,000: 3% = 570, raised to the CDF 2,000 minimum.
        $this->assertSame('2000', $ipr('20000')['employee_minor']);
        // S4 (researched, low): the minimum comes before the 30% cap, so the cap wins: 4,750 → 142.50 → 2,000 → capped at 1,425.
        $this->assertSame('1425', $ipr('5000')['employee_minor']);
        // Dependants: 2% of IPR each, at most 9. 1,000,000 → 950,000: 123,060; 3 dependants: 6% off = 115,676.40 → 115,676.
        $this->assertSame('123060', $ipr('1000000')['employee_minor']);
        $this->assertSame('115676', $ipr('1000000', 3)['employee_minor']);
        // 12 dependants count as 9: 18% off = 100,909.20 → 100,909.
        $this->assertSame('100909', $ipr('1000000', 12)['employee_minor']);

        // INPP by headcount band (3% up to 50, 2% from 51 to 300, 1% above), on gross pay.
        $all = PayrollPack::applicable(PayrollPack::inForce($pack, $on));
        $inpp = fn (int $headcount) => collect($engine->compute($all, new StatutoryInput(gross: '1000000', taxableGross: '1000000', contributoryGross: '1000000', basic: '1000000', headcount: $headcount))->components)->keyBy('code')['inpp']['employer_minor'];
        $this->assertSame(['30000', '30000', '20000', '20000', '10000'], [$inpp(1), $inpp(50), $inpp(51), $inpp(300), $inpp(301)]);

        // Only the family allowance still blocks (once an employee has children).
        $missing = MissingValues::check($pack, $on, 'monthly', ['dependants' => true, 'children' => true]);
        $this->assertSame(['family_allowance'], array_column($missing, 'component'));
        $this->assertSame([], MissingValues::check($pack, $on, 'monthly', ['dependants' => true]));
        // Owner decision 2026-10-22: a researched value marked low confidence is usable.
        $pack['components'][0]['periods'][0]['confidence'] = 'low';
        $this->assertSame([], MissingValues::check($pack, $on, 'monthly', []));
        // A period still needing confirmation blocks.
        $pack['components'][0]['periods'][0] = ['status' => 'needs_confirmation', 'needs_confirmation' => true] + $pack['components'][0]['periods'][0];
        $this->assertSame('ipr', MissingValues::check($pack, $on, 'monthly', [])[0]['component']);
        // The shipped KE pack is complete for monthly runs.
        $this->assertSame([], MissingValues::check(self::shipped('KE'), $on, 'monthly', ['insurance_premiums' => true, 'pension' => true, 'post_retirement_medical' => true, 'mortgage_interest' => true]));
    }
}
