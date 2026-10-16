<?php

// Country-pack labels (CP-01): keyed by pack code and tax code. Business
// names of the tax codes a tenant gets are copied from here once, in the
// tenant's language, and are then the tenant's to rename.
return [
    'KE' => [
        'name' => 'Kenya',
        'VAT_STD' => 'VAT, standard rate',
        'VAT_ZERO' => 'VAT, zero-rated',
        'VAT_EXEMPT' => 'VAT exempt',
        'VAT_WHT' => 'VAT withholding',
    ],
    'CD' => [
        'name' => 'Democratic Republic of the Congo',
        'VAT_STD' => 'VAT, standard rate',
        'VAT_ZERO' => 'VAT, zero-rated',
        'VAT_EXEMPT' => 'VAT exempt',
    ],
    // ADM-04 (ADR 022): pack releases in the platform console.
    'releases' => [
        'wrong_country' => 'The document is for :code, not :country. Load it for its own country.',
        'unknown_kind' => 'This kind of pack (:kind) isn’t known.',
        'schema' => 'The document doesn’t have the shape of this pack: :reason',
        'overlap' => 'Two periods of :key in :path overlap. Close one before the next starts.',
        'missing_value' => ':path is marked confirmed but has no value for :fields. Enter the figures, or mark it as needing confirmation.',
        'needs_confirmation' => ':path still needs confirmation; what uses it stays blocked until it is confirmed.',
        'content_shape' => 'The document must be a JSON object of at most 512 KB.',
        'not_draft' => 'Only a draft release can be approved.',
        'invalid' => 'This release has validation errors. Fix them and load it again.',
        'own_load' => 'You loaded this release, so someone else must approve it.',
        'decided' => 'This release was already published or rejected.',
        'not_approved' => 'Only an approved release can be published.',
        'own_approval' => 'You approved this release, so someone else must publish it.',
    ],
];
