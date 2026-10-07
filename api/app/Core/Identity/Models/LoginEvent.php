<?php

namespace App\Core\Identity\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One sign-in attempt of a known user (AUTH-10). Tenant table under RLS. */
class LoginEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'ip', 'user_agent', 'fingerprint', 'succeeded'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }

    /**
     * sha256(user agent . network): IPv4 /24, IPv6 /64, so a device keeps
     * its fingerprint when its address moves within the network.
     */
    public static function fingerprint(?string $userAgent, ?string $ip): string
    {
        return hash('sha256', (string) $userAgent.self::network($ip));
    }

    private static function network(?string $ip): string
    {
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = substr(inet_pton($ip), 0, 8).str_repeat("\0", 8);

            return inet_ntop($packed).'/64';
        }

        return '';
    }
}
