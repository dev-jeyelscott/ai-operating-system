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
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->jsonb('analysis_flags')->nullable()->after('analysis_gaps');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_versions
                ADD CONSTRAINT document_versions_analysis_flags_shape_check
                    CHECK (analysis_flags IS NULL OR jsonb_typeof(analysis_flags) = 'array')
            SQL);
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn('analysis_flags');
        });
    }
};
