<?php

namespace App\Core\Sso;

use App\Core\Privacy\PrivacySubjects;
use App\Core\Privacy\SubjectSource;
use App\Core\Sso\Console\RefreshSamlMetadataCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * AUTH-11, ADR 023: enterprise single sign-on (OIDC with Entra ID and
 * Google Workspace, SAML 2.0), per-tenant connections and email domains.
 */
class SsoServiceProvider extends ServiceProvider
{
    /** Start, callbacks and completion: per address a minute. */
    public const LIMITER = 'sso';

    public function register(): void
    {
        // NFR-08 (Phase 8 review L1): a user's links to identity providers are their data: in the
        // access report, and on deletion the asserted email and subject go and the links are archived
        // (TEN-06), so the IdP can no longer sign the anonymised account in.
        $this->callAfterResolving(PrivacySubjects::class, fn (PrivacySubjects $subjects) => $subjects->register(new SubjectSource(
            subjectType: 'user', table: 'sso_identities', match: 'user_id',
            personal: ['email' => 'anon_email', 'subject' => 'label'],
        )));
    }

    public function boot(): void
    {
        RateLimiter::for(self::LIMITER, fn (Request $request) => Limit::perMinute((int) config('sso.start_per_minute', 20))->by('sso|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshSamlMetadataCommand::class]);
        }
    }
}
