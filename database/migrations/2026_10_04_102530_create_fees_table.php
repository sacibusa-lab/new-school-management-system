<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue of fees the school charges.
 *
 * A fee is a thing that can be billed, not a bill: what it is called, how often it comes
 * round, what it costs by default, and which terms it applies to. The bill a parent
 * receives is raised from these, which is why the amount here is a default rather than a
 * price — a year group can be billed something else without this row having to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fees', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();

            // termly, annually, one-time. A string rather than an enum column, so a
            // fourth cycle later is a code change rather than a migration on a table
            // the whole fee section reads.
            $table->string('cycle', 20)->default('termly');

            // Nullable on purpose: a levy that applies to every session is entered once
            // and left, rather than copied into each session as the years go by.
            $table->foreignId('academic_session_id')
                ->nullable()
                ->constrained('academic_sessions')
                ->nullOnDelete();

            $table->decimal('amount', 12, 2)->default(0);

            // Which terms the fee comes round in. Only meaningful for a termly fee, but
            // stored for all of them: switching a fee from termly to annually and back
            // should not quietly lose the answer someone gave.
            $table->boolean('first_term_active')->default(true);
            $table->boolean('second_term_active')->default(true);
            $table->boolean('third_term_active')->default(true);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['academic_session_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fees');
    }
};
