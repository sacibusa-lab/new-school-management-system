<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();

            // SAC-00001 — issued at registration, ascending, permanent.
            $table->string('registration_number')->unique();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');

            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->default('Nigerian')->nullable();

            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();

            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('lga')->nullable();

            $table->string('previous_school')->nullable();

            $table->foreignId('level_applied_for_id')->nullable()
                ->constrained('school_levels')->nullOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('guardian_address')->nullable();

            $table->string('photo_path')->nullable();
            $table->json('documents')->nullable();

            // registered|exam_scheduled|exam_completed|shortlisted|admitted|rejected|withdrawn
            $table->string('status')->default('registered')->index();

            $table->text('admin_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('admitted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['first_name', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
