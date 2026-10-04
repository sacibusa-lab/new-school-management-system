<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money added to or taken off one child's bill, with a reason.
 *
 * Not a scholarship: a bursary is a decision with paperwork behind it that belongs to the
 * child and lasts as long as it lasts. This is the office correcting one term — a family
 * that paid for the bus up front, a sibling discount agreed at the counter, a charge for
 * something the school paid out for. Attaching it to a session and a term is what keeps it
 * off next year's slip.
 *
 * **Signed.** A negative amount is money taken off, a positive is money added, and one
 * column rather than two is what makes "what did we change on this child's bill" a single
 * read with a single sum. A discount is not a charge of minus-something; it is the same
 * thing going the other way.
 *
 * Nothing here is authoritative about the total: the notice adds the adjustments up and
 * writes the answer on the slip, exactly as it does the fees. An adjustment is never a
 * second opinion about what the fee costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_adjustments', function (Blueprint $table) {
            $table->id();

            // If the child goes, their adjustments go with them. A row pointing at nobody
            // is money nobody can explain.
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // Nullable so an adjustment can be made before the year is set up, and so
            // deleting a session does not delete the reason the money was moved.
            $table->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('description');

            // Who decided it. Null once the user is gone — the adjustment stays, because
            // money that was moved does not become unmoved.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The slip asks for one child's adjustments in one term, which is this index.
            $table->index(['student_id', 'academic_session_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_adjustments');
    }
};
