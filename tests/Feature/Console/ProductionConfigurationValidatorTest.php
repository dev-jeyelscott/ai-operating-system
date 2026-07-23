<?php

use App\Support\Environment\InvalidProductionConfiguration;
use App\Support\Environment\ProductionConfigurationValidator;
use Illuminate\Config\Repository;

beforeEach(function () {
    config()->set(validProductionConfiguration());
});

test('a valid production configuration passes validation', function () {
    $validator = app(ProductionConfigurationValidator::class);

    expect($validator->violations())->toBe([]);

    $validator->validate();
});

test(
    'unsafe production configuration is rejected with an actionable message',
    function (string $key, mixed $unsafeValue, string $expectedViolation) {
        config()->set($key, $unsafeValue);

        $validator = app(ProductionConfigurationValidator::class);

        expect($validator->violations())->toContain($expectedViolation);

        try {
            $validator->validate();
            $this->fail('Expected production configuration validation to fail.');
        } catch (InvalidProductionConfiguration $exception) {
            expect($exception->violations())
                ->toContain($expectedViolation)
                ->and($exception->getMessage())
                ->toContain($expectedViolation);
        }
    },
)->with('unsafe production configuration');

test(
    'production validation requires a PostgreSQL database connection',
    function (string $key, mixed $unsafeValue): void {
        $configuration = new Repository;

        foreach (validProductionConfiguration() as $configurationKey => $value) {
            $configuration->set($configurationKey, $value);
        }

        $configuration->set($key, $unsafeValue);

        $validator = new ProductionConfigurationValidator($configuration);

        expect($validator->violations())->toContain(
            'DB_CONNECTION must select a PostgreSQL connection.',
        );
    },
)->with([
    'mysql database connection' => [
        'database.connections.pgsql.driver',
        'mysql',
    ],
    'sqlite database connection' => [
        'database.default',
        'sqlite',
    ],
    'missing database connection' => [
        'database.default',
        '',
    ],
]);
test('selected failover mailers cannot fall back to the log transport', function () {
    config()->set('mail.default', 'failover');

    $validator = app(ProductionConfigurationValidator::class);

    expect($validator->violations())->toContain(
        'The selected production mailer must not use log or array transport.',
    );
});

test('validation aggregates violations without exposing configuration values', function () {
    config()->set([
        'app.url' => 'http://admin:app-secret@app.example.com',
        'broadcasting.connections.reverb.secret' => 'local-reverb-secret-value',
    ]);

    $validator = app(ProductionConfigurationValidator::class);

    try {
        $validator->validate();
        $this->fail('Expected production configuration validation to fail.');
    } catch (InvalidProductionConfiguration $exception) {
        expect($exception->violations())
            ->toContain('APP_URL must use HTTPS and a non-local host.')
            ->toContain('REVERB_APP_SECRET must not use local credentials.')
            ->and($exception->getMessage())
            ->not->toContain('app-secret')
            ->not->toContain('local-reverb-secret-value');
    }
});

