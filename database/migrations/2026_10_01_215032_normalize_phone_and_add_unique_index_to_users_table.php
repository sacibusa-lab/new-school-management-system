<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The phone number is the sign-in detail now, so it has to be one shape
        // before it can be one number. Fold what is already on the records first —
        // `0803 123 4567` and `+2348031234567` are the same line, and a unique index
        // on the raw column would have let both of them exist.
        DB::table('users')
            ->select('id', 'phone')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $normalized = PhoneNumber::normalize($user->phone);

                    if ($normalized !== $user->phone) {
                        DB::table('users')->where('id', $user->id)->update(['phone' => $normalized]);
                    }
                }
            });

        Schema::table('users', function (Blueprint $table) {
            // No longer the only way in, so it can be absent. The unique index stays.
            $table->string('email', 150)->nullable()->change();

            // One number, one account. Empty numbers are not a clash: MySQL,
            // Postgres and SQLite all allow any number of NULLs in a unique index.
            $table->unique('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->string('email', 150)->nullable(false)->change();
        });
    }
};
