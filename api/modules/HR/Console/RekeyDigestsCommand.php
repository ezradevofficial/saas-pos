<?php

namespace Modules\HR\Console;

use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Support\Digest;
use Modules\HR\Support\Errors;

/**
 * HR-10: recompute every identifier digest under the current
 * HR_DIGEST_KEY, tenant by tenant under row-level security (after the key
 * changed): every tenant holding an identifier, suspended and closed ones
 * included (app_tenants_with_hr_identifiers, a security-definer function
 * returning ids only). Values are decrypted in memory only; nothing else
 * changes.
 */
class RekeyDigestsCommand extends Command
{
    protected $signature = 'hr:rekey-digests';

    protected $description = 'Recompute employee identifier digests under the current HR_DIGEST_KEY';

    public function handle(TenantContext $tenants): int
    {
        $count = 0;
        $tenantIds = array_map(fn ($row) => (string) $row->id, DB::connection(TenantContext::CONNECTION)->select('select app_tenants_with_hr_identifiers() as id'));

        foreach ($tenantIds as $tenantId) {
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
