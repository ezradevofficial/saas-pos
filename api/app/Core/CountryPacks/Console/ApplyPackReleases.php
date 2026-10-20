<?php

namespace App\Core\CountryPacks\Console;

use App\Core\CountryPacks\PackReleases;
use Illuminate\Console\Command;

/**
 * ADM-04d (ADR 022): tax and holidays releases published with a later
 * effective date are written into the pack tables on that date (a new tax
 * pack version propagated to tenants, or the public holidays replaced).
 * Runs daily on the runtime and platform roles, never the owner.
 */
class ApplyPackReleases extends Command
{
    protected $signature = 'country-packs:apply-releases';

    protected $description = 'Apply published tax and holiday pack releases whose effective date has come';

    public function handle(PackReleases $releases): int
    {
        $this->components->info($releases->applyDue().' pack releases applied.');

        return self::SUCCESS;
    }
}
