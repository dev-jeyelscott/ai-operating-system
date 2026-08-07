<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorizes and validates privacy-safe browser renderer telemetry.
 */
final class StoreOfficeRenderingTelemetryRequest extends FormRequest
{
    /**
     * Restrict telemetry to users who can view the tenant-scoped project.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('view', $project) === true;
    }

    /**
     * Return the bounded telemetry contract.
     *
     * Every array uses an explicit key allowlist so project content, ticket
     * identifiers, agent identifiers, browser identity, GPU identity, URLs,
     * and arbitrary client-controlled strings fail validation by default.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'events' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],

            /*
             * Reject every event-level key that is not part of the approved,
             * privacy-safe telemetry schema.
             */
            'events.*' => [
                'required',
                'array:type,sessionId,sequence,observedAt,qualityPreset,reducedMotion,capabilityStatus,capabilityReason,failureReason,frame',
            ],

            'events.*.type' => [
                'required',
                'string',
                Rule::in([
                    'capability_checked',
                    'load_started',
                    'load_succeeded',
                    'load_failed',
                    'quality_changed',
                    'frame_window',
                ]),
            ],

            'events.*.sessionId' => [
                'required',
                'uuid',
            ],

            'events.*.sequence' => [
                'required',
                'integer',
                'min:1',
                'max:1000000',
            ],

            'events.*.observedAt' => [
                'required',
                'date',
            ],

            'events.*.qualityPreset' => [
                'required',
                'string',
                Rule::in([
                    'low',
                    'balanced',
                    'high',
                ]),
            ],

            'events.*.reducedMotion' => [
                'required',
                'boolean',
            ],

            'events.*.capabilityStatus' => [
                'nullable',
                'string',
                Rule::in([
                    'checking',
                    'supported',
                    'limited',
                    'unavailable',
                ]),
            ],

            'events.*.capabilityReason' => [
                'nullable',
                'string',
                Rule::in([
                    'not_checked',
                    'webgl2_available',
                    'major_performance_caveat',
                    'webgl2_unavailable',
                    'capability_check_failed',
                ]),
            ],

            'events.*.failureReason' => [
                'nullable',
                'string',
                Rule::in([
                    'webgl_unavailable',
                    'initialization_failed',
                    'context_lost',
                ]),
            ],

            /*
             * Reject every frame-level key that is not part of the approved
             * aggregate performance measurement schema.
             */
            'events.*.frame' => [
                'nullable',
                'array:averageFps,p95FrameMs,maxFrameMs,sampleDurationMs,frameCount,drawCalls,triangles,geometries,textures,degraded',
            ],

            'events.*.frame.averageFps' => [
                'nullable',
                'numeric',
                'min:0',
                'max:1000',
            ],

            'events.*.frame.p95FrameMs' => [
                'nullable',
                'numeric',
                'min:0',
                'max:60000',
            ],

            'events.*.frame.maxFrameMs' => [
                'nullable',
                'numeric',
                'min:0',
                'max:60000',
            ],

            'events.*.frame.sampleDurationMs' => [
                'nullable',
                'integer',
                'min:1000',
                'max:60000',
            ],

            'events.*.frame.frameCount' => [
                'nullable',
                'integer',
                'min:0',
                'max:60000',
            ],

            'events.*.frame.drawCalls' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000',
            ],

            'events.*.frame.triangles' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000000',
            ],

            'events.*.frame.geometries' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000',
            ],

            'events.*.frame.textures' => [
                'nullable',
                'integer',
                'min:0',
                'max:1000000',
            ],

            'events.*.frame.degraded' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Return the validated and allowlisted telemetry event list.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = $this->validated('events');

        return $events;
    }
}
