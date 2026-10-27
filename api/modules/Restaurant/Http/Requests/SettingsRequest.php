<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;

/**
 * GET restaurant/companies/{company}/settings (REST-13): any of Restaurant's
 * settings, floor or order views somewhere in the company; else not found.
 */
class SettingsRequest extends RestaurantRequest
{
    public function authorize(): bool
    {
        $company = $this->companyId();

        abort_unless(collect(['restaurant.settings.edit', 'restaurant.order.view', 'restaurant.floor.view'])
            ->contains(fn (string $permission) => $this->places()->inCompany($this->user(), $permission, $company)), 404);

        return true;
    }

    public function companyId(): string
    {
        return (string) $this->route('company')->getKey();
    }

    protected function target(): ?Scope
    {
        return null;
    }
}
