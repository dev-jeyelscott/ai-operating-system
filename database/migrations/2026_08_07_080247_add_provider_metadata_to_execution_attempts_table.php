<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add nullable provider metadata without rewriting historical attempts.
     */
    public function up(): void
    {
        Schema::table(
            'execution_attempts',
            function (Blueprint $table): void {
                $table
                    ->string('provider_protocol_version', 100)
                    ->nullable();

                $table
                    ->string('provider_sandbox_profile', 100)
                    ->nullable();

                $table
                    ->string('effective_capability', 100)
                    ->nullable();

                $table
                    ->string('provider_selection_source', 100)
                    ->nullable();

                $table
                    ->string('simulation_scenario', 100)
                    ->nullable();
            },
        );
    }

    /**
     * Preserve immutable attempt history during application rollback.
     *
     * Production rollback reverts application code and leaves these nullable
     * metadata columns in place.
     */
    public function down(): void
    {
        // Intentionally irreversible to preserve execution provenance.
    }
};
