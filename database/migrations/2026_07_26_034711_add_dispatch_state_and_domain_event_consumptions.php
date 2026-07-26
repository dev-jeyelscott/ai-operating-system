<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add dispatcher reservation state and durable consumer receipts.
     *
     * This migration is additive. Existing outbox messages remain unpublished
     * and immediately eligible for delivery through the default available_at.
     */
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table
                ->timestampTz('available_at')
                ->useCurrent();

            $table
                ->timestampTz('reserved_until')
                ->nullable();

            $table
                ->uuid('reservation_token')
                ->nullable();

            $table
                ->unsignedInteger('dispatch_attempts')
                ->default(0);

            $table
                ->text('last_error')
                ->nullable();

            $table->index(
                [
                    'published_at',
                    'available_at',
                    'reserved_until',
                    'sequence',
                ],
                'outbox_messages_dispatch_ready_index',
            );
        });

        Schema::create(
            'domain_event_consumptions',
            function (Blueprint $table): void {
                $table->bigIncrements('id');

                $table->string('event_id', 26);
                $table->string('consumer_name', 191);

                $table
                    ->foreignId('organization_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignId('project_id')
                    ->nullable()
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->timestampTz('consumed_at')
                    ->useCurrent();

                $table
                    ->timestampTz('created_at')
                    ->useCurrent();

                /*
                 * This is the authoritative duplicate-prevention constraint.
                 * The same consumer may successfully apply one event only once.
                 */
                $table->unique(
                    ['consumer_name', 'event_id'],
                    'domain_event_consumptions_consumer_event_unique',
                );

                $table->index(
                    [
                        'organization_id',
                        'project_id',
                        'consumed_at',
                    ],
                    'domain_event_consumptions_scope_index',
                );

                $table->index(
                    ['event_id', 'consumed_at'],
                    'domain_event_consumptions_event_index',
                );
            },
        );
    }

    /**
     * Preserve delivery and consumption history during rollback.
     *
     * Removing these columns or receipts could permit already-consumed events
     * to execute again. Reversal therefore requires an explicit later migration
     * with an approved operational data-migration plan.
     */
    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
