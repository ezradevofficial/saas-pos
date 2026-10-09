<?php

namespace App\Core\DocumentTemplates;

use App\Core\Approvals\Http\NoReferrer;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\DocumentTemplates\Http\Controllers\SharedDocumentController;
use App\Core\Localisation\Http\SetLocale;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * TPL-01..TPL-05: document templates.
 *
 * - The `template` configuration kind (LAY-06): key = document type,
 *   scopes tenant, company and branch, permissions `core.template.*`,
 *   validated by TemplateSchema with the fiscal rules of the document's
 *   country (TPL-03, FiscalRules).
 * - Data sources: sample sources for the types whose modules don't exist
 *   yet; modules register real ones (the POS module: receipts).
 * - The public link `/d/{token}` of a shared document (TPL-04).
 */
class DocumentTemplatesServiceProvider extends ServiceProvider
{
    public const SHARE_LIMITER = 'document-share';

    public function register(): void
    {
        $this->app->singleton(DataSources::class);
        $this->app->scoped(FiscalRules::class);
    }

    public function boot(): void
    {
        $sources = $this->app->make(DataSources::class);

        foreach (array_keys(SampleSource::CATALOGUE) as $type) {
            if ($sources->find($type) === null) {
                $sources->register(new SampleSource($type));
            }
        }

        $this->app->make(ConfigKinds::class)->register(new ConfigKind(
            key: TemplateResolver::KIND,
            schema: fn (array $payload, ?ConfigDocument $document = null) => $document === null || $sources->find($document->key) === null
                ? []
                : TemplateSchema::problems($payload, $document->key, app(FiscalRules::class)->requiredAt($document->key, $document)),
            scopes: [ConfigDocument::TENANT, ConfigDocument::COMPANY, ConfigDocument::BRANCH],
            permissions: ['view' => 'core.template.view', 'edit' => 'core.template.edit', 'publish' => 'core.template.publish'],
            keys: DocumentTypes::keys(),
        ));

        RateLimiter::for(self::SHARE_LIMITER, fn (Request $request) => Limit::perMinute(30)->by('ip|'.$request->ip()));

        if (! $this->app->routesAreCached()) {
            // TPL-04: a shared document; the token is the credential (SharedDocumentController).
            Route::get('d/{token}', SharedDocumentController::class)
                ->where('token', '[A-Za-z0-9]{'.DocumentShares::LENGTH.'}')
                ->middleware([SetLocale::class, NoReferrer::class, 'throttle:'.self::SHARE_LIMITER]);
        }
    }
}
