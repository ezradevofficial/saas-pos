<?php

namespace Modules\HR\Console;

use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Support\Digest;
use Modules\HR\Support\Errors;

/**
 * HR-10: recompute every identifier digest under the current
 * HR_DIGEST_KEY, tenant by tenant under row-level security (after the key
 * changed). Values are decrypted in memory only; nothing else changes.
 */
class RekeyDigestsCommand extends Command
{
    protected $signature = 'hr:rekey-digests';

    protected $description = 'Recompute employee identifier digests under the current HR_DIGEST_KEY';

    public function handle(DueTenants $due, TenantContext $tenants): int
    {
        $count = 0;

        foreach ($due->active() as $tenantId) {
            $tenants->run($tenantId, function () use (&$count) {
                Errors::transaction(function () use (&$count) {
                    foreach (EmployeeIdentifier::query()->lockForUpdate()->orderBy('id')->get() as $identifier) {
                        $digest = Digest::of($identifier->type, $identifier->value);

                        if ($digest !== $identifier->value_digest) {
                            $identifier->forceFill(['value_digest' => $digest])->save();
                            $count++;
                        }
                    }
                });
            });
        }

        $this->components->info("{$count} identifier digests recomputed.");

        return self::SUCCESS;
    }
}
