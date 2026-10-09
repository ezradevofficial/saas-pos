<?php

namespace App\Core\DocumentTemplates;

/**
 * One document ready to render (TPL-04): its type and record, the data in
 * the DataSource shape, and the place its template is resolved for
 * (TPL-05). `email` is the customer's address, when there is one.
 */
final class DocumentData
{
    public function __construct(
        public readonly string $type,
        public readonly string $recordId,
        public readonly array $data,
        public readonly ?string $companyId,
        public readonly ?string $branchId,
        public readonly ?string $country,
        public readonly ?string $email = null,
    ) {}

    public function number(): string
    {
        return (string) ($this->data['document']['number'] ?? '');
    }

    /** A file name safe on every system: `receipt-R-L01-000001.pdf`. */
    public function fileName(string $extension = 'pdf'): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', str_replace('.', '-', $this->type).'-'.$this->number());

        return trim((string) $base, '-').'.'.$extension;
    }
}
