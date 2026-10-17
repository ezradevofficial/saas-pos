<?php

namespace App\Core\CountryPacks\Jobs;

use App\Core\CountryPacks\Models\CountryPack;
use App\Core\MasterData\Taxes\PropagateCountryPack;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ADM-04 (ADR 022, review M4): a published tax release reaches one tenant.
 * One job per tenant, so a tenant's failure is isolated and retried with
 * back-off while the others go on. The job enters the tenant itself
 * (PropagateCountryPack::forTenant) under row-level security.
 */
class PropagatePackToTenant implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public string $packId,
        public string $tenantId,
    ) {}

    public function handle(PropagateCountryPack $propagate): void
    {
        $pack = CountryPack::query()->find($this->packId);

        if ($pack === null) {
            return;
        }

        $result = $propagate->forTenant($pack, $this->tenantId);

        if ($result['conflicts'] !== []) {
            Log::warning('country pack propagation left codes unchanged', ['pack' => $pack->code, 'version' => $pack->version, 'tenant' => $this->tenantId, 'conflicts' => $result['conflicts']]);
        }
    }
}
