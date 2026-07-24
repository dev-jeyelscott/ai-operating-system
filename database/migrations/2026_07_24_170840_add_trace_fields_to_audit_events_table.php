<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add durable traceability and duplicate-prevention fields.
     *
     * Existing events remain schema version 1. Nullable trace identifiers keep
     * historical records valid while new document events provide complete
     * correlation and causation data.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->string('causation_id', 128)->nullable();
            $table->string('execution_id', 128)->nullable();

            $table->unsignedSmallInteger('schema_version')
                ->default(1);

            $table->string('deduplication_key', 191)
                ->nullable();

            $table->index(
                'causation_id',
                'audit_events_causation_index',
            );

            $table->index(
                'execution_id',
                'audit_events_execution_index',
            );

            /*
             * Duplicate protection is tenant-scoped. PostgreSQL permits
             * multiple null values, so legacy and intentionally unkeyed events
             * remain valid.
             */
            $table->unique(
                ['organization_id', 'deduplication_key'],
                'audit_events_org_dedup_unique',
            );
        });
    }

    /**
     * This audit-envelope migration is forward-only.
     *
     * Removing these columns would destroy historical traceability. Roll back
     * the application release instead and retain the additive database schema.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Audit trace fields are forward-only and must not be removed.',
        );
    }
};
