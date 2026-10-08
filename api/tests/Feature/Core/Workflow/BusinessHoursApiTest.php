<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** WF-09, APR-05: a company's working hours through the API. */
class BusinessHoursApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
    }

    private function url(?string $companyId = null): string
    {
        return '/api/v1/companies/'.($companyId ?? $this->acme->id).'/business-hours';
    }

    public function test_the_default_is_shown_until_hours_are_set_and_changes_are_audited(): void
    {
        $this->getJson($this->url(), $this->headersFor())->assertOk()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.timezone', 'Africa/Nairobi')
            ->assertJsonPath('data.hours', BusinessCalendar::DEFAULT_HOURS);

        $saved = $this->putJson($this->url(), ['hours' => [
            'mon' => [['13:00', '17:00'], ['08:00', '12:00']],
            'sat' => [['09:00', '13:00']],
        ]], $this->headersFor())->assertOk();

        $saved->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.hours.mon', [['08:00', '12:00'], ['13:00', '17:00']])
            ->assertJsonPath('data.hours.tue', [])
            ->assertJsonPath('data.hours.sat', [['09:00', '13:00']]);

        $this->putJson($this->url(), ['hours' => BusinessCalendar::DEFAULT_HOURS], $this->headersFor())->assertOk();
        $actions = $this->inTenant(fn () => AuditEntry::query()->where('action', 'like', 'core.business_hours.%')->orderBy('seq')->pluck('action')->all());
        $this->assertSame(['core.business_hours.create', 'core.business_hours.update'], $actions);
    }

    public function test_invalid_hours_are_refused(): void
    {
        foreach ([
            ['monday' => [['08:00', '17:00']]],
            ['mon' => [['17:00', '08:00']]],
            ['mon' => [['08:00', '12:00'], ['11:00', '17:00']]],
            ['mon' => [['8am', '5pm']]],
            // M2 regression: a week with no open time.
            ['mon' => [], 'tue' => []],
        ] as $hours) {
            $this->putJson($this->url(), ['hours' => $hours], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('hours');
        }

        $this->putJson($this->url(), [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('hours');
    }

    public function test_reading_needs_the_company_and_changing_needs_company_edit(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson($this->url(), $this->headersFor($manager))->assertOk();
        $this->putJson($this->url(), ['hours' => BusinessCalendar::DEFAULT_HOURS], $this->headersFor($manager))->assertForbidden();

        $other = $this->otherTenant();
        $this->getJson($this->url($other['company']->id), $this->headersFor())->assertNotFound();
        $this->putJson($this->url($other['company']->id), ['hours' => BusinessCalendar::DEFAULT_HOURS], $this->headersFor())->assertNotFound();
        $this->getJson($this->url(), $this->headersFor($other['user']))->assertNotFound();
    }
}
