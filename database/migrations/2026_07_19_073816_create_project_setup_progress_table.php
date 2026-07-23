<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSetupStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create one persisted setup-progress record per project.
     */
    public function up(): void
    {
        Schema::create(
            'project_setup_progress',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('project_id')
                    ->constrained()
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->string('current_step', 32)
                    ->default(ProjectSetupStep::Details->value);

                /*
                 * This field intentionally has no database expression default.
                 * Application code always creates it as an empty JSON array,
                 * avoiding database-specific JSON default expressions.
                 */
                $table->jsonb('completed_steps');

                $table->timestampTz('completed_at')->nullable();

                $table->timestampsTz();

                $table->unique(
                    'project_id',
                    'project_setup_progress_project_unique',
                );

                $table->index(
                    ['current_step', 'completed_at'],
                    'project_setup_progress_state_index',
                );
            },
        );
    }

    /**
     * Remove persisted project wizard progress.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_setup_progress');
    }
};
