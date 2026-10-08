<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A day's collection paid out to the accounts it was divided between.
 *
 * The page works out where a day's money should go; moving it is a set of transfers somebody
 * makes at a bank. This is the office saying those transfers have been made, which is the
 * only way the software can know — nothing here can watch a bank account.
 *
 * Keyed by the day rather than kept on each payment, because a day is the unit the transfers
 * are made in: one Zenith transfer covers every payment collected that day. A payment
 * carries its own settled flag for the different question of whether the gateway has
 * finished with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();

            // The session the money belongs to, which is the session of the bills it paid.
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();

            // The day the money was collected, not the day it was transferred.
            $table->date('collected_on');

            $table->timestamp('disbursed_at');

            // Null on delete: who made the transfers is worth knowing, but the record that
            // they were made must outlive the staff record.
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A day is paid out once. Pressing the button twice must not write a second
            // record and make the day look as though it were transferred twice.
            $table->unique(['academic_session_id', 'collected_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
