<?php

return [
    // CP-01, PAY-07: where the payroll packs are read from (`{dir}/{CODE}/payroll.json`).
    // The shipped packs have no figures; a local or staging environment may point this
    // at a directory of test packs (never committed) to try runs end to end.
    'pack_dir' => env('PAYROLL_PACK_DIR'),

    // Decision 41 (owner may change): a department paid to fewer employees than this in a
    // run posts its payroll journal at company level, so no journal shows one person's pay.
    'department_min_employees' => 3,
];
