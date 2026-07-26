<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable idempotency records without modifying existing data.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('scope', 255);
            $table->char('key_hash', 64);
            $table->string('command_class', 512);
            $table->char('request_fingerprint', 64);

            $table->string('status', 32);
            $table->string('result_status', 64)->nullable();

            /*
             * Laravel's encrypted array cast requires a TEXT-compatible column.
             * PostgreSQL TEXT is not restricted to a small fixed length.
             */
            $table->text('result_payload')->nullable();

            $table->uuid('lock_owner')->nullable();
            $table->timestampTz('lock_expires_at')->nullable();

            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            /*
             * This constraint is the durable correctness boundary.
             * Redis reduces contention, but it is not the source of truth.
             */
            $table->unique(
                ['scope', 'key_hash'],
                'idempotency_keys_scope_key_unique',
            );

            $table->index(
                ['status', 'lock_expires_at'],
                'idempotency_keys_processing_index',
            );

            $table->index(
                ['status', 'expires_at'],
                'idempotency_keys_expiry_index',
            );
        });
    }

    /**
     * Remove the table outside production environments only.
     *
     * Dropping idempotency history in production could allow previously
     * completed commands to execute again.
     */
    public function down(): void
    {
        if (app()->environment('production') && Schema::hasTable('idempotency_keys')) {
            throw new RuntimeException(
                'Refusing to drop idempotency_keys in production because doing so may permit duplicate command execution.',
            );
        }

        Schema::dropIfExists('idempotency_keys');
    }
};
