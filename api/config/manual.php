<?php

return [
    /*
    | Where the manual's topic files live: one folder per locale
    | (en, fr), each holding {module}/{task}.md. The repository keeps them
    | in docs/manual, next to the API.
    */
    'path' => env('MANUAL_PATH', base_path('../docs/manual')),

    /*
    | The design tokens the PDF reads its quiet greys from (light theme).
    */
    'tokens' => env('MANUAL_TOKENS', base_path('../design/tokens.json')),
];
