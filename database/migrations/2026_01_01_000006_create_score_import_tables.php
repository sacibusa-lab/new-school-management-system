<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One upload = one raw scoresheet (photo of a marked sheet, Excel, or CSV).
        Schema::create('score_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // spreadsheet|ai_vision|ocr|manual_grid
            $table->string('driver')->default('spreadsheet');
            // pending|parsing|needs_review|committed|failed
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_matched')->default(0);
            $table->unsignedInteger('rows_unmatched')->default(0);
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        // Each parsed line, held for human review before anything is written to `scores`.
        Schema::create('score_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw')->nullable();

            // Exactly what the machine read, before correction.
            $table->string('raw_identifier')->nullable();   // registration number as read
            $table->string('raw_name')->nullable();         // student name as read
            $table->string('raw_subject')->nullable();      // subject as read (AI may find several per sheet)
            $table->decimal('raw_score', 6, 2)->nullable();

            // What we resolved it to.
            $table->foreignId('exam_subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matched_applicant_id')->nullable()->constrained('applicants')->nullOnDelete();
            $table->decimal('match_confidence', 5, 2)->nullable();

            // pending|matched|ambiguous|unmatched|duplicate|invalid|ignored|committed
            $table->string('status')->default('pending')->index();
            $table->text('message')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['score_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_import_rows');
        Schema::dropIfExists('score_imports');
    }
};
