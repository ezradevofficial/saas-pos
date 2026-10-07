<?php

namespace App\Core\Tenancy\Http;

use App\Core\Audit\AuditContext;
use App\Core\Tenancy\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEN-05: device routes accept only a paired device's token (ability
 * `device`). Audit entries name the device and its location (AUD-02).
 */
class EnsureDeviceToken
{
    public function __construct(private readonly AuditContext $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        abort_unless($device instanceof Device && $device->tokenCan(Device::TOKEN_ABILITY), 403);

        $this->audit->setDeviceId($device->id)->setLocationId($device->location_id);

        return $next($request);
    }
}
