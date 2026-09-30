<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The school's letterhead, as an uploaded image.
     *
     * Added here as well as in SettingsSeeder, which is not enough on its own: that
     * seeder uses updateOrCreate, so re-running it against a live database would
     * reset every value the office has edited. This row is inserted only when it is
     * missing, leaving everything else alone.
     */
    private const SETTING = [
        'key' => 'letterhead_image',
        'group' => 'branding',
        'type' => 'image',
        'label' => 'Letterhead',
    ];

    public function up(): void
    {
        DB::table('settings')->insertOrIgnore(self::SETTING + [
            'value' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::SETTING['key'])->delete();
    }
};
