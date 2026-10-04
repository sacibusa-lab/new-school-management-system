<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The free-text label — "Fees account", "PTA account" — is gone.
 *
 * It was asked for on the way in and read back in the table, and nowhere else: the fee
 * splits and the students hub read `bank_name`, and `BankAccount::label()` is computed
 * from the bank and the number rather than from this column. So it was a caption for
 * one screen, and the office had to think of one before it would let them save an
 * account whose number the bank had already spelled out.
 *
 * The column is NOT NULL, so the field cannot simply be taken off the page: a school
 * adding an account would get a database error instead of an account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }

    /**
     * Nullable, where it was NOT NULL: the values are not recoverable, and a school
     * that rolls back is adding accounts rather than restoring the captions they had.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('label')->nullable();
        });
    }
};
