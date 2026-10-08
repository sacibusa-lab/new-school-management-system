<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a payment was settled, and by whom.
 *
 * The settlement page works out what a day's collection divides into, but moving the money
 * is a thing somebody does at a bank, and nothing in the software can see it happen. So the
 * record is a statement of fact by the office rather than a figure the system derived: a
 * payment is settled when a person says it is.
 *
 * On the payment rather than on the day, because a day's collection is a group of
 * individual transfers and one of them can be made while another is held over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('paid_at');

            // Null on delete: who settled it is worth knowing, but losing the staff record
            // must not unsettle the money.
            $table->foreignId('settled_by')->nullable()->after('settled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn('settled_at');
        });
    }
};
