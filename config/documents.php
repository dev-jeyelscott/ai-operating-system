<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Document upload policy
    |--------------------------------------------------------------------------
    |
    | The MVP accepts text documents only. Archive containers, binary files,
    | and additional office formats remain outside the approved MVP scope.
    |
    */
    'upload' => [
        'max_bytes' => 20 * 1024 * 1024,

        'allowed_extensions' => [
            'md',
            'txt',
        ],
    ],
];
