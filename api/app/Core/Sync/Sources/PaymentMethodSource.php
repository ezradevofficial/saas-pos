<?php

namespace App\Core\Sync\Sources;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * MD-04, NFR-04: the device's company's active payment methods in till
 * order. Never the provider's settings or secrets: the till asks the
 * server to start a mobile money or card payment. `capabilities` tells the
 * till what it may offer: `stk` (a push to the customer's phone: an M-Pesa
 * method on the Daraja adapter with every required setting and secret)
 * and `manual_code` (the cashier types the provider's code: any mobile
 * money method). Version 2 added `capabilities`.
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
        return 2;
    }

    public function rows(DeviceScope $scope): array
    {
        return PaymentMethod::query()
            ->where('company_id', $scope->companyId())
            ->where('active', true)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'type' => $method->type,
                'name' => $method->name,
                'currency' => $method->currency,
                'provider' => $method->provider,
                'position' => $method->position,
                'capabilities' => [
                    'stk' => $method->provider === 'mpesa_ke'
                        && app(PaymentProviderRegistry::class)->driverName($method) === 'mpesa_daraja'
                        && app(PaymentProviders::class)->missing($method) === [],
                    'manual_code' => $method->type === 'mobile_money',
                ],
            ])
            ->values()
            ->all();
    }
}
