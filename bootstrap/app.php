<?php

use App\Application\Shared\Exceptions\ConflictException;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Application\Shared\Exceptions\RetryableOperationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->prepend(AssignRequestContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();

        $exceptions->context(fn (): array => [
            'request_id' => request()?->attributes->get('request_id'),
            'release' => config('app.release'),
        ]);

        $exceptions->render(
            function (
                Throwable $exception,
                Request $request,
            ): mixed {
                $wantsJson = $request->is('api/*')
                    || $request->expectsJson();

                if (! $wantsJson) {
                    return null;
                }

                return match (true) {
                    $exception instanceof ValidationException =>
                        ApiErrorResponse::make(
                            request: $request,
                            code: 'validation_failed',
                            message: 'The submitted data is invalid.',
                            status: Response::HTTP_UNPROCESSABLE_ENTITY,
                            details: [
                                'fields' => $exception->errors(),
                            ],
                        ),

                    $exception instanceof AuthenticationException =>
                        ApiErrorResponse::make(
                            request: $request,
                            code: 'authentication_required',
                            message: 'Authentication is required.',
                            status: Response::HTTP_UNAUTHORIZED,
                        ),

                    $exception instanceof AuthorizationException =>
                        ApiErrorResponse::make(
                            request: $request,
                            code: 'authorization_denied',
                            message: 'You are not authorized to perform this action.',
                            status: Response::HTTP_FORBIDDEN,
                        ),

                    $exception instanceof ModelNotFoundException,
                    $exception instanceof NotFoundHttpException =>
                        ApiErrorResponse::make(
                            request: $request,
                            code: 'resource_not_found',
                            message: 'The requested resource was not found.',
                            status: Response::HTTP_NOT_FOUND,
                        ),

                    $exception instanceof ConflictException =>
                        ApiErrorResponse::make(
                            request: $request,
                            code: 'state_conflict',
                            message: $exception->getMessage(),
                            status: Response::HTTP_CONFLICT,
                        ),

                    $exception instanceof RetryableOperationException =>
                        ApiErrorResponse::make(
                            request: $request,
                            code: 'temporarily_unavailable',
                            message: $exception->getMessage(),
                            status: Response::HTTP_SERVICE_UNAVAILABLE,
                            retryable: true,
                            retryAfterSeconds:
                                $exception->retryAfterSeconds,
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
    })->create();
