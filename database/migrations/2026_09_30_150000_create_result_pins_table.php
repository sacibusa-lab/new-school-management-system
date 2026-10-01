<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The result-checking PINs a parent buys.
     *
     * One to a student for one term, which is why the three ids are unique together:
     * pressing Generate twice for the same class must not sell a family two cards for
     * the same result. A child who joins after the first batch is simply the gap the
     * second press fills.
     *
     * `used_at` is written by nothing yet — checking a result does not ask for a PIN —
     * and is here so the day it does, the cards already printed can be accounted for
     * without a second migration.
     */
    public function up(): void
    {
        Schema::create('result_pins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();

            $table->string('pin', 16)->unique();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'academic_session_id', 'term_id'], 'result_pin_once_a_term');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_pins');
    }
};
