<?php

namespace App\Core\CustomFields;

use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\CustomFields\Entities\UserLookup;
use App\Core\CustomFields\Http\Requests\CustomFieldAccessRules;
use App\Core\CustomFields\Jobs\RestampCustomFieldRecords;
use App\Core\Identity\Models\User;
use App\Core\MasterData\History\HistoryTypes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * CF-01..CF-03, CF-06: the custom field core. Registers the built-in
 * entities (items, parties) and lookup targets (users), the definitions'
 * history type, and keeps caches and the till in step when a definition
 * changes.
 */
class CustomFieldsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomFieldEntities::class);
    }

    public function boot(): void
    {
        // CF-01: file uploads for custom fields, per user (10 MB each).
        RateLimiter::for('custom-field-files', fn (Request $request) => Limit::perMinute(20)->by('user|'.($request->user()?->id ?? $request->ip())));
        $entities = $this->app->make(CustomFieldEntities::class);
        $entities->register(new ItemEntity);
        $entities->register(new PartyEntity);
        $entities->registerLookup(new UserLookup);

        // MD-07: a definition's history, for whoever sees definitions.
        $this->app->make(HistoryTypes::class)->register(
            'custom_field', CustomFieldDefinition::class, null,
            fn (User $user, CustomFieldDefinition $field) => CustomFieldAccessRules::views($user),
        );

        CustomFieldDefinition::saved(function (CustomFieldDefinition $field) {
            $this->app->make(CustomFieldDefinitions::class)->forget();

            // NFR-04: values that start or stop travelling to the till re-stamp the entity's records.
            // (A new field has no values yet: records get them when saved.)
            if ($field->wasChanged('show_on_pos') || ($field->show_on_pos && $field->wasChanged('archived_at'))) {
                RestampCustomFieldRecords::dispatch($field->tenant_id, $field->entity)->afterCommit();
            }
        });
    }
}
