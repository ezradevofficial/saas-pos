<?php

namespace App\Core\Fiscal\Http\Controllers;

use App\Core\Fiscal\FiscalDrivers;
use App\Core\Fiscal\FiscalSettingsCheck;
use App\Core\Fiscal\Http\Requests\InitializeFiscalRequest;
use App\Core\Fiscal\Http\Requests\SaveFiscalSettingsRequest;
use App\Core\Fiscal\Http\Requests\ShowFiscalSettingsRequest;
use App\Core\Fiscal\Http\Resources\FiscalSettingsResource;
use App\Core\Fiscal\LocalRejection;
use App\Core\Fiscal\Models\FiscalSettings;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A company's fiscal settings (concept note 7.2). Until saved, a company
 * has none and nothing is transmitted; GET then answers the defaults
 * (`data: null` plus the drivers it may choose).
 *
 * Transmission switches on only when the driver can transmit and nothing
 * it needs is missing (`fiscal_not_ready`). Changing the PIN, branch id,
 * device serial or driver drops the key the authority issued for the old
 * ones and switches transmission off: the device must be initialised
 * again. Credentials are merged key by key and never returned. Every
 * change is audited (AUD-01; credentials by key name only).
 */
class FiscalSettingsController
{
    private const IDENTITY = ['driver', 'tin', 'branch_code', 'device_serial'];

    public function __construct(
        private readonly FiscalDrivers $drivers,
        private readonly FiscalSettingsCheck $check,
    ) {}

    public function show(ShowFiscalSettingsRequest $request, Company $company): JsonResponse
    {
        $settings = FiscalSettings::query()->where('company_id', $company->id)->first();

        if ($settings === null) {
            return response()->json(['data' => null, 'meta' => ['drivers' => $this->drivers->choices((string) $company->country)]]);
        }

        return FiscalSettingsResource::make($settings)->response();
    }

    public function save(SaveFiscalSettingsRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        $settings = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data) {
            $settings = FiscalSettings::query()->where('company_id', $company->id)->lockForUpdate()->first()
                ?? new FiscalSettings([
                    'company_id' => $company->id,
                    'country' => $company->country,
                    'driver' => $this->drivers->choices((string) $company->country)[0] ?? throw new ApiException(422, 'fiscal_unsupported', __('fiscal.errors.country_unsupported')),
                ]);

            $settings->fill(array_intersect_key($data, array_flip(['driver', 'tin', 'branch_code', 'device_serial'])));

            if (array_key_exists('settings', $data)) {
                $settings->settings = array_filter([...(array) $settings->settings, ...$data['settings']], fn ($value) => $value !== null);
            }

            $credentials = (array) ($settings->credentials ?? []);

            if ($settings->exists && $settings->isDirty(self::IDENTITY)) {
                $credentials = [];
                $settings->initialized_at = null;
                $settings->enabled = false;
            }

            if (array_key_exists('credentials', $data)) {
                $credentials = array_filter([...$credentials, ...$data['credentials']], fn ($value) => $value !== null);
            }

            $settings->credentials = $credentials === [] ? null : $credentials;

            if (array_key_exists('enabled', $data)) {
                $settings->enabled = (bool) $data['enabled'];
            }

            if ($settings->enabled) {
                $this->assertReady($settings);
            }

            $settings->save();

            return $settings;
        });

        return FiscalSettingsResource::make($settings)->response()->setStatusCode($settings->wasRecentlyCreated ? 201 : 200);
    }

    public function initialize(InitializeFiscalRequest $request, Company $company): FiscalSettingsResource
    {
        $settings = FiscalSettings::query()->where('company_id', $company->id)->first()
            ?? throw new ApiException(422, 'fiscal_not_configured', __('fiscal.errors.not_configured'));
        $driver = $this->drivers->get($settings->driver);

        if (! $driver->available()) {
            throw new ApiException(422, 'driver_unavailable', __('fiscal.errors.driver_unavailable'));
        }

        try {
            $issued = $driver->initialize($settings);
        } catch (LocalRejection $e) {
            throw new ApiException(422, $e->reason, $e->getMessage());
        }

        $settings->fill([
            'settings' => [...(array) $settings->settings, ...$issued['settings']],
            'credentials' => [...(array) ($settings->credentials ?? []), ...$issued['credentials']],
            'initialized_at' => CarbonImmutable::now(),
        ])->save();

        return FiscalSettingsResource::make($settings);
    }

    private function assertReady(FiscalSettings $settings): void
    {
        if (! $this->drivers->get($settings->driver)->available()) {
            throw new ApiException(422, 'driver_unavailable', __('fiscal.errors.driver_unavailable'));
        }

        $missing = $this->check->missing($settings);

        if ($missing !== []) {
            throw new ApiException(422, 'fiscal_not_ready', __('fiscal.errors.not_ready', ['fields' => implode(', ', $missing)]));
        }
    }
}
