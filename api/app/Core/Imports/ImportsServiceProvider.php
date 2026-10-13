<?php

namespace App\Core\Imports;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\Imports\Importers\CustomersImporter;
use App\Core\Imports\Importers\ItemPricesImporter;
use App\Core\Imports\Importers\ItemsImporter;
use App\Core\Imports\Importers\SuppliersImporter;
use App\Core\MasterData\RecordUsage;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * IMP-01..IMP-03: the importer registry with core's importers (items,
 * customers, suppliers, item prices), where core's records are used
 * (RecordUsage: prices, credit limit requests, custom field lookups) and
 * the upload rate limit. Modules register their own importers and usage
 * checks the same way (ADR 014).
 */
class ImportsServiceProvider extends ServiceProvider
{
    public const LIMITER = 'imports';

    public function register(): void
    {
        $this->app->singleton(ImportRegistry::class);
        $this->app->singleton(RecordUsage::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(ImportRegistry::class);
        $registry->register(new ItemsImporter);
        $registry->register(new CustomersImporter);
        $registry->register(new SuppliersImporter);
        $registry->register(new ItemPricesImporter);

        $usage = $this->app->make(RecordUsage::class);
        $db = fn () => DB::connection(TenantContext::CONNECTION);
        $usage->register('item', 'price', fn (array $ids) => $db()->table('item_prices')->whereIn('item_id', $ids)->whereNull('archived_at')->distinct()->pluck('item_id')->all());
        $usage->register('party', 'document', fn (array $ids) => $db()->table('credit_limit_changes')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());

        // Records named by another record's lookup field (custom forms are documents).
        foreach (['item', 'party'] as $target) {
            $usage->register($target, 'document', function (array $ids) use ($target) {
                $used = [];
                $entities = app(CustomFieldEntities::class);

                foreach (CustomFieldDefinition::query()->where('type', 'lookup')->where('lookup_target', $target)->get(['entity', 'key']) as $field) {
                    $entity = $entities->find($field->entity);

                    if ($entity !== null) {
                        $used = [...$used, ...$entity->newQuery()->whereIn(DB::raw("custom ->> '{$field->key}'"), $ids)->pluck('custom')
                            ->map(fn ($custom) => (is_array($custom) ? $custom : (array) json_decode((string) $custom, true))[$field->key] ?? null)->filter()->all()];
                    }
                }

                return array_values(array_unique($used));
            });
        }

        RateLimiter::for(self::LIMITER, fn (Request $request) => Limit::perMinute(ImportLimits::UPLOADS_PER_MINUTE)
            ->by('user|'.($request->user()?->id ?? $request->ip())));
    }
}
