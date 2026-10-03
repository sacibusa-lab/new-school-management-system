<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SettingLayout;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * How the settings page is arranged.
 *
 * The page used to be thrown at the screen in the order the database returned —
 * `orderBy('group')->orderBy('key')`, which is alphabetical twice over. So Finance
 * sat between Branding and Letters, and inside the school's own branding the first
 * field was **Address**, because an address sorts before a name. The order the
 * controller declared was only ever used for the heading text.
 *
 * A setting that vanished from the page would be far worse than one shown in the
 * wrong place, so the last two tests here are about not losing anything.
 */
class SettingsLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    /** @param array<int,string> $keys of a single group */
    private function group(string $group, array $keys): Collection
    {
        return collect($keys)->map(fn (string $key) => new Setting([
            'key' => $key,
            'group' => $group,
            'type' => 'string',
            'label' => $key,
        ]));
    }

    /* ------------------------------------------------------------------ */
    /* The order of the groups */
    /* ------------------------------------------------------------------ */

    public function test_the_groups_are_drawn_in_the_order_they_are_declared(): void
    {
        $settings = Setting::query()->orderBy('key')->get()->groupBy('group');

        $drawn = array_column(SettingLayout::arrange($settings), 'key');

        $this->assertSame(
            ['branding', 'numbering', 'admissions', 'letters', 'messaging', 'fees', 'results', 'api_paystack', 'api_termii', 'api_deepseek'],
            $drawn,
            'The groups are out of order. A new group has to be placed in SettingLayout::GROUPS.',
        );
    }

    public function test_the_page_starts_with_the_school_itself_and_not_with_admissions(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.settings.index'))->assertOk()->getContent();

        preg_match_all('/<h2 class="text-base font-semibold[^"]*">\s*([^<]+?)\s*<\/h2>/s', $html, $matches);

        $headings = array_values(array_filter(array_map('trim', $matches[1])));

        // The academic-session card sits above the form and is an h2 as well.
        $this->assertSame('Academic session', $headings[0] ?? null);
        $this->assertSame('School branding', $headings[1] ?? null);

        // Admissions is set up on its own page now, not scrolled past on the way
        // to the school's logo.
        $this->assertNotContains('Admissions', $headings);
        $this->assertNotContains('Admission letters', $headings);
    }

    /* ------------------------------------------------------------------ */
    /* Which page a group is set up on */
    /* ------------------------------------------------------------------ */

    /**
     * The admissions journey and the letter it sends out are one sitting's work, so
     * they have a page of their own — and the general page no longer carries them.
     */
    public function test_the_admissions_settings_are_on_their_own_page(): void
    {
        $general = $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))->assertOk()->getContent();

        $admissions = $this->actingAs($this->admin)
            ->get(route('admin.settings.admissions'))->assertOk()->getContent();

        foreach (['settings[registration_open]', 'settings[application_fee]', 'settings[default_cutoff_mark]', 'settings[admission_letter_body]', 'settings[admission_letter_title]'] as $field) {
            $this->assertStringContainsString($field, $admissions, "{$field} is not on the admissions settings page.");
            $this->assertStringNotContainsString($field, $general, "{$field} is still on the general settings page.");
        }

        // And the other way about: the school's own settings stayed where they were.
        $this->assertStringContainsString('settings[school_name]', $general);
        $this->assertStringNotContainsString('settings[school_name]', $admissions);
    }

    /**
     * The school's accounts elsewhere are a sitting's work of their own, and they
     * are not among the school's own settings: whoever changes the school's phone
     * number has no business scrolling past a secret key to do it.
     */
    public function test_the_api_keys_are_on_a_page_of_their_own(): void
    {
        $this->assertSame([
            'Paystack — fees collection',
            'Termii — text messages',
            'DeepSeek — reading scoresheets',
        ], $this->headingsOn('admin.settings.api'));

        $general = $this->headingsOn('admin.settings.index');

        // The switch that turns text messages on is not a credential, so it stayed
        // behind where the office can reach it without going past a key.
        $this->assertContains('Text messages (SMS)', $general);

        // And the keys are not drawn on the general page as well: a setting with two
        // homes is a setting that will sooner or later have two values.
        $this->assertNotContains('Paystack — fees collection', $general);
        $this->assertNotContains('Termii — text messages', $general);
        $this->assertNotContains('DeepSeek — reading scoresheets', $general);
    }

    public function test_the_general_page_no_longer_carries_the_api_keys(): void
    {
        $general = $this->htmlOn('admin.settings.index');

        foreach (['settings[paystack_public_key]', 'settings[paystack_secret_key]', 'settings[termii_api_key]', 'settings[termii_sender_id]', 'settings[ai_provider]', 'settings[ai_api_key]', 'settings[ai_model]'] as $field) {
            $this->assertSame(
                0,
                substr_count($general, $field),
                "{$field} is still drawn on the general settings page.",
            );
        }
    }

    /**
     * Counted rather than compared: a page of settings is sixty kilobytes of HTML,
     * and a failure that prints all of it is a failure nobody reads.
     */
    private function htmlOn(string $route): string
    {
        return $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();
    }

    /**
     * @return array<int,string>
     */
    private function headingsOn(string $route): array
    {
        preg_match_all('/<h2 class="text-base font-semibold[^"]*">\s*([^<]+?)\s*<\/h2>/s', $this->htmlOn($route), $matches);

        return array_values(array_filter(array_map('trim', $matches[1])));
    }

    /** The groups keep the order they are declared in, whatever page they are on. */
    public function test_the_admissions_page_draws_admissions_before_the_letters(): void
    {
        $admissions = $this->actingAs($this->admin)
            ->get(route('admin.settings.admissions'))->assertOk()->getContent();

        $this->assertNotFalse(strpos($admissions, 'settings[admission_letter_title]'));

        $this->assertLessThan(
            strpos($admissions, 'settings[admission_letter_title]'),
            strpos($admissions, 'settings[default_cutoff_mark]'),
        );
    }

    /** A group the layout has never heard_of is left on the general page, not hidden. */
    public function test_a_group_the_layout_has_never_heard_of_goes_to_the_general_page(): void
    {
        Setting::put('library_fine', '50', ['group' => 'library', 'label' => 'Library fine']);

        $settings = Setting::query()->orderBy('key')->get()->groupBy('group');

        $this->assertSame(['admissions', 'letters'], array_column(SettingLayout::arrange($settings, 'admissions'), 'key'));
        $this->assertContains('library', array_column(SettingLayout::arrange($settings, 'general'), 'key'));
    }

    /* ------------------------------------------------------------------ */
    /* The order of the fields inside a group */
    /* ------------------------------------------------------------------ */

    /**
     * The school's name is the first thing a school sets, and it was the seventh
     * field in its own group.
     */
    public function test_the_school_name_comes_before_its_address(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.settings.index'))->assertOk()->getContent();

        $name = strpos($html, 'id="setting_school_name"');
        $motto = strpos($html, 'id="setting_school_motto"');
        $address = strpos($html, 'id="setting_contact_address"');
        $logo = strpos($html, 'setting_school_logo');

        $this->assertNotFalse($name);
        $this->assertNotFalse($address);

        $this->assertLessThan($motto, $name, 'Motto is drawn above the school name.');
        $this->assertLessThan($address, $name, 'The address is drawn above the school name.');
        $this->assertLessThan($logo, $address, 'The logo is drawn above the address.');
    }

    public function test_a_prefix_is_drawn_next_to_the_padding_it_belongs_to(): void
    {
        $items = $this->group('numbering', [
            'receipt_prefix',
            'student_number_padding',
            'admission_number_prefix',
            'student_number_prefix',
            'invoice_prefix',
            'admission_number_padding',
        ]);

        $ordered = SettingLayout::fieldsIn($items, 'numbering')->pluck('key')->all();

        $this->assertSame([
            'admission_number_prefix',
            'admission_number_padding',
            'student_number_prefix',
            'student_number_padding',
            'invoice_prefix',
            'receipt_prefix',
        ], $ordered);
    }

    /* ------------------------------------------------------------------ */
    /* Nothing may quietly disappear */
    /* ------------------------------------------------------------------ */

    /**
     * The layout file is knowledge about the school; the database is what exists.
     * Where they disagree, the setting is shown.
     */
    public function test_a_setting_the_layout_has_never_heard_of_is_still_shown(): void
    {
        Setting::put('bell_time', '07:30', ['group' => 'branding', 'label' => 'Bell time']);

        $settings = Setting::query()->orderBy('key')->get()->groupBy('group');
        $branding = collect(SettingLayout::arrange($settings))->firstWhere('key', 'branding');

        $keys = $branding['items']->pluck('key')->all();

        $this->assertContains('bell_time', $keys);
        // Appended rather than interleaved, so a listed field never moves.
        $this->assertSame('bell_time', end($keys));
    }

    public function test_a_group_the_layout_has_never_heard_of_is_still_shown(): void
    {
        Setting::put('library_fine', '50', ['group' => 'library', 'label' => 'Library fine']);

        $settings = Setting::query()->orderBy('key')->get()->groupBy('group');
        $drawn = SettingLayout::arrange($settings);

        $this->assertSame('library', end($drawn)['key']);
        $this->assertSame('Library', end($drawn)['label'], 'An unknown group falls back to its own name.');
    }

    /** An empty heading is furniture, so a declared group with nothing in it is skipped. */
    public function test_a_declared_group_with_no_settings_is_not_drawn(): void
    {
        $this->assertSame(0, Setting::query()->where('group', 'general')->count());

        $html = $this->actingAs($this->admin)->get(route('admin.settings.index'))->assertOk()->getContent();

        preg_match_all('/<h2 class="text-base font-semibold[^"]*">\s*([^<]+?)\s*<\/h2>/s', $html, $matches);

        $headings = array_map('trim', $matches[1]);

        $this->assertContains('School branding', $headings);
        $this->assertNotContains('General', $headings);
    }

    public function test_every_setting_in_the_database_reaches_a_page(): void
    {
        $pages = [
            route('admin.settings.index'),
            route('admin.settings.admissions'),
            // The school's accounts with other people are set up here, and this test
            // is what catches a setting saved into a page nobody draws.
            route('admin.settings.api'),
        ];

        $html = '';

        foreach ($pages as $page) {
            $html .= $this->actingAs($this->admin)->get($page)->assertOk()->getContent();
        }

        foreach (Setting::query()->pluck('key') as $key) {
            $this->assertStringContainsString(
                'settings['.$key.']',
                $html,
                "The setting {$key} is not on any settings page.",
            );
        }
    }
}
