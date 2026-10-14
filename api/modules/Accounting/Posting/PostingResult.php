<?php

namespace Modules\Accounting\Posting;

use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;

/** ACC-07: what posting a source did: a journal (new or the one it already had) or an exception kept to retry. */
final class PostingResult
{
    public function __construct(
        public readonly ?Journal $journal,
        public readonly bool $created,
        public readonly ?PostingException $exception = null,
    ) {}

    public function posted(): bool
    {
        return $this->journal !== null;
    }
}
