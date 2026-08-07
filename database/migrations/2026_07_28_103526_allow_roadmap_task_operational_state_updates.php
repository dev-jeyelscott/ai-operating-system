<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow operational state changes while preserving generated content
     * immutability for roadmap tasks.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aios_reject_generated_roadmap_content_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                old_generated_content jsonb;
                new_generated_content jsonb;
            BEGIN
                /*
                 * Deleting provider-generated roadmap tasks remains prohibited.
                 */
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION
                        'provider-generated roadmap content is immutable'
                        USING ERRCODE = '55000';
                END IF;

                /*
                 * Remove explicitly mutable operational fields before comparing
                 * the persisted provider-generated roadmap content.
                 *
                 * Any difference remaining after these fields are removed
                 * represents an attempted generated-content mutation.
                 */
                old_generated_content := to_jsonb(OLD) - ARRAY[
                    'status',
                    'desired_state',
                    'reported_state',
                    'observed_state',
                    'actual_state',
                    'status_changed_at',
                    'ready_at',
                    'notion_body_overrides',
                    'updated_at'
                ]::text[];

                new_generated_content := to_jsonb(NEW) - ARRAY[
                    'status',
                    'desired_state',
                    'reported_state',
                    'observed_state',
                    'actual_state',
                    'status_changed_at',
                    'ready_at',
                    'notion_body_overrides',
                    'updated_at'
                ]::text[];

                IF old_generated_content IS DISTINCT FROM new_generated_content THEN
                    RAISE EXCEPTION
                        'provider-generated roadmap content is immutable'
                        USING ERRCODE = '55000';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }

    /**
     * Restore the original strict immutability behavior.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aios_reject_generated_roadmap_content_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'provider-generated roadmap content is immutable'
                    USING ERRCODE = '55000';
            END;
            $$;
        SQL);
    }
};
