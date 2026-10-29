<?php

namespace App\Core\Search;

/** One search result: what the palette shows and the web page it opens. */
final class RecordHit
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $path,
    ) {}
}
