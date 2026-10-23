<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Ecommerce\Delivery\ProofPhotos;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Http\Requests\ProofPhotoFileRequest;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Policies\DeliveryPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * ECOM-41: a proof photo on the local media disk through a temporary
 * signed URL (ProofPhotos::present; the `signed` middleware checks the
 * signature and expiry). The path names the tenant and the delivery: the
 * controller enters the tenant, finds the delivery whose photo it is
 * (row-level security), and checks the module is still active and the
 * user the URL was signed for may still see the delivery. Anything else is
 * not found.
 */
class ProofPhotoController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/ecommerce/proofs/([0-9a-f\-]{36})/[0-9a-f\-]{36}\.(jpg|png|webp)\z#';

    public function __invoke(ProofPhotoFileRequest $request, TenantContext $tenants, DeliveryPolicy $policy, ProofPhotos $photos, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]) && Str::isUuid($parts[2]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $parts, $userId, $policy, $photos) {
            abort_unless(app(ModuleRegistry::class)->isActive(EcommerceServiceProvider::MODULE), 404);
            $delivery = Delivery::query()->whereKey($parts[2])->where('proof_photo_path', $path)->first();
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

            abort_unless($delivery !== null && $user !== null && $user->isActive() && $policy->view($user, $delivery), 404);

            return $photos->response($delivery);
        });
    }
}
