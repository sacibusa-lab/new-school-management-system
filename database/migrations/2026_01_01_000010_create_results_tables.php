<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A single gradable component: CA1, CA2, Assignment, Exam...
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('ca');           // ca|exam|project
            $table->decimal('max_score', 6, 2)->default(20);
            $table->unsignedTinyInteger('weight')->default(1);
            $table->date('assessed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['term_id', 'school_class_id', 'subject_id'], 'assessments_scope_index');
        });

        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->string('source')->default('manual');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id'], 'assessment_score_unique');
        });

        // The published report card summary for a student / term.
        Schema::create('term_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('total_score', 10, 2)->default(0);
            $table->decimal('average', 6, 2)->default(0);
            $table->unsignedInteger('subjects_count')->default(0);
            $table->unsignedInteger('position')->nullable();
            $table->unsignedInteger('class_size')->nullable();
            $table->string('grade', 5)->nullable();

            $table->string('teacher_remark')->nullable();
            $table->string('principal_remark')->nullable();

            // draft|computed|approved|published
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_session_id', 'term_id'], 'term_result_unique');
        });

        Schema::create('term_result_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();

            $table->decimal('ca_score', 6, 2)->default(0);
            $table->decimal('exam_score', 6, 2)->default(0);
            $table->decimal('total_score', 6, 2)->default(0);
            $table->decimal('subject_average', 6, 2)->nullable();
            $table->decimal('highest_in_class', 6, 2)->nullable();
            $table->decimal('lowest_in_class', 6, 2)->nullable();

            $table->string('grade', 5)->nullable();
            $table->string('remark')->nullable();
            $table->unsignedInteger('subject_position')->nullable();
            $table->boolean('is_absent')->default(false);
            $table->timestamps();

            $table->unique(['term_result_id', 'subject_id'], 'term_result_subject_unique');
        });

        // Controls when a class/term result becomes visible on the public checker.
        Schema::create('result_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_session_id', 'term_id', 'school_class_id'], 'result_publication_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_publications');
        Schema::dropIfExists('term_result_items');
        Schema::dropIfExists('term_results');
        Schema::dropIfExists('assessment_scores');
        Schema::dropIfExists('assessments');
    }
};
