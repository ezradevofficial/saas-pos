<?php

namespace App\Core\Marketplace\Http\Requests;

/**
 * MKT-01: a new webhook secret for the app. The previous one keeps signing
 * for the overlap, unless `immediate` ends it at once (a secret that leaked).
 */
class RotateWebhookSecretRequest extends DeveloperRequest
{
    public function rules(): array
    {
        return [
            'immediate' => ['sometimes', 'boolean'],
        ];
    }
}
