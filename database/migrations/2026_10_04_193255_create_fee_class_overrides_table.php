<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a year group is charged instead of the fee's default amount.
 *
 * Per year group and not per class arm: JSS1 is charged more for tuition and every arm of
 * JSS1 is charged it, which is one decision rather than one per JSS1A, JSS1B and so on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_class_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('school_levels')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            // An override can be switched off without being deleted, so a price the school
            // is not charging yet can be set up in advance.
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // One amount per year group per fee. Two would be a contradiction the database
            // refuses rather than one the page silently picks between.
            $table->unique(['fee_id', 'level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_class_overrides');
    }
};
