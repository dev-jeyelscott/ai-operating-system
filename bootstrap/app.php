<?php

use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Shared\Exceptions\RetryableOperationException;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrustProxies as ApplicationTrustProxies;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        * Replace Laravel's default proxy middleware with the application
        * implementation. Configuration is resolved during HTTP request handling,
        * after Laravel has loaded the configuration repository.
        */
        $middleware->replace(
            FrameworkTrustProxies::class,
            ApplicationTrustProxies::class,
        );

        // Keep UI preference cookies readable by the frontend.
        $middleware->encryptCookies(except: [
            'appearance',
            'sidebar_state',
        ]);

        // Register middleware required by the web and Inertia application.
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Assign request identifiers before other middleware executes.
        $middleware->prepend(AssignRequestContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Prevent the same exception instance from being reported repeatedly.
        $exceptions->dontReportDuplicates();

        // Attach traceability and deployment information to exception logs.
        $exceptions->context(fn (): array => [
            'request_id' => request()->attributes->get('request_id'),
            'release' => config('app.release'),
        ]);

        // Return the platform's standard API error envelope for API requests.
        $exceptions->render(
            function (
                Throwable $exception,
                Request $request,
            ): mixed {
                /*
                * Preserve responses intentionally produced by middleware or
                * application code. Laravel's named throttle middleware wraps
                * custom rate-limit responses in HttpResponseException.
                */
                if ($exception instanceof HttpResponseException) {
                    return $exception->getResponse();
                }

                $wantsJson = $request->is('api/*')
                    || $request->expectsJson();

                if (! $wantsJson) {
                    return null;
                }

                /*
                * Laravel prepares authorization and model-binding exceptions
                * before registered render callbacks execute.
                *
                * A policy response produced by denyAsNotFound() therefore
                * reaches this callback as HttpExceptionInterface with status
                * 404 rather than as AuthorizationException.
                */
                $httpStatus = $exception instanceof HttpExceptionInterface
                    ? $exception->getStatusCode()
                    : null;

                return match (true) {
                    $exception instanceof ValidationException => ApiErrorResponse::make(
                        request: $request,
                        code: 'validation_failed',
                        message: 'The submitted data is invalid.',
                        status: Response::HTTP_UNPROCESSABLE_ENTITY,
                        details: [
                            'fields' => $exception->errors(),
                        ],
                    ),

                    $exception instanceof AuthenticationException => ApiErrorResponse::make(
                        request: $request,
                        code: 'authentication_required',
                        message: 'Authentication is required.',
                        status: Response::HTTP_UNAUTHORIZED,
                    ),

                    /*
                    * This covers ordinary authorization denials and prepared
                    * AccessDeniedHttpException instances.
                    */
                    $httpStatus === Response::HTTP_FORBIDDEN => ApiErrorResponse::make(
                        request: $request,
                        code: 'authorization_denied',
                        message: 'You are not authorized to perform this action.',
                        status: Response::HTTP_FORBIDDEN,
                    ),

                    /*
                    * Return the same response for:
                    *
                    * - missing route models;
                    * - parent-child scoped-binding failures;
                    * - policy responses using denyAsNotFound();
                    * - explicit not-found HTTP exceptions.
                    */
                    $exception instanceof ModelNotFoundException,
                    $exception instanceof NotFoundHttpException,
                    $httpStatus === Response::HTTP_NOT_FOUND => ApiErrorResponse::make(
                        request: $request,
                        code: 'resource_not_found',
                        message: 'The requested resource was not found.',
                        status: Response::HTTP_NOT_FOUND,
                    ),

                    $exception instanceof ConflictException => ApiErrorResponse::make(
                        request: $request,
                        code: 'state_conflict',
                        message: $exception->getMessage(),
                        status: Response::HTTP_CONFLICT,
                    ),

                    $exception instanceof RetryableOperationException => ApiErrorResponse::make(
                        request: $request,
                        code: 'temporarily_unavailable',
                        message: $exception->getMessage(),
                        status: Response::HTTP_SERVICE_UNAVAILABLE,
                        retryable: true,
                        retryAfterSeconds: $exception->retryAfterSeconds,
                    ),

                    /*
                    * Preserve legitimate HTTP status codes rather than turning
                    * every prepared HTTP exception into a misleading 500.
                    */
                    $exception instanceof HttpExceptionInterface => ApiErrorResponse::make(
                        request: $request,
                        code: 'http_error',
                        message: 'The request could not be completed.',
                        status: $exception->getStatusCode(),
                    ),

                    default => ApiErrorResponse::make(
                        request: $request,
                        code: 'internal_error',
                        message: 'An unexpected error occurred.',
                        status: Response::HTTP_INTERNAL_SERVER_ERROR,
                    ),
                };
            },
        );
    })
    ->create();
