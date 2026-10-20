<?php

// NFR-08: data subject requests and consent records.
return [
    'anonymised_label' => 'Anonymised :ref',
    'errors' => [
        'subject_not_found' => 'No such person in this business. Pick the person again from the list.',
        'not_correctable' => 'This field cannot be corrected through a request. Edit it on the record instead.',
        'not_approved' => 'This request is not approved yet. Approve it first, then carry it out.',
        'already_decided' => 'This request was already decided. Reload the page to see its status.',
        'own_request' => 'You logged this request, so someone else must approve or reject it.',
        'report_unavailable' => 'The report is ready once an access request is carried out.',
    ],
];
