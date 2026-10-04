<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The switch that turns text messages on used to sit on the general settings page,
 * with Termii's key on the API page.
 *
 * Two halves of one arrangement, a page apart. The office could read that text
 * messages were switched on without seeing whether there was a key to send them
 * with, and could paste a key in without noticing the switch above it was off.
 * Nothing refused to work in either case, which is what made it worth moving:
 * a school that had switched text messages off and a school that had lost its key
 * looked the same from both pages.
 *
 * Termii's card now holds both, the switch first and the credentials under it.
 * The seeder carries the same change for a fresh database, but it is not enough on
 * its own: it writes metadata over rows that already exist, and only when it is run
 * - this has to reach an installation that is never seeded again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'sms_enabled')->doesntExist()) {
            // Nothing to move. The row has to exist for the switch to be drawn at
            // all, and the column defaults would put it straight back on the general
            // page, so it is created where it now belongs.
            DB::table('settings')->insert([
                'key' => 'sms_enabled',
                'value' => '1',
                'group' => 'api_termii',
                'type' => 'bool',
                'label' => 'Send text messages',
            ]);

            return;
        }

        // Only the group. The value is the office's answer to whether this school
        // sends text messages — moving the switch must not switch it back on.
        DB::table('settings')
            ->where('key', 'sms_enabled')
            ->update(['group' => 'api_termii']);
    }

    /**
     * Back to the group this row came from, which is where an installation that has
     * not run the seeder since would still be looking for it.
     */
    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'sms_enabled')
            ->update(['group' => 'messaging']);
    }
};