dataset('unsafe production configuration', [
    'non-production environment' => [
        'app.env',
        'staging',
        'APP_ENV must be production.',
    ],
    'debug mode enabled' => [
        'app.debug',
        true,
        'APP_DEBUG must be false.',
    ],
    'http application URL' => [
        'app.url',
        'http://app.example.com',
        'APP_URL must use HTTPS and a non-local host.',
    ],
    'local application URL' => [
        'app.url',
        'https://localhost',
        'APP_URL must use HTTPS and a non-local host.',
    ],

    'file session driver' => [
        'session.driver',
        'file',
        'SESSION_DRIVER must be database.',
    ],
    'cookie session driver' => [
        'session.driver',
        'cookie',
        'SESSION_DRIVER must be database.',
    ],
    'session encryption disabled' => [
        'session.encrypt',
        false,
        'SESSION_ENCRYPT must be true.',
    ],
    'http only cookie disabled' => [
        'session.http_only',
        false,
        'SESSION_HTTP_ONLY must be true.',
    ],
    'secure cookie disabled' => [
        'session.secure',
        false,
        'SESSION_SECURE_COOKIE must be true.',
    ],
    'unsafe same site policy' => [
        'session.same_site',
        'none',
        'SESSION_SAME_SITE must be lax or strict.',
    ],
    'database cache store' => [
        'cache.default',
        'database',
        'CACHE_STORE must select a Redis-backed cache store.',
    ],
    'array limiter store' => [
        'cache.limiter',
        'array',
        'CACHE_LIMITER_STORE must select a Redis-backed cache store.',
    ],
    'synchronous queue connection' => [
        'queue.default',
        'sync',
        'QUEUE_CONNECTION must select a Redis-backed queue connection.',
    ],
    'log mailer' => [
        'mail.default',
        'log',
        'The selected production mailer must not use log or array transport.',
    ],
    'array mailer' => [
        'mail.default',
        'array',
        'The selected production mailer must not use log or array transport.',
    ],
    'mailpit smtp host' => [
        'mail.mailers.smtp.host',
        'mailpit',
        'The selected SMTP mailer must not target Mailpit or a loopback host.',
    ],
    'local artifact disk' => [
        'filesystems.artifact',
        'local',
        'ARTIFACT_FILESYSTEM_DISK must select an S3 disk.',
    ],
    'minio endpoint' => [
        'filesystems.disks.s3.endpoint',
        'http://minio:9000',
        'AWS_ENDPOINT must be empty when production uses Amazon S3.',
    ],
    'missing s3 region' => [
        'filesystems.disks.s3.region',
        '',
        'AWS_DEFAULT_REGION is required for artifact storage.',
    ],
    'missing s3 bucket' => [
        'filesystems.disks.s3.bucket',
        '',
        'AWS_BUCKET is required for artifact storage.',
    ],
    'missing reverb app id' => [
        'broadcasting.connections.reverb.app_id',
        '',
        'REVERB_APP_ID is required.',
    ],
    'local reverb key' => [
        'broadcasting.connections.reverb.key',
        'local-key',
        'REVERB_APP_KEY must not use local credentials.',
    ],
    'local reverb secret' => [
        'broadcasting.connections.reverb.secret',
        'local-secret',
        'REVERB_APP_SECRET must not use local credentials.',
    ],
    'local reverb host' => [
        'broadcasting.connections.reverb.options.host',
        'localhost',
        'REVERB_HOST must use a non-local production host.',
    ],
    'insecure reverb scheme' => [
        'broadcasting.connections.reverb.options.scheme',
        'http',
        'REVERB_SCHEME must be https.',
    ],
    'reverb tls disabled' => [
        'broadcasting.connections.reverb.options.useTLS',
        false,
        'Reverb TLS must be enabled.',
    ],
]);

/**
 * Return a complete, external-service-free production configuration baseline.
 *
 * @return array<string, mixed>
 */
function validProductionConfiguration(): array
{
    return [
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://app.example.com',
        'database.default' => 'pgsql',
        'database.connections.pgsql.driver' => 'pgsql',
        'database.connections.mysql.driver' => 'mysql',
        'database.connections.sqlite.driver' => 'sqlite',

        'session.driver' => 'database',
        'session.encrypt' => true,
        'session.http_only' => true,
        'session.secure' => true,
        'session.same_site' => 'lax',

        'cache.default' => 'redis',
        'cache.limiter' => 'redis',
        'cache.stores.redis.driver' => 'redis',
        'cache.stores.database.driver' => 'database',
        'cache.stores.array.driver' => 'array',

        'queue.default' => 'redis',
        'queue.connections.redis.driver' => 'redis',
        'queue.connections.sync.driver' => 'sync',

        'mail.default' => 'smtp',
        'mail.mailers.smtp.transport' => 'smtp',
        'mail.mailers.smtp.url' => null,
        'mail.mailers.smtp.host' => 'smtp.example.com',
        'mail.mailers.log.transport' => 'log',
        'mail.mailers.array.transport' => 'array',
        'mail.mailers.failover.transport' => 'failover',
        'mail.mailers.failover.mailers' => ['smtp', 'log'],

        'filesystems.artifact' => 's3',
        'filesystems.disks.s3.driver' => 's3',
        'filesystems.disks.s3.region' => 'ap-southeast-1',
        'filesystems.disks.s3.bucket' => 'aios-production',
        'filesystems.disks.s3.endpoint' => null,
        'filesystems.disks.local.driver' => 'local',

        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.driver' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'production-app',
        'broadcasting.connections.reverb.key' => 'production-key',
        'broadcasting.connections.reverb.secret' => 'production-secret',
        'broadcasting.connections.reverb.options.host' => 'reverb.example.com',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'broadcasting.connections.reverb.options.useTLS' => true,
    ];
}
