<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HealthController
{
    /**
     * Return public-safe application health metadata.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json([
                'status' => 'ok',
                'release' => config('app.release'),
                'request_id' => $request->attributes->get('request_id'),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
