<?php

declare(strict_types=1);

return [
    'patterns' => [
        '/\bghp_[A-Za-z0-9]{36}\b/',
        '/\bsk-[A-Za-z0-9]{20,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/(?i)\b(password|secret|token|api[_-]?key)\s*[:=]\s*[^\s,;]+/',
    ],
];
