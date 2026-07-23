<?php

declare(strict_types=1);

namespace App\Support\Environment;

use Illuminate\Contracts\Config\Repository;

final class ProductionConfigurationValidator
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Fail closed when any security-sensitive production setting is unsafe.
     */
    public function validate(): void
    {
        $violations = $this->violations();

        if ($violations !== []) {
            throw new InvalidProductionConfiguration($violations);
        }
    }

    /**
     * Return every redacted violation without contacting external services.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        $this->require(
            $violations,
            $this->string('app.env') === 'production',
            'APP_ENV must be production.',
        );
        $this->require(
            $violations,
            $this->config->get('app.debug') === false,
            'APP_DEBUG must be false.',
        );
        $this->require(
            $violations,
            $this->isHttpsProductionUrl($this->string('app.url')),
            'APP_URL must use HTTPS and a non-local host.',
        );
        $this->require(
            $violations,
            $this->selectedDriver(
                'database.default',
                'database.connections',
            ) === 'pgsql',
            'DB_CONNECTION must select a PostgreSQL connection.',
        );

        $this->require(
            $violations,
            $this->string('session.driver') === 'database',
            'SESSION_DRIVER must be database.',
        );
        $this->require(
            $violations,
            $this->config->get('session.encrypt') === true,
            'SESSION_ENCRYPT must be true.',
        );
        $this->require(
            $violations,
            $this->config->get('session.http_only') === true,
            'SESSION_HTTP_ONLY must be true.',
        );
        $this->require(
            $violations,
            $this->config->get('session.secure') === true,
            'SESSION_SECURE_COOKIE must be true.',
        );
        $this->require(
            $violations,
            in_array(
                strtolower($this->string('session.same_site')),
                ['lax', 'strict'],
                true,
            ),
            'SESSION_SAME_SITE must be lax or strict.',
        );
        $this->require(
            $violations,
            $this->selectedDriver('cache.default', 'cache.stores') === 'redis',
            'CACHE_STORE must select a Redis-backed cache store.',
        );
        $this->require(
            $violations,
            $this->selectedDriver('cache.limiter', 'cache.stores') === 'redis',
            'CACHE_LIMITER_STORE must select a Redis-backed cache store.',
        );
        $this->require(
            $violations,
            $this->selectedDriver('queue.default', 'queue.connections') === 'redis',
            'QUEUE_CONNECTION must select a Redis-backed queue connection.',
        );

        $this->validateMail($violations);
        $this->validateArtifactStorage($violations);
        $this->validateReverb($violations);

        return array_values(array_unique($violations));
    }

    /**
     * Reject non-delivery transports and local SMTP targets.
     *
     * @param  list<string>  $violations
     */
    private function validateMail(array &$violations): void
    {
        $mailerNames = $this->selectedMailerNames();

        $this->require(
            $violations,
            $mailerNames !== [],
            'MAIL_MAILER must select a configured production mailer.',
        );

        foreach ($mailerNames as $mailerName) {
            $prefix = "mail.mailers.{$mailerName}";
            $transport = strtolower($this->string("{$prefix}.transport"));

            $this->require(
                $violations,
                $transport !== '',
                'MAIL_MAILER must select a configured production mailer.',
            );
            $this->require(
                $violations,
                ! in_array($transport, ['log', 'array'], true),
                'The selected production mailer must not use log or array transport.',
            );

            if ($transport !== 'smtp') {
                continue;
            }

            $mailUrl = $this->string("{$prefix}.url");
            $target = $mailUrl !== ''
                ? $mailUrl
                : $this->string("{$prefix}.host");

            $this->require(
                $violations,
                ! $this->isUnsafeSmtpTarget($target),
                'The selected SMTP mailer must not target Mailpit or a loopback host.',
            );
        }
    }

    /**
     * Require the selected artifact disk to use Amazon S3 configuration.
     *
     * @param  list<string>  $violations
     */
    private function validateArtifactStorage(array &$violations): void
    {
        $disk = $this->string('filesystems.artifact');
        $prefix = "filesystems.disks.{$disk}";

        $this->require(
            $violations,
            $disk !== '' && $this->string("{$prefix}.driver") === 's3',
            'ARTIFACT_FILESYSTEM_DISK must select an S3 disk.',
        );
        $this->require(
            $violations,
            $this->string("{$prefix}.region") !== '',
            'AWS_DEFAULT_REGION is required for artifact storage.',
        );
        $this->require(
            $violations,
            $this->string("{$prefix}.bucket") !== '',
            'AWS_BUCKET is required for artifact storage.',
        );
        $this->require(
            $violations,
            $this->string("{$prefix}.endpoint") === '',
            'AWS_ENDPOINT must be empty when production uses Amazon S3.',
        );
    }

    /**
     * Require non-local Reverb credentials and an HTTPS endpoint.
     *
     * @param  list<string>  $violations
     */
    private function validateReverb(array &$violations): void
    {
        $connection = $this->string('broadcasting.default');
        $prefix = "broadcasting.connections.{$connection}";

        $this->require(
            $violations,
            $connection !== '' && $this->string("{$prefix}.driver") === 'reverb',
            'BROADCAST_CONNECTION must select a Reverb connection.',
        );

        foreach ([
            'app_id' => 'REVERB_APP_ID',
            'key' => 'REVERB_APP_KEY',
            'secret' => 'REVERB_APP_SECRET',
        ] as $configKey => $environmentKey) {
            $credential = $this->string("{$prefix}.{$configKey}");

            $this->require(
                $violations,
                $credential !== '',
                "{$environmentKey} is required.",
            );
            $this->require(
                $violations,
                ! str_starts_with(strtolower($credential), 'local-'),
                "{$environmentKey} must not use local credentials.",
            );
        }

        $host = $this->string("{$prefix}.options.host");

        $this->require(
            $violations,
            $host !== '' && ! $this->isLocalHost($host),
            'REVERB_HOST must use a non-local production host.',
        );
        $this->require(
            $violations,
            strtolower($this->string("{$prefix}.options.scheme")) === 'https',
            'REVERB_SCHEME must be https.',
        );
        $this->require(
            $violations,
            $this->config->get("{$prefix}.options.useTLS") === true,
            'Reverb TLS must be enabled.',
        );
    }

    /**
     * Resolve the driver behind a selected cache store or queue connection.
     */
    private function selectedDriver(string $selectionKey, string $definitionsKey): string
    {
        $selection = $this->string($selectionKey);

        return $selection === ''
            ? ''
            : strtolower($this->string("{$definitionsKey}.{$selection}.driver"));
    }

    /**
     * Resolve the selected mailer and its failover or round-robin children.
     *
     * @return list<string>
     */
    private function selectedMailerNames(): array
    {
        $rootMailer = $this->string('mail.default');

        if ($rootMailer === '') {
            return [];
        }

        /** @var list<string> $pending */
        $pending = [$rootMailer];

        /** @var array<string, true> $seen */
        $seen = [];

        while ($pending !== []) {
            // The loop guarantees that the list is non-empty, and $pending only
            // contains strings, so array_pop() returns a string here.
            $mailerName = array_pop($pending);

            // Prevent cycles and duplicate traversal in nested failover or
            // round-robin mailer configurations.
            if (isset($seen[$mailerName])) {
                continue;
            }

            $seen[$mailerName] = true;

            $children = $this->config->get(
                "mail.mailers.{$mailerName}.mailers",
                [],
            );

            if (! is_array($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (is_string($child) && trim($child) !== '') {
                    $pending[] = trim($child);
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Return a trimmed string configuration value or an empty string.
     */
    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Determine whether the application URL is HTTPS and not local-only.
     */
    private function isHttpsProductionUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return $scheme === 'https'
            && $host !== ''
            && ! $this->isLocalHost($host);
    }

    /**
     * Detect local development hosts that are forbidden in production.
     */
    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        return in_array(
            $host,
            ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'reverb', 'mailpit', 'minio'],
            true,
        )
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    /**
     * Reject missing, Mailpit, and loopback SMTP targets.
     */
    private function isUnsafeSmtpTarget(string $target): bool
    {
        if ($target === '') {
            return true;
        }

        $parsedHost = parse_url($target, PHP_URL_HOST);
        $host = is_string($parsedHost) && $parsedHost !== ''
            ? $parsedHost
            : $target;

        return str_contains(strtolower($host), 'mailpit')
            || $this->isLocalHost($host);
    }

    /**
     * Append a redacted violation instead of stopping at the first failure.
     *
     * @param  list<string>  $violations
     */
    private function require(
        array &$violations,
        bool $condition,
        string $message,
    ): void {
        if (! $condition) {
            $violations[] = $message;
        }
    }
}
