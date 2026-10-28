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
     * @param  string|null  $image  file name in docs/manual/images/{locale}, from `image:` or `{id}.png` when that file exists
     * @param  string|null  $imageAlt  the picture's alt text in the topic's language (`image_alt:`)
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
        public readonly ?string $image = null,
        public readonly ?string $imageAlt = null,
    ) {}
}
