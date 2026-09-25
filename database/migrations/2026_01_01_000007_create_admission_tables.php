<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin/exam-officer controlled pass mark, per session + level.
        Schema::create('admission_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('school_levels')->cascadeOnDelete();
            $table->decimal('cutoff_mark', 6, 2)->default(50);
            $table->decimal('subject_pass_mark', 6, 2)->default(40);
            $table->unsignedInteger('available_slots')->nullable();
            $table->boolean('require_all_subjects')->default(false);
            $table->boolean('auto_admit')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_session_id', 'level_id'], 'admission_settings_session_level_unique');
        });

        // One row per applicant per exam — the computed merit + the decision.
        Schema::create('admission_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('average_score', 6, 2)->default(0);
            $table->decimal('highest_score', 6, 2)->default(0);
            $table->unsignedInteger('subjects_offered')->default(0);
            $table->unsignedInteger('subjects_passed')->default(0);
            $table->unsignedInteger('subjects_failed')->default(0);
            $table->boolean('has_absent')->default(false);
            $table->decimal('cutoff_mark', 6, 2)->default(0);
            $table->unsignedInteger('position')->nullable();
            $table->unsignedInteger('position_in_level')->nullable();

            // pending|admitted|rejected|withdrawn|deferred
            $table->string('decision')->default('pending')->index();
            $table->boolean('is_auto')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'exam_id'], 'admission_decision_applicant_exam_unique');
        });

        // Audit of every notification the pipeline emits.
        Schema::create('pipeline_events', function (Blueprint $table) {
            $table->id();
            $table->string('event');                       // applicant.registered, applicant.admitted...
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_events');
        Schema::dropIfExists('admission_decisions');
        Schema::dropIfExists('admission_settings');
    }
};
