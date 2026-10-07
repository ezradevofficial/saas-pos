<?php

namespace App\Core\Localisation\Http;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response language (L10N-01): a supported language from
 * Accept-Language, else the tenant's default_locale when a tenant is set,
 * else the application locale.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'fr'];

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->fromHeader($request) ?? $this->tenantDefault() ?? config('app.locale'));

        return $next($request);
    }

    private function fromHeader(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');
        $best = null;
        $bestQuality = 0.0;

        foreach (explode(',', $header) as $part) {
            [$tag, $params] = array_pad(explode(';', trim($part), 2), 2, '');
            $language = strtolower(explode('-', trim($tag))[0]);
            $quality = preg_match('/q\s*=\s*([0-9.]+)/', $params, $m) ? (float) $m[1] : 1.0;

            if (in_array($language, self::SUPPORTED, true) && $quality > $bestQuality) {
                $best = $language;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    private function tenantDefault(): ?string
    {
        $id = $this->context->id();

        if ($id === null) {
            return null;
        }

        // One query per request: the middleware runs once and the result is used immediately.
        $locale = Tenant::whereKey($id)->value('default_locale');

        return in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}
