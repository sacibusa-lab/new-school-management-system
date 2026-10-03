<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own bank accounts, taken from the fees site.
 *
 * These are the accounts the school *receives* fees into — the ones printed on a
 * letter and read out to a parent — as against the dedicated account each child is
 * given. `sub_account_code` is Paystack's handle for an account that fee money is
 * split to, and is empty until the school sets that up.
 *
 * The account number is unique because two rows for one account is how a school ends
 * up printing last year's account on this year's letter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('bank_name');
            $table->string('bank_code')->nullable();
            $table->string('account_number')->unique();
            $table->string('account_name');
            $table->string('sub_account_code')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
