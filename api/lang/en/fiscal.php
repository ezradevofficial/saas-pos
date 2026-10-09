<?php

// Fiscal transmission (concept note 7.2): API messages, list labels and alerts.
return [
    'errors' => [
        'duplicate_invoice' => 'The tax authority already holds fiscal invoice :number with other content. Check the numbering with the authority before retrying.',
        'original_not_accepted' => 'The sale this document reverses was not accepted by the tax authority. Resolve the sale first, then retry this document.',
        'tax_rate_missing' => 'The item “:item” carries tax but no tax rate. Check the sale’s tax data, then retry.',
        'confirm_required' => 'Confirm that the documents since this date should be sent to the tax authority.',
        'not_enabled' => 'Switch transmission on before sending earlier sales.',
        'currency_unconfirmed' => 'eTIMS currency handling for USD sales needs confirming. This :currency document is held until it is decided, then retried.',
        'tax_code_missing' => 'The item “:item” has no tax code, so it can’t be sent to the tax authority. Give the item a tax category, then retry.',
        'fiscal_code_missing' => 'The item “:item” uses the tax code :code, which has no fiscal code. Set the tax code’s fiscal code, then retry.',
        'fiscal_code_unknown' => 'The tax code :code has the fiscal code :fiscal_code, which the tax authority doesn’t know. Use one of :allowed, then retry.',
        'driver_unavailable' => 'Transmission to this tax authority isn’t available yet. Leave transmission off for now.',
        'band_rate_conflict' => 'Lines of tax band :band were sold at different rates. Check the tax codes and rates, then retry.',
        'item_code_missing' => 'The item “:item” has no code. Give it a code, then retry.',
        'item_class_missing' => 'The item “:item” has no tax authority classification, and the company has no default. Set one, then retry.',
        'unit_code_missing' => 'The item “:item” has no tax authority unit code, and the company has no default. Set one, then retry.',
        'settings_missing' => 'Enter :fields first.',
        'authority_unavailable' => 'The tax authority couldn’t be reached. It will be tried again.',
        'initialize_refused' => 'The tax authority refused to initialise the device (:code: :message). Check the PIN, branch id and device serial.',
        'not_initialized' => 'The device isn’t initialised with the tax authority yet. Initialise it in the fiscal settings, then retry.',
        'authority_refused' => 'The tax authority refused the document (:code: :message).',
        'unexpected' => 'Sending failed unexpectedly. It will be tried again.',
        'settings_unknown' => 'Use only these settings: :keys.',
        'credentials_unknown' => 'Use only these credentials: :keys.',
        'driver_invalid' => 'Choose a tax authority available for the company’s country.',
        'country_unsupported' => 'Fiscal transmission isn’t available for this company’s country.',
        'not_configured' => 'Save the company’s fiscal settings first.',
        'not_ready' => 'Transmission can’t be switched on yet. Enter :fields first.',
        'already_accepted' => 'The tax authority already accepted this document.',
    ],

    'document_types' => [
        'sale' => 'Sale',
        'refund' => 'Refund',
        'void' => 'Void',
    ],

    'statuses' => [
        'queued' => 'Queued',
        'sending' => 'Sending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'retrying' => 'Retrying',
        'needs_attention' => 'Needs attention',
    ],

    'filters' => [
        'all' => 'All',
        'pending' => 'Not accepted yet',
        'queued' => 'Queued',
        'sending' => 'Sending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'retrying' => 'Retrying',
        'needs_attention' => 'Needs attention',
    ],

    'submissions' => [
        'list_title' => 'Fiscal submissions of :company',
        'columns' => [
            'created_at' => 'Queued',
            'document_type' => 'Document',
            'document_number' => 'Number',
            'invoice_number' => 'Fiscal invoice',
            'status' => 'Status',
            'attempts' => 'Attempts',
            'error' => 'Last error',
        ],
    ],

    'notifications' => [
        'needs_attention' => [
            'label' => 'Fiscal document needs a decision',
            'subject' => '{document_type} {document_number} is held before sending to the tax authority',
            'body' => "Hello {recipient_name},\n\n{document_type} {document_number} of {company_name} is held: {error}\n\nOnce it is decided, retry it from the fiscal queue.",
            'sms' => '{app_name}: {document_type} {document_number} is held before sending to the tax authority. Open the fiscal queue.',
        ],
        'rejected' => [
            'label' => 'Fiscal document rejected',
            'subject' => '{document_type} {document_number} was rejected by the tax authority',
            'body' => "Hello {recipient_name},\n\n{document_type} {document_number} of {company_name} was not accepted: {error}\n\nFix what is missing, then retry it from the fiscal queue.",
            'sms' => '{app_name}: {document_type} {document_number} was rejected by the tax authority. Open the fiscal queue.',
        ],
        'delayed' => [
            'label' => 'Fiscal document not sent yet',
            'subject' => '{document_type} {document_number} is not accepted after {hours} hours',
            'body' => "Hello {recipient_name},\n\n{document_type} {document_number} of {company_name} has not been accepted by the tax authority after {hours} hours. Last error: {error}\n\nIt is still being retried. Check the connection and the fiscal settings.",
            'sms' => '{app_name}: {document_type} {document_number} is not accepted by the tax authority after {hours} hours.',
        ],
    ],
];
