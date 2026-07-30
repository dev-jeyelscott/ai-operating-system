<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\RateLimitExceededResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register application rate limiters.
     */
    public function boot(): void
    {
        $this->configureAuthenticationLimiters();
        $this->configureProjectCommandLimiter();
    }

    /**
     * Configure authentication abuse controls used by Laravel Fortify.
     */
    private function configureAuthenticationLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $username = Str::lower(
                (string) $request->input(Fortify::username()),
            );

            $key = Str::transliterate(
                $username.'|'.self::clientAddress($request),
            );

            return Limit::perMinute(
                self::positiveConfigInteger(
                    'rate-limits.authentication.login_per_minute',
                ),
            )
                ->by($key)
                ->response(
                    fn (
                        Request $request,
                        array $headers,
                    ) => RateLimitExceededResponse::make(
                        request: $request,
                        headers: $headers,
                    ),
                );
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $loginId = (string) $request->session()->get(
                'login.id',
                $request->session()->getId(),
            );

            return Limit::perMinute(
                self::positiveConfigInteger(
                    'rate-limits.authentication.two_factor_per_minute',
                ),
            )
                ->by($loginId.'|'.self::clientAddress($request))
                ->response(
                    fn (
                        Request $request,
                        array $headers,
                    ) => RateLimitExceededResponse::make(
                        request: $request,
                        headers: $headers,
                    ),
                );
        });

        RateLimiter::for('passkeys', function (Request $request): Limit {
            $credentialId = (string) $request->input(
                'credential.id',
                $request->session()->getId(),
            );

            return Limit::perMinute(
                self::positiveConfigInteger(
                    'rate-limits.authentication.passkeys_per_minute',
                ),
            )
                ->by(
                    $credentialId.'|'.self::clientAddress($request),
                )
                ->response(
                    fn (
                        Request $request,
                        array $headers,
                    ) => RateLimitExceededResponse::make(
                        request: $request,
                        headers: $headers,
                    ),
                );
        });
    }

    /**
     * Configure limits for project commands that change persistent state.
     *
     * Every key includes the authenticated actor, organization route value,
     * and command name. Minute and hourly limits use distinct keys so the
     * counters do not collide.
     */
    private function configureProjectCommandLimiter(): void
    {
        RateLimiter::for(
            'project-commands',
            function (Request $request): array {
                $command = self::projectCommandName($request);
                $actorId = (string) $request->user()?->getAuthIdentifier();
                $organization = self::organizationRouteKey($request);

                $baseKey = implode(':', [
                    'project-command',
                    $actorId !== '' ? $actorId : 'guest',
                    $organization,
                    $command,
                ]);

                $perMinute = self::positiveConfigInteger(
                    "rate-limits.project_commands.{$command}.per_minute",
                );

                $perHour = self::positiveConfigInteger(
                    "rate-limits.project_commands.{$command}.per_hour",
                );

                $response = fn (
                    Request $request,
                    array $headers,
                ) => RateLimitExceededResponse::make(
                    request: $request,
                    headers: $headers,
                );

                return [
                    Limit::perMinute($perMinute)
                        ->by($baseKey.':minute')
                        ->response($response),

                    Limit::perHour($perHour)
                        ->by($baseKey.':hour')
                        ->response($response),
                ];
            },
        );
    }

    /**
     * Resolve the logical project command from the current route name.
     *
     * Project setup submissions update persisted project configuration, so they
     * intentionally share the existing project "update" limiter bucket.
     */
    private static function projectCommandName(Request $request): string
    {
        return match ($request->route()?->getName()) {
            'organizations.projects.store' => 'store',

            'organizations.projects.update',
            'organizations.projects.setup.update',
            'organizations.projects.documents.versions.approve',
            'organizations.projects.documents.versions.reject',
            'organizations.projects.documents.versions.supersede',
            'organizations.projects.quality-assurance.decisions.store' => 'update',
            'organizations.projects.documents.versions.retry' => 'update',

            'organizations.projects.integrations.credentials.store' => 'credentials',

            'organizations.projects.integrations.notion.test' => 'integration_test',

            'organizations.projects.documents.store' => 'upload',

            'organizations.projects.archive' => 'archive',
            'organizations.projects.restore' => 'restore',

            default => 'unknown',
        };
    }

    /**
     * Resolve the organization's route key without trusting request input.
     */
    private static function organizationRouteKey(Request $request): string
    {
        $organization = $request->route('organization');

        if (is_object($organization)
            && method_exists($organization, 'getRouteKey')) {
            return (string) $organization->getRouteKey();
        }

        $routeValue = trim((string) $organization);

        return $routeValue !== '' ? $routeValue : 'unknown';
    }

    /**
     * Resolve the client address used for unauthenticated abuse controls.
     */
    private static function clientAddress(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }

    /**
     * Return a validated positive integer from configuration.
     *
     * Invalid configuration fails safe by allowing one request per window
     * instead of silently disabling the limiter.
     */
    private static function positiveConfigInteger(string $key): int
    {
        return max(1, (int) config($key));
    }
}
