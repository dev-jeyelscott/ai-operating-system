<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

/**
 * Contains validated metadata returned by one successful App Server handshake.
 */
final readonly class CodexGatewayInitialization
{
    /**
     * Create one immutable initialization result.
     */
    public function __construct(
        public string $userAgent,
        public string $codexHome,
        public string $platformFamily,
        public string $platformOs,
        public string $binaryVersion,
        public string $protocolVersion,
        public string $schemaFingerprint,
    ) {}
}
