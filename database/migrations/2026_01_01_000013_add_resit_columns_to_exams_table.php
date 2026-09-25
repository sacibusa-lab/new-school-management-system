<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resit is modelled as its own examination that points back at the paper it
     * repeats. That reuses the whole existing scores/cutoff pipeline instead of
     * inventing a parallel "batch" concept.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('resit_of_exam_id')->nullable()->after('level_id')
                ->constrained('exams')->nullOnDelete();
            $table->boolean('is_resit')->default(false)->after('resit_of_exam_id')->index();
            $table->unsignedTinyInteger('resit_round')->default(1)->after('is_resit');
        });

        // Which subjects a candidate is re-sitting, so only failed papers reappear.
        Schema::table('scores', function (Blueprint $table) {
            $table->boolean('is_resit')->default(false)->after('is_absent');
            $table->unsignedTinyInteger('attempt')->default(1)->after('is_resit');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn(['is_resit', 'attempt']);
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resit_of_exam_id');
            $table->dropColumn(['is_resit', 'resit_round']);
        });
    }
};
