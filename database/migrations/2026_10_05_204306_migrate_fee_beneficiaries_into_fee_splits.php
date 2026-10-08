<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The splits tab used to write to `fee_beneficiaries`; it now writes to `fee_splits`,
 * which lets a share outlive the account it was pointed at and remembers the order the
 * office set the shares out in. The old rows are carried over rather than left behind,
 * so a fee divided before the change stays divided after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $positions = [];

        DB::table('fee_beneficiaries')
            ->orderBy('fee_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $beneficiary) use (&$positions): void {
                $position = $positions[$beneficiary->fee_id] ?? 0;
                $positions[$beneficiary->fee_id] = $position + 1;

                DB::table('fee_splits')->insert([
                    'fee_id' => $beneficiary->fee_id,
                    'bank_account_id' => $beneficiary->bank_account_id,
                    'amount' => $beneficiary->amount,
                    'position' => $position,
                    'created_at' => $beneficiary->created_at,
                    'updated_at' => $beneficiary->updated_at,
                ]);
            });

        Schema::dropIfExists('fee_beneficiaries');
    }

    public function down(): void
    {
        Schema::create('fee_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['fee_id', 'bank_account_id']);
        });

        DB::table('fee_splits')
            ->whereNotNull('bank_account_id')
            ->orderBy('fee_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->each(function (object $split): void {
                DB::table('fee_beneficiaries')->insert([
                    'fee_id' => $split->fee_id,
                    'bank_account_id' => $split->bank_account_id,
                    'amount' => $split->amount,
                    'created_at' => $split->created_at,
                    'updated_at' => $split->updated_at,
                ]);
            });
    }
};
