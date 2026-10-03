<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank account number each student's fees are paid into.
 *
 * Copied from the school's own fees site, minus the institution: this platform is
 * one school, so the row does not need to say which one.
 *
 * `student_id` is unique because the whole point of a dedicated account is that it
 * is the child's and stays the child's — a parent saves it once. If it ever has to
 * be reissued, the old row is deactivated rather than a second one added, so a
 * transfer arriving against the old number is still recognised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();

            // Paystack's own handle for the payer, which is what the webhook carries.
            $table->string('customer_code')->index();

            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->string('account_slug')->nullable();

            $table->string('provider')->default('paystack');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_virtual_accounts');
    }
};
