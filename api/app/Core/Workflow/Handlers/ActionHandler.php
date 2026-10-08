<?php

namespace App\Core\Workflow\Handlers;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * What an `action` node does (WF-07; automation actions, AUTO-03, reuse
 * the registry). Registered in ActionHandlers by key; a node names it in
 * `action` and configures it in `config`.
 */
interface ActionHandler
{
    /** The `action` value in a node, e.g. `create_document`. */
    public function key(): string;

    /**
     * Problems with a node's `config`, translated (empty when valid).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function validate(array $config, DocumentType $type): array;

    /**
     * Do it, inside the engine's transaction. Returns what to record in the
     * flow's history (JSON-safe). Throwing rolls the whole move back.
     *
     * @return array<string, mixed>
     */
    public function run(ActionContext $context): array;

    /**
     * What it would do, in the reader's language, for a dry run (no writes).
     *
     * @param  array<string, mixed>  $config
     */
    public function describe(array $config, DocumentType $type): string;
}
