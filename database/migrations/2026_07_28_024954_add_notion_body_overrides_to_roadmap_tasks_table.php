<?php

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
        Schema::table('roadmap_tasks', function (Blueprint $table): void {
            $table->jsonb('notion_body_overrides')->nullable();
        });
        Schema::table('notion_reconciliation_conflicts', function (Blueprint $table): void {
            $table->foreignId('resulting_roadmap_id')->nullable()->constrained('roadmaps')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roadmap_tasks', function (Blueprint $table): void {
            $table->dropColumn('notion_body_overrides');
        });
        Schema::table('notion_reconciliation_conflicts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resulting_roadmap_id');
        });
    }
};
