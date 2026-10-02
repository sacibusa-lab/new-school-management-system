<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handed-over passwords are no longer temporary.
 *
 * The column marked an account that had to choose its own password before it could be
 * used for anything else, and a middleware read it to hold that account on the profile
 * screen until it had. Both are gone: a password set for somebody now simply stands
 * until they change it themselves — something they can do from their profile, but are
 * no longer sent to.
 *
 * Dropped rather than left behind. A flag nothing reads is a flag somebody sets again,
 * and whoever finds it next has no way to tell whether the gate was taken out on
 * purpose or lost by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }
};
