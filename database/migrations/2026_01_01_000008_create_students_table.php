<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Created automatically the moment an applicant is admitted.
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // SAC/2026/001 — year-scoped, issued at admission.
            $table->string('student_number')->unique();
            // The original SAC-00001 the applicant registered with.
            $table->string('admission_number')->nullable()->index();

            $table->foreignId('applicant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('photo_path')->nullable();

            $table->foreignId('level_id')->nullable()->constrained('school_levels')->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();

            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();

            // active|suspended|graduated|withdrawn
            $table->string('status')->default('active')->index();

            // Module switches — flipped on by the admission pipeline.
            $table->boolean('results_portal_enabled')->default(true);
            $table->boolean('fees_portal_enabled')->default(true);

            $table->timestamp('admitted_at')->nullable();
            $table->decimal('admission_average', 6, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
