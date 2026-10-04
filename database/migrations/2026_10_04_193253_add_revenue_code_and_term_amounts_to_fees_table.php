<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a fee costs in a named term, and the code the school's own books know it by.
 *
 * The amount on the fee stays as the default. A term amount is an exception, and empty
 * means "the default" rather than zero — a fee with no term amounts set is not a free fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->string('revenue_code', 40)->nullable()->after('description');

            $table->decimal('first_term_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('second_term_amount', 12, 2)->nullable()->after('first_term_amount');
            $table->decimal('third_term_amount', 12, 2)->nullable()->after('second_term_amount');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn([
                'revenue_code',
                'first_term_amount',
                'second_term_amount',
                'third_term_amount',
            ]);
        });
    }
};
