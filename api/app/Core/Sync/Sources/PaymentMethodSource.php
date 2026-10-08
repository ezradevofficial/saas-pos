<?php

namespace App\Core\Sync\Sources;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * MD-04, NFR-04: the device's company's active payment methods in till
 * order. Never the provider's settings or secrets: the till asks the
 * server to start a mobile money or card payment.
 */
class PaymentMethodSource implements SnapshotSource
{
    public function key(): string
    {
        return 'payment_methods';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        return PaymentMethod::query()
            ->where('company_id', $scope->companyId())
            ->where('active', true)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'type', 'name', 'currency', 'provider', 'position'])
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'type' => $method->type,
                'name' => $method->name,
                'currency' => $method->currency,
                'provider' => $method->provider,
                'position' => $method->position,
            ])
            ->values()
            ->all();
    }
}
