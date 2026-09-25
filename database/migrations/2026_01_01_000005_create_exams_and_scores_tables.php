<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('title');                     // Entrance Examination 2026/2027
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('school_levels')->nullOnDelete();
            $table->date('exam_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->string('venue')->nullable();
            $table->decimal('cutoff_mark', 6, 2)->nullable();
            // draft|scheduled|ongoing|marking|awaiting_review|completed|published
            $table->string('status')->default('draft')->index();
            $table->text('instructions')->nullable();
            $table->boolean('results_locked')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_marks', 6, 2)->default(100);
            $table->decimal('pass_mark', 6, 2)->nullable();
            $table->unsignedTinyInteger('weight')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_compulsory')->default(true);
            $table->timestamps();

            $table->unique(['exam_id', 'subject_id']);
        });

        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->string('grade', 5)->nullable();
            // manual|spreadsheet|ai_vision|ocr|api
            $table->string('source')->default('manual');
            $table->decimal('confidence', 5, 2)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['exam_subject_id', 'applicant_id'], 'score_subject_applicant_unique');
            $table->index(['exam_id', 'applicant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
        Schema::dropIfExists('exam_subjects');
        Schema::dropIfExists('exams');
    }
};
