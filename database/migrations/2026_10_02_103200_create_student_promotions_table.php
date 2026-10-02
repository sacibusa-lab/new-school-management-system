<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was decided about a student at the end of one session.
 *
 * This is a record of a decision rather than a copy of a student: it names where
 * they were, where they went and who decided, so the year can be read back later
 * even after the student has moved on again. The student row itself only ever
 * holds where they are now, which is why this table exists at all.
 *
 * `from_academic_session_id` is part of the uniqueness: a student is promoted out
 * of a session once, and coming back to the same class's page edits that decision
 * rather than writing a second one beside it.
 *
 * Nothing about their marks, results or bills is touched when they are promoted —
 * those rows carry the session and class they were earned in, so history survives
 * the move intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('to_academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('from_school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('to_school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->string('action', 20);                    // promoted|repeated|graduated|withdrawn
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // One decision per student per session, so the page can be revisited and
            // corrected instead of promoting the same year twice.
            $table->unique(['student_id', 'from_academic_session_id'], 'student_promotion_session_unique');
            // The lookup the promotion page makes: this class, this session.
            $table->index(['from_school_class_id', 'from_academic_session_id'], 'student_promotion_from_class_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotions');
    }
};
