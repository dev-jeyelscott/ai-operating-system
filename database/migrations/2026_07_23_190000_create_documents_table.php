<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('title', 191);
            $table->timestamps();
            $table->index(['project_id', 'updated_at'], 'documents_project_updated_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD CONSTRAINT documents_title_check
                    CHECK (char_length(btrim(title)) BETWEEN 1 AND 191)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
