<?php

namespace App\Core\DocumentTemplates\Http\Requests;

use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\ConfigPolicy;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\DocumentTemplates\TemplateResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TPL-01: a designer request (document types, previews): the user holds
 * one of the template permissions somewhere (`core.template.*`, RBAC-01).
 * A company or branch named must be of this tenant (row-level security
 * hides every other) and seen by the user (RBAC-04).
 */
abstract class TemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ConfigPolicy::class)->anywhere($this->user(), app(ConfigKinds::class)->get(TemplateResolver::KIND));
    }

    public function attributes(): array
    {
        return [
            'type' => __('templates.attributes.type'),
            'payload' => __('templates.attributes.payload'),
            'company' => __('config.attributes.company'),
            'branch' => __('config.attributes.branch'),
            'scope_type' => __('config.attributes.scope_type'),
            'scope_id' => __('config.attributes.scope_id'),
        ];
    }

    /** A config document standing for the scope (not saved), for the fiscal rules (TPL-03). */
    public function scopeDocument(?string $type, ?string $id): ?ConfigDocument
    {
        // The tenant scope takes no id, or the tenant's own (as config saves do).
        if ($type === ConfigDocument::TENANT && $id !== null && $id !== app(TenantContext::class)->id()) {
            throw ValidationException::withMessages(['scope_id' => __('validation.exists', ['attribute' => __('config.attributes.scope_id')])]);
        }

        if ($type === null || $type === ConfigDocument::TENANT || $id === null) {
            return null;
        }

        $exists = Str::isUuid($id) && match ($type) {
            ConfigDocument::COMPANY => Company::query()->whereKey($id)->exists(),
            ConfigDocument::BRANCH => Branch::query()->whereKey($id)->exists(),
            default => false,
        };

        abort_unless($exists && app(ConfigPolicy::class)->allows($this->user(), app(ConfigKinds::class)->get(TemplateResolver::KIND), 'view', $type, $id), 404);

        return (new ConfigDocument)->forceFill(['scope_type' => $type, 'scope_id' => $id]);
    }
}
