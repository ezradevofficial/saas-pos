<?php

namespace App\Core\CustomForms;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CF-04: a form the tenant built ("Petty cash request"). Its key never
 * changes (numbers, flows and fields name it); its name is typed once.
 * Its header fields are custom fields of `custom_form:<key>`, its line
 * fields of `custom_form_line:<key>` (CF-05). Registered at run time as a
 * numbered document type (NUM-01) and a workflow document type (WF-01).
 * Archived, never deleted (TEN-06); audited as `core.custom_form_type.*`.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property bool $workflow
 * @property bool $has_lines
 * @property bool $attachments
 * @property list<string> $line_fields
 * @property list<string> $role_ids
 */
class CustomFormType extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,29}\z/';

    public const ENTITY_PREFIX = 'custom_form:';

    public const LINE_ENTITY_PREFIX = 'custom_form_line:';

    /** The document type (WF-01) and number type (NUM-01) key prefix. */
    public const DOCUMENT_PREFIX = 'core.custom_form_';

    /** The form layout key prefix (LAY-03). */
    public const LAYOUT_PREFIX = 'custom_form.';

    protected $fillable = ['key', 'name', 'description', 'number_type', 'workflow', 'has_lines', 'line_fields', 'attachments', 'role_ids'];

    protected $attributes = ['workflow' => false, 'has_lines' => false, 'attachments' => false, 'line_fields' => '[]', 'role_ids' => '[]'];

    protected function casts(): array
    {
        return ['workflow' => 'boolean', 'has_lines' => 'boolean', 'attachments' => 'boolean', 'line_fields' => 'array', 'role_ids' => 'array'];
    }

    public function entity(): string
    {
        return self::ENTITY_PREFIX.$this->key;
    }

    public function lineEntity(): string
    {
        return self::LINE_ENTITY_PREFIX.$this->key;
    }

    public function documentType(): string
    {
        return self::DOCUMENT_PREFIX.$this->key;
    }

    public function layoutKey(): string
    {
        return self::LAYOUT_PREFIX.$this->key;
    }
}
