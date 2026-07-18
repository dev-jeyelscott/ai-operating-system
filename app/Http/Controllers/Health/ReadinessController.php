<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Support\Health\HealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReadinessController
{
    public function __construct(
        private readonly HealthService $health,
    ) {
    }

    /**
     * Return dependency readiness with HTTP 503 when required services are
     * unavailable.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $report = $this->health->readiness();

        $report['request_id'] = $request->attributes->get('request_id');

        return response()
            ->json(
                $report,
                $report['status'] === 'ok'
                    ? Response::HTTP_OK
                    : Response::HTTP_SERVICE_UNAVAILABLE,
            )
            ->header('Cache-Control', 'no-store');
    }
}
