<?php

namespace Modules\Procurement\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Http\Middleware\PortalSessionMiddleware;
use Modules\Procurement\Models\PortalSession;

/**
 * A supplier portal request (PRC-07): the portal session middleware has
 * already proven the session, entered its tenant and bound its tender and
 * bidder; nothing in the body may name another. Bodiless actions (sign
 * out, withdraw) use this class as it is.
 */
class PortalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    public function session(): ?PortalSession
    {
        $session = $this->attributes->get(PortalSessionMiddleware::ATTRIBUTE);

        return $session instanceof PortalSession ? $session : null;
    }
}
