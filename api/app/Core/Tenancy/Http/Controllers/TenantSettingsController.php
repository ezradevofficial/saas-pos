<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Identity\Services\PasswordPolicy;
use App\Core\Identity\Services\SessionTimeout;
use App\Core\Tenancy\Http\Requests\TenantSettingsRequest;
use App\Core\Tenancy\Http\Requests\UpdateTenantSettingsRequest;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The tenant's own settings: password minimum (AUTH-02), session idle
 * timeout (AUTH-09) and default language (L10N-01). Only the current
 * tenant's row is reachable (RLS on `tenants`). Changes are audited as
 * `core.settings.update` with the changed values before and after.
 */
class TenantSettingsController
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function show(TenantSettingsRequest $request): JsonResponse
    {
        return $this->respond(Tenant::findOrFail($this->tenants->require()));
    }

    public function update(UpdateTenantSettingsRequest $request): JsonResponse
    {
        $tenant = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request) {
            $tenant = Tenant::query()->whereKey($this->tenants->require())->lockForUpdate()->firstOrFail();
            $before = self::values($tenant);
            $input = $request->validated();

            $settings = $tenant->settings ?? [];
            foreach (array_keys(Tenant::DEFAULT_SETTINGS) as $key) {
                if (array_key_exists($key, $input)) {
                    $settings[$key] = (int) $input[$key];
                }
            }

            $tenant->settings = $settings;
            if (array_key_exists('default_locale', $input)) {
                $tenant->default_locale = $input['default_locale'];
            }
            $tenant->save();

            $after = self::values($tenant);
            $changed = array_keys(array_diff_assoc($after, $before));

            if ($changed !== []) {
                $this->auditor->record(
                    'core.settings.update',
                    $tenant,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)),
                );
            }

            return $tenant;
        });

        return $this->respond($tenant);
    }

    /** @return array{password_min_length: int, session_timeout_minutes: int, default_locale: string} */
    private static function values(Tenant $tenant): array
    {
        return [
            'password_min_length' => PasswordPolicy::minLength($tenant),
            'session_timeout_minutes' => SessionTimeout::minutes($tenant),
            'default_locale' => $tenant->default_locale,
        ];
    }

    private function respond(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => self::values($tenant)]);
    }
}
