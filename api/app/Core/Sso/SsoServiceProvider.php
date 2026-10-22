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
    /** Starting a sign-in: per address a minute (Phase 8 review L3: a whole office starts from one address). */
    public const START_LIMITER = 'sso-start';

    /**
     * The IdP's callback (OIDC `state`), the SAML ACS (`RelayState`) and the
     * web app's completion (`ticket`): per flow, keyed by that value, so one
     * busy address never holds back another person's sign-in; with a looser
     * ceiling per address for values that name no flow.
     */
    public const CALLBACK_LIMITER = 'sso-callback';

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
        RateLimiter::for(self::START_LIMITER, fn (Request $request) => Limit::perMinute(max(1, (int) config('sso.start_per_minute', 60)))->by('sso-start|'.$request->ip()));
        RateLimiter::for(self::CALLBACK_LIMITER, fn (Request $request) => [
            Limit::perMinute(max(1, (int) config('sso.callback_per_flow_per_minute', 10)))->by('sso-flow|'.self::flowKey($request)),
            Limit::perMinute(max(1, (int) config('sso.callback_per_minute_per_ip', 300)))->by('sso-callback|'.$request->ip()),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshSamlMetadataCommand::class]);
        }
    }

    /** The flow a callback or completion names (its state, relay state or ticket), hashed; else the address. */
    private static function flowKey(Request $request): string
    {
        foreach (['ticket', 'state', 'RelayState'] as $field) {
            $value = $request->input($field);

            if (is_string($value) && $value !== '') {
                return hash('sha256', $field.'|'.$value);
            }
        }

        return 'none|'.$request->ip();
    }
}
