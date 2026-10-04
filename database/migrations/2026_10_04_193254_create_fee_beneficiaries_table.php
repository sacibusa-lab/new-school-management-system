<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a fee is divided between.
 *
 * The bank account is referenced rather than its details copied onto the row, so a
 * renaming or a corrected account number follows through to every fee split to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();

            // Cascades: a bank account the office has removed cannot go on receiving a
            // share of a fee, so the split goes with it and the fee falls back to paying
            // the main account.
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->timestamps();

            // A fee cannot be split into the same account twice — two rows would be two
            // transfers to one account, which is never what was meant.
            $table->unique(['fee_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_beneficiaries');
    }
};
