<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The school's logo and favicon are uploaded from the settings page.
 *
 * The thing worth guarding is not the upload itself but what happens around it:
 * a second upload must not leave the first file on the server for ever, and â€”
 * the trap this was written for â€” pressing "Save settings" on an unrelated field
 * must not blank a logo the form carried no value for.
 */
class SchoolBrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        Storage::fake('public');
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    private function save(array $settings)
    {
        return $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'settings' => $settings,
        ]);
    }

    private function logoSetting(): Setting
    {
        return Setting::query()->where('key', 'school_logo')->sole();
    }

    /* ------------------------------------------------------------------ */
    /* The settings page itself                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_branding_section_offers_a_logo_and_a_favicon(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('School branding')
            ->assertSee('School logo')
            ->assertSee('Browser favicon')
            ->assertSee('No logo yet')
            ->assertSee('No favicon yet');
    }

    public function test_the_settings_form_can_carry_a_file(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false);
    }

    /* ------------------------------------------------------------------ */
    /* Uploading                                                           */
    /* ------------------------------------------------------------------ */

    public function test_a_logo_is_stored_and_then_shown_on_the_site(): void
    {
        $this->save([
            'school_logo' => ['file' => UploadedFile::fake()->image('crest.png', 200, 200)],
        ])->assertSessionHasNoErrors();

        $path = $this->logoSetting()->value;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // In the header of every public page, and on the settings page as a preview.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('storage/' . $path, false);
    }

    public function test_a_favicon_is_linked_in_the_page_head(): void
    {
        $this->save([
            'school_favicon' => ['file' => UploadedFile::fake()->image('favicon.png', 64, 64)],
        ])->assertSessionHasNoErrors();

        $path = Setting::get('school_favicon');

        $this->assertNotNull($path);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<link rel="icon" href="' . asset('storage/' . $path) . '">', false);
    }

    public function test_uploading_again_deletes_the_file_it_replaced(): void
    {
        $this->save(['school_logo' => ['file' => UploadedFile::fake()->image('first.png')]]);
        $first = $this->logoSetting()->value;

        $this->save(['school_logo' => ['file' => UploadedFile::fake()->image('second.png')]]);
        $second = $this->logoSetting()->value;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_the_remove_box_clears_the_logo_and_its_file(): void
    {
        $this->save(['school_logo' => ['file' => UploadedFile::fake()->image('crest.png')]]);
        $path = $this->logoSetting()->value;

        $this->save(['school_logo' => ['remove' => '1']])->assertSessionHasNoErrors();

        $this->assertNull($this->logoSetting()->value);
        Storage::disk('public')->assertMissing($path);

        // And the initials come back in the header.
        $this->get(route('home'))->assertOk()->assertDontSee('storage/branding', false);
    }

    /**
     * The trap: the logo field posts no text value, so a blanket "write every
     * posted value" would set it to null the moment anybody saved the page to
     * change, say, the contact phone number.
     */
    public function test_saving_another_setting_does_not_blank_the_logo(): void
    {
        $this->save(['school_logo' => ['file' => UploadedFile::fake()->image('crest.png')]]);
        $path = $this->logoSetting()->value;

        $this->save([
            'contact_phone' => ['value' => '0803 000 1111'],
            'school_name' => ['value' => 'Saci Grammar School'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($path, $this->logoSetting()->value);
        Storage::disk('public')->assertExists($path);
        $this->assertSame('0803 000 1111', Setting::get('contact_phone'));
        $this->assertSame('Saci Grammar School', Setting::get('school_name'));
    }

    /* ------------------------------------------------------------------ */
    /* What is refused                                                     */
    /* ------------------------------------------------------------------ */

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->save([
            'school_logo' => ['file' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf')],
        ])->assertSessionHasErrors('settings.school_logo.file');

        $this->assertNull($this->logoSetting()->value);
        Storage::disk('public')->assertDirectoryEmpty('branding');
    }

    public function test_an_oversized_image_is_refused(): void
    {
        $this->save([
            'school_logo' => ['file' => UploadedFile::fake()->image('huge.png')->size(3000)],
        ])->assertSessionHasErrors('settings.school_logo.file');

        $this->assertNull($this->logoSetting()->value);
    }

    public function test_only_a_manager_can_change_the_branding(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('admin.settings.index'))->assertForbidden();

        $this->actingAs($nobody)->put(route('admin.settings.update'), [
            'settings' => ['school_name' => ['value' => 'Hacked Academy']],
        ])->assertForbidden();

        $this->assertSame('Saci Schools', Setting::get('school_name'));
    }

    /* ------------------------------------------------------------------ */
    /* The fallback                                                        */
    /* ------------------------------------------------------------------ */

    public function test_the_initials_stand_in_until_a_logo_is_uploaded(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('SS')
            ->assertDontSee('storage/branding', false);
    }
}

