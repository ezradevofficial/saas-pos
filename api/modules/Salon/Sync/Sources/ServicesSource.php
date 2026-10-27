<?php

namespace Modules\Salon\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Salon\Appointments\Pricing;
use Modules\Salon\Models\Service;
use Modules\Salon\SalonServiceProvider;

/** SAL-29, NFR-04: the service menu the till books and sells from: duration, buffer, who may do it, add-ons and today's list price. */
class ServicesSource implements SnapshotSource
{
    public function __construct(private readonly Pricing $pricing) {}

    public function key(): string
    {
        return 'salon_services';
    }

    public function module(): string
    {
        return SalonServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        return Service::query()->where('company_id', $scope->companyId())->whereNull('archived_at')->orderBy('category')->orderBy('name')->orderBy('id')->get()
            ->map(fn (Service $service) => [
                'id' => $service->id, 'item_id' => $service->item_id, 'name' => $service->name, 'category' => $service->category, 'kind' => $service->kind,
                'duration_minutes' => $service->duration_minutes, 'buffer_minutes' => $service->buffer_minutes, 'staff_ids' => $service->staff_ids,
                'resource_ids' => $service->resource_ids, 'addon_ids' => $service->addon_ids,
                'price_minor' => ($price = $this->pricing->unit($service, $scope->company, $scope->at)) === null ? null : (string) $price,
            ])->values()->all();
    }
}
