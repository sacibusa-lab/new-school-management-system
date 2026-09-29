<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two branding settings that hold an uploaded image rather than typed text.
     *
     * Added here as well as in SettingsSeeder, which is not enough on its own:
     * that seeder uses updateOrCreate, so re-running it against a live database
     * would reset every value the office has edited. These rows are inserted only
     * when they are missing, leaving everything else alone.
     */
    private const SETTINGS = [
        ['key' => 'school_logo', 'group' => 'branding', 'type' => 'image', 'label' => 'School logo'],
        ['key' => 'school_favicon', 'group' => 'branding', 'type' => 'image', 'label' => 'Browser favicon'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as $setting) {
            DB::table('settings')->insertOrIgnore($setting + [
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', array_column(self::SETTINGS, 'key'))
            ->delete();
    }
};
