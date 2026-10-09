<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Place;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Validation\Validator;

/**
 * LAY-06: GET config/{kind}/resolved?key=&company=&branch=&location=: the
 * configuration that applies to the signed-in user at that place (any
 * level may be given; the levels above are read from the most specific
 * one). No configuration permission is needed: a cashier reads the POS
 * layout of their till. The place must be of this tenant and covered by
 * one of the user's role assignments (RBAC-04), else 422.
 */
class ResolveConfigRequest extends ConfigKindRequest
{
    protected bool $needsPermission = false;

    private Place|false|null $place = null;

    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:100'],
            'company' => ['sometimes', 'uuid'],
            'branch' => ['sometimes', 'uuid'],
            'location' => ['sometimes', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->place = Place::from($this->query('company'), $this->query('branch'), $this->query('location'));

            if ($this->place === false
                || ($this->place !== null && app(ScopeResolver::class)->roleIds($this->user(), $this->place->scope()) === [])) {
                $validator->errors()->add('location', __('config.errors.place_out_of_scope'));
            }
        }];
    }

    public function key(): string
    {
        return $this->validated('key', ConfigKind::DEFAULT_KEY);
    }

    public function place(): ?Place
    {
        return $this->place ?: null;
    }
}
