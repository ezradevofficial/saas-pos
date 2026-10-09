<?php

namespace App\Core\Branding\Http\Controllers;

use App\Core\Branding\BrandingSettings;
use App\Core\Branding\Http\Requests\DomainRequest;
use App\Core\Branding\Http\Requests\UpdateBrandingSettingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * BR-04, BR-06, BR-07: the subdomain, the email sender (with SPF and DKIM
 * guidance for the owner's DNS) and the SMS sender ID
 * (`core.domain.manage`, tenant scope). `hide_platform` is shown, never
 * changed here: only the platform sets it (`tenant:branding`).
 */
class BrandingSettingsController
{
    public function __construct(private readonly BrandingSettings $settings) {}

    public function show(DomainRequest $request): JsonResponse
    {
        return $this->respond($this->settings->values());
    }

    public function update(UpdateBrandingSettingsRequest $request): JsonResponse
    {
        return $this->respond($this->settings->update($request->validated()));
    }

    private function respond(array $values): JsonResponse
    {
        return response()->json([
            'data' => [...$values, 'email_sender_active' => $this->settings->sender() !== null],
            'meta' => ['mail' => [
                'spf_include' => config('branding.mail.spf_include'),
                'dkim_selector' => config('branding.mail.dkim_selector'),
                'dkim_target' => config('branding.mail.dkim_target'),
            ]],
        ]);
    }
}
