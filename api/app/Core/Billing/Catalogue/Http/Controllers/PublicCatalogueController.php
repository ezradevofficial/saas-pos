<?php

namespace App\Core\Billing\Catalogue\Http\Controllers;

use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Catalogue\Http\Requests\PublicCatalogueRequest;
use App\Core\Billing\Catalogue\Http\Requests\QuoteCatalogueRequest;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Pricing\QuoteError;
use App\Core\Http\ApiException;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * ONB-03: the public catalogue and live quote, before anyone signs up
 * (the onboarding module picker). Global data only, no tenant: rate-limited
 * per IP.
 */
class PublicCatalogueController extends Controller
{
    public function __construct(private readonly Catalogue $catalogue) {}

    /** GET catalogue?country=KE */
    public function index(PublicCatalogueRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->publicCatalogue(strtoupper((string) $request->input('country')))]);
    }

    /** POST catalogue/quote */
    public function quote(QuoteCatalogueRequest $request): JsonResponse
    {
        return response()->json(['data' => self::price($this->catalogue, $request->validated())]);
    }

    /**
     * Run a quote and turn a QuoteError into a translated 422 on its field.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function price(Catalogue $catalogue, array $input): array
    {
        try {
            return $catalogue->quote(
                strtoupper((string) $input['country']),
                array_values($input['modules'] ?? []),
                array_map('intval', $input['quantities'] ?? []),
                (string) $input['cycle'],
                filled($input['bundle'] ?? null) ? (string) $input['bundle'] : null,
                filled($input['coupon'] ?? null) ? (string) $input['coupon'] : null,
            );
        } catch (QuoteError $e) {
            $name = $e->key === null ? '' : (CatalogueModule::on(PlatformDatabase::READ)->where('key', $e->key)->first()?->localName() ?? CatalogueBundle::on(PlatformDatabase::READ)->where('key', $e->key)->first()?->localName() ?? $e->key);
            $message = __("billing.quote.{$e->reason}", ['name' => $name]);

            // ONB-03: an add-on that needs a bundle still names the bundle to pick.
            throw new ApiException(422, $e->reason, $message, [$e->field => [$message]], $e->suggestion === null ? [] : ['suggestion' => $e->suggestion]);
        }
    }
}
