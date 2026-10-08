<?php

namespace App\Core\Workflow\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A document a flow created (WF-07) and what happens to it when the flow
 * is cancelled (WF-11): `keep` or `cancel`.
 *
 * @property string $node_id
 * @property string $mapping
 * @property string $target_type
 * @property string $target_document_id
 * @property string $on_cancel
 */
class DocumentWorkflowLink extends Model
{
    use BelongsToTenant, HasUuids;

    public const ON_CANCEL = ['keep', 'cancel'];

    protected $fillable = ['workflow_id', 'node_id', 'mapping', 'target_type', 'target_document_id', 'on_cancel', 'cancelled_at'];

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime'];
    }
}
