<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notion_reconciliation_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_ticket_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('state', 40)->default('open');
            $table->string('current_fingerprint', 64);
            $table->string('published_fingerprint', 64)->nullable();
            $table->string('external_fingerprint', 64)->nullable();
            $table->string('decision', 40)->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'project_id', 'state']);
            $table->index(['external_ticket_mapping_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notion_reconciliation_conflicts');
    }
};
