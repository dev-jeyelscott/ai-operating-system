<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add fail-closed Codex policy defaults while preserving every existing key.
     */
    public function up(): void
    {
        $codexDefaults = [
            'enabled' => false,
            'model_identifier' => 'gpt-5.3-codex',
            'allowed_capabilities' => [
                'planning.generate',
                'development.execute',
                'quality_assurance.review',
            ],
            'reasoning' => [
                'minimum' => 'medium',
                'maximum' => 'high',
            ],
            'sandbox' => [
                'planning' => 'read-only',
                'development' => 'workspace-write',
                'quality_assurance' => 'read-only',
            ],
            'network' => [
                'default' => 'deny',
                'allow_escalation_with_approval' => true,
            ],
            'budget_limit_minor' => null,
            'timeout_seconds' => 900,
            'retry_limit' => 2,
        ];

        DB::table('project_configurations')
            ->whereIn('schema_version', [1, 2])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($codexDefaults): void {
                foreach ($rows as $row) {
                    $policy = $this->decodePolicy($row->provider_policy);
                    $policy['codex'] ??= $codexDefaults;

                    DB::table('project_configurations')
                        ->where('id', $row->id)
                        ->update([
                            'provider_policy' => json_encode(
                                $policy,
                                JSON_THROW_ON_ERROR,
                            ),
                            'schema_version' => 3,
                        ]);
                }
            });
    }

    /**
     * Refuse to erase or downgrade a persisted security policy.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Project configuration schema v3 contains security policy data and is intentionally forward-only.',
        );
    }

    /**
     * Decode PostgreSQL JSON/JSONB values without silently replacing bad data.
     *
     * @return array<string, mixed>
     */
    private function decodePolicy(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                'A project provider policy could not be decoded during the v3 migration.',
            );
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'A project provider policy contains invalid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'A project provider policy must decode to an object.',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
};
