<?php

namespace Modules\Payroll\Runs;

use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Settings;

/** What a run's calculation reads once: the run, its company, pay group, country pack, settings and the instant rates are taken at. */
final class RunContext
{
    public function __construct(
        public readonly Run $run,
        public readonly Company $company,
        public readonly PayGroup $group,
        public readonly array $pack,
        public readonly ?Settings $settings,
        public readonly CarbonImmutable $at,
    ) {}
}
