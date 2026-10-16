<?php

namespace Modules\Ecommerce\Shop;

use App\Core\Branding\Models\TenantDomain;
use App\Core\Tenancy\Models\Tenant;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Store;

/**
 * ECOM-05, ECOM-21, ECOM-23: a store's public addresses on the tenant's
 * host (BR-04, BR-05): its verified custom domain when it has one, else
 * `{slug}.{branding.base_domain}`, else the app's own address; with the
 * scheme and port of the app's address (so local work keeps its port).
 */
final class ShopUrls
{
    public function base(Store $store): string
    {
        $frontend = (string) config('app.frontend_url', config('app.url'));
        $scheme = parse_url($frontend, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($frontend, PHP_URL_PORT);
        $domain = TenantDomain::query()->where('status', 'verified')->whereNull('archived_at')->orderBy('verified_at')->value('host');
        $slug = Tenant::query()->whereKey($store->tenant_id)->value('slug');
        $baseDomain = (string) config('branding.base_domain');
        $host = match (true) {
            is_string($domain) && $domain !== '' => $domain,
            is_string($slug) && $slug !== '' && $baseDomain !== '' => "{$slug}.{$baseDomain}",
            default => null,
        };

        if ($host === null) {
            return rtrim($frontend, '/');
        }

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    public function store(Store $store): string
    {
        return $this->base($store).'/shop/'.$store->handle;
    }

    public function product(Store $store, Product $product): string
    {
        return $this->store($store).'/products/'.$product->slug;
    }

    public function tracking(Store $store, Order $order, string $token): string
    {
        return $this->store($store).'/orders/'.rawurlencode($order->number).'?t='.$token;
    }

    /** ECOM-23: a WhatsApp share link for $text (to the store's number when given). */
    public static function whatsapp(string $text, ?string $number = null): string
    {
        $to = $number === null ? '' : preg_replace('/\D+/', '', $number);

        return 'https://wa.me/'.($to ?: '').'?text='.rawurlencode($text);
    }
}
