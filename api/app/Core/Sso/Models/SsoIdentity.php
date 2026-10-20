<?php

namespace App\Core\Sso\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUTH-11, ADR 023: a user linked to an IdP subject (OIDC `sub`, SAML
 * NameID). One live link per user and connection; a subject names one
 * user. Unlinking, or repointing the connection at another IdP, archives it.
 *
 * @property string $id
 * @property string $user_id
 * @property string $connection_id
 * @property string $subject
 * @property string $email
 */
class SsoIdentity extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['user_id', 'connection_id', 'subject', 'email', 'last_sign_in_at', 'archived_at'];

    protected function casts(): array
    {
        return ['last_sign_in_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
