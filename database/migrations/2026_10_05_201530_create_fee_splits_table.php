<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fee_splits', function (Blueprint $table) {
            $table->id();

            // Which fee is being carved up. The fee that owns the money owns the instruction
            // about where it goes, so the share goes when the fee goes.
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();

            // The account that receives this share. Nullable rather than cascading: a share
            // left behind by an account that was removed is something somebody has to look at,
            // and a row saying so is better than a row that silently vanished.
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();

            // A fixed number of naira out of every payment of the fee, not a percentage — that
            // is how the school sets it up. On their ₦62,000 first-term fee it is ₦32,000 to
            // one account and ₦30,000 to the other, and the two have to add up to the fee or
            // the money does not balance.
            $table->decimal('amount', 12, 2)->default(0);

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['fee_id', 'bank_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_splits');
    }
};
