<?php

namespace App\Core\Manual;

/**
 * One task of the user manual, read from a Markdown file in
 * docs/manual/{locale}/{module}/{task}.md. Written once as a topic,
 * filtered by permission in the app later, built into a PDF per role
 * template now.
 */
final class Topic
{
    /**
     * @param  list<string>  $permissions  `module.resource.action` names the task needs
     * @param  list<string>  $roles  system role template keys the topic is written for
     * @param  list<string>  $screens  web routes or POS screens (`pos:SellScreen`)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $module,
        public readonly array $permissions,
        public readonly array $roles,
        public readonly array $screens,
        public readonly int $order,
        public readonly string $body,
        public readonly string $path,
    ) {}
}
