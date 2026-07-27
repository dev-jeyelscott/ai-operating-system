<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\Data\PlanningDiagnostic;
use App\Application\Security\RedactSensitiveData;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\PlanningExecutionDiagnostic;
use Illuminate\Support\Str;

final readonly class PersistPlanningDiagnostics
{
    public function __construct(private RedactSensitiveData $redactor) {}

    /**
     * @param  list<PlanningDiagnostic>  $diagnostics
     * @return list<PlanningExecutionDiagnostic>
     */
    public function handle(Execution $execution, ExecutionAttempt $attempt, array $diagnostics): array
    {
        $persisted = [];
        foreach ($diagnostics as $diagnostic) {
            $message = Str::limit($this->redactor->message($diagnostic->message), 2000, '');
            $details = $this->sanitizeDetails($diagnostic->details);
            $fingerprint = RoadmapCommandFingerprint::make([
                'execution_id' => $execution->id,
                'code' => $diagnostic->code,
                'category' => $diagnostic->category,
                'message' => $message,
                'details' => $details,
            ]);
            $persisted[] = PlanningExecutionDiagnostic::query()->firstOrCreate(
                ['execution_id' => $execution->id, 'fingerprint' => $fingerprint],
                [
                    'execution_attempt_id' => $attempt->id,
                    'code' => $diagnostic->code,
                    'category' => $diagnostic->category,
                    'message' => $message,
                    'details' => $details,
                ],
            );
        }

        return $persisted;
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $details
     * @return array<string, scalar|list<scalar>|null>
     */
    private function sanitizeDetails(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_string($value)) {
                $details[$key] = Str::limit($this->redactor->message($value), 2000, '');
            } elseif (is_array($value)) {
                $details[$key] = array_map(
                    fn (mixed $item): mixed => is_string($item)
                        ? Str::limit($this->redactor->message($item), 2000, '')
                        : $item,
                    $value,
                );
            }
        }

        return $details;
    }
}
