<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\ConfigPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request under config/{kind} (LAY-06). A kind that is not registered,
 * or whose module is not active for the tenant, is not found (RBAC-08).
 * Subclasses that set $needsPermission refuse users holding none of the
 * kind's permissions anywhere (403), unless the kind is `personal`: then
 * anyone gets through to their own user scope, which the policy checks
 * next (LAY-01, LAY-04).
 */
class ConfigKindRequest extends FormRequest
{
    protected bool $needsPermission = true;

    private ?ConfigKind $resolvedKind = null;

    public function authorize(): bool
    {
        $kind = $this->kind();

        return ! $this->needsPermission || $kind->personal || app(ConfigPolicy::class)->anywhere($this->user(), $kind);
    }

    public function rules(): array
    {
        return [];
    }

    public function kind(): ConfigKind
    {
        return $this->resolvedKind ??= app(ConfigKinds::class)->get((string) $this->route('kind'));
    }

    public function attributes(): array
    {
        return [
            'key' => __('config.attributes.key'),
            'name' => __('config.attributes.name'),
            'scope_type' => __('config.attributes.scope_type'),
            'scope_id' => __('config.attributes.scope_id'),
            'payload' => __('config.attributes.payload'),
            'version' => __('config.attributes.version'),
            'from' => __('config.attributes.from'),
            'company' => __('config.attributes.company'),
            'branch' => __('config.attributes.branch'),
            'location' => __('config.attributes.location'),
        ];
    }
}
