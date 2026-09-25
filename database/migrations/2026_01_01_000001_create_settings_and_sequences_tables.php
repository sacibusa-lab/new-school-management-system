<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general')->index();
            $table->string('type')->default('string'); // string|int|bool|json|text
            $table->string('label')->nullable();
            $table->timestamps();
        });

        // Atomic counters behind SAC-00001 (admission) and SAC/2026/001 (student) numbers.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type');                       // admission_registration|student_number
            $table->string('scope')->default('global');   // global | 2026
            $table->string('prefix')->default('');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('settings');
    }
};
