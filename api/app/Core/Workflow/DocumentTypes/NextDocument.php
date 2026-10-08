<?php

namespace App\Core\Workflow\DocumentTypes;

use InvalidArgumentException;

/**
 * WF-07: a document a flow of this type may create, e.g. an approved
 * requisition creates a draft purchase order. An `action` node with
 * `action: create_document` names the mapping by key. The engine reads the
 * source's fieldValues(), maps them (`fields`: target field => source
 * field) and asks the target type to create the draft (createDraft()); it
 * never writes the target module's tables itself.
 */
final class NextDocument
{
    /**
     * @param  string  $key  the mapping key used in flow graphs
     * @param  string  $target  the target document type key
     * @param  string  $label  translation key shown in the builder
     * @param  array<string, string>  $fields  target field => source field
     */
    public function __construct(
        public readonly string $key,
        public readonly string $target,
        public readonly string $label,
        public readonly array $fields,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid next-document key [{$key}].");
        }
    }

    /**
     * The target's values from the source's.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function map(array $source): array
    {
        $mapped = [];

        foreach ($this->fields as $target => $from) {
            $mapped[$target] = $source[$from] ?? null;
        }

        return $mapped;
    }

    /** @return array{key: string, target: string, label: string, fields: array<string, string>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'target' => $this->target, 'label' => __($this->label), 'fields' => $this->fields];
    }
}
