<?php

return [
    // HR-10: the key of identifier digests (uniqueness of ID numbers without
    // the clear value). Separate from APP_KEY; required outside local and
    // testing (EnvironmentGuard). After changing it, run `hr:rekey-digests`.
    'digest_key' => env('HR_DIGEST_KEY'),
];
