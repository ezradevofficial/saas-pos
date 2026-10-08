<?php

namespace App\Core\Lists\Http;

use App\Core\MasterData\Http\Requests\ListsArchivable;

/**
 * The list contract on a FormRequest for archivable records (lists and
 * pickers plan, API contract): ListsArchivable's `?status` and
 * `?per_page`, plus SortsAndExports' `?sort`, `?search` helper and export
 * (`?format=csv|xlsx|pdf`, `?columns[]`).
 */
trait ListsRecords
{
    use ListsArchivable {
        listRules as archivableRules;
    }
    use SortsAndExports;

    /** @return array<string, list<mixed>> */
    protected function listRules(): array
    {
        return [
            ...$this->archivableRules(),
            ...$this->sortAndExportRules(),
        ];
    }
}
