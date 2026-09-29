<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings are the school's own words and numbers, and the seeder is run against
 * live databases — `migrate --seed` on a fresh clone, as the README says.
 *
 * So the seeder must be safe to run twice. It used to write every value it knew
 * over whatever was there, which means a school that had put in its own name,
 * phone number and letter wording would have them quietly reset to the defaults.
 * Nobody would find out until a parent noticed the wrong address on a letter.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_re_running_the_settings_seeder_leaves_edited_values_alone(): void
    {
        $this->seed(SettingsSeeder::class);

        Setting::put('school_name', 'Saci Grammar School');
        Setting::put('contact_phone', '0803 000 1111');
        Setting::put('school_tagline', 'Work and Service');

        $this->seed(SettingsSeeder::class);

        $this->assertSame('Saci Grammar School', Setting::get('school_name'));
        $this->assertSame('0803 000 1111', Setting::get('contact_phone'));
        $this->assertSame('Work and Service', Setting::get('school_tagline'));
        $this->assertSame(
            'Saci Grammar School',
            Setting::query()->where('key', 'school_name')->sole()->value,
        );
    }

    public function test_the_seeder_still_creates_settings_that_are_missing(): void
    {
        // The branding migration already inserts its own two rows; everything else
        // comes from the seeder, which is what a fresh clone relies on.
        $this->assertSame(0, Setting::query()->where('key', 'school_tagline')->count());

        $this->seed(SettingsSeeder::class);

        $this->assertSame('Knowledge, Character, Service', Setting::get('school_tagline'));
        $this->assertGreaterThan(20, Setting::query()->count());
    }

    public function test_the_seeder_still_brings_a_renamed_label_and_type_up_to_date(): void
    {
        $this->seed(SettingsSeeder::class);

        Setting::query()->where('key', 'school_logo')
            ->update(['label' => 'An old label', 'type' => 'string']);

        $this->seed(SettingsSeeder::class);

        $logo = Setting::query()->where('key', 'school_logo')->sole();

        $this->assertSame('School logo', $logo->label);
        $this->assertSame('image', $logo->type);
    }

    /** An uploaded image lives in the value, and a re-seed must not blank it. */
    public function test_re_running_the_seeder_does_not_blank_an_uploaded_image(): void
    {
        $this->seed(SettingsSeeder::class);

        Setting::put('school_logo', 'branding/crest.png');
        Setting::put('school_favicon', 'branding/favicon.png');

        $this->seed(SettingsSeeder::class);

        $this->assertSame('branding/crest.png', Setting::query()->where('key', 'school_logo')->sole()->value);
        $this->assertSame('branding/favicon.png', Setting::query()->where('key', 'school_favicon')->sole()->value);
    }
}
