<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a student is let off, and by whom.
 *
 * Taken from the fees site, where an award belongs to the student rather than to a
 * fee: it says who is being helped, for which session and term, how much, and who
 * approved it. `invoices.discount` is where an approved award lands — the column has
 * been sitting there unused since the fee tables were written.
 *
 * Pending is the default because an award is a decision: it changes what a family
 * owes, and somebody's name belongs against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            // Empty means every term of the session.
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('scholarship');  // scholarship|bursary|staff_ward
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->string('status')->default('pending')->index();  // pending|approved|rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'academic_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarships');
    }
};
