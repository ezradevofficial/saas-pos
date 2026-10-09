<?php

namespace App\Core\Configuration;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Http\ApiException;

/**
 * 409 on a configuration document (LAY-06), answered with the document as
 * it is now (ConfigController), so the designer can show what changed:
 *
 * - `config_changed`: the draft is no longer the revision the request
 *   edited or reviewed (someone saved, published or discarded it since);
 * - `config_draft_exists`: a copy would replace the draft at the target
 *   and the request did not say `replace: true`.
 */
class ConfigConflict extends ApiException
{
    public const CHANGED = 'config_changed';

    public const DRAFT_EXISTS = 'config_draft_exists';

    public function __construct(public readonly ConfigDocument $document, string $code = self::CHANGED)
    {
        parent::__construct(409, $code, __('config.errors.'.$code));
    }
}
