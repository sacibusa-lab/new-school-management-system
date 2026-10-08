<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two halves of one idea: a bill line has to say which fee it is, so that the money paid
 * against it can be divided the way that fee says; and a fee has to say what the platform
 * keeps back per transaction before the rest is divided.
 *
 * The fee on a bill line is nullable rather than required. Billing was built from
 * categories before the catalogue was split into fees, and a line that names no fee is a
 * line nothing has been divided for — which is the ordinary case for most schools, not a
 * broken row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structure_items', function (Blueprint $table) {
            // Null on delete: a line priced for a fee that has since been removed still
            // has to be billed, it just stops being divided.
            $table->foreignId('fee_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Copied from the structure line when the invoice is raised, so that a later
            // change to the structure cannot re-divide money already collected.
            $table->foreignId('fee_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('fees', function (Blueprint $table) {
            // Empty means the platform's default, not nought. Stored nullable so a fee
            // that has never been thought about follows the default as it changes.
            $table->decimal('it_maintenance_fee', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn('it_maintenance_fee');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_id');
        });

        Schema::table('fee_structure_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_id');
        });
    }
};
