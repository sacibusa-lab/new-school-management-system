<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SMS centre is a placeholder, and a placeholder has one job: not to look
 * broken. It also has to be honest about the Termii credentials, because that is
 * the first thing anybody checks when a message does not arrive.
 */
class SmsCenterTest extends TestCase
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

    public function test_the_sms_centre_is_on_the_menu_and_opens(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.sms.center'))
            ->assertOk()
            // The menu entry itself, so the route is reachable by clicking.
            ->assertSee('SMS center')
            ->assertSee('This screen is reserved');
    }

    public function test_it_points_at_the_screens_that_already_work(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.sms.center'))
            ->assertOk()
            // A placeholder that is a dead end is worse than no placeholder.
            ->assertSee(route('admin.sms.index'), false)
            ->assertSee(route('admin.sms.batch'), false)
            ->assertSee(route('admin.sms.templates'), false);
    }

    public function test_it_says_the_gateway_is_not_ready_while_there_is_no_api_key(): void
    {
        Setting::put('termii_api_key', '');
        Setting::put('sms_enabled', true);

        $this->actingAs($this->admin)
            ->get(route('admin.sms.center'))
            ->assertOk()
            ->assertSee('Not sending yet')
            ->assertSee('Not set')
            ->assertSee('No API key yet');
    }

    public function test_it_says_sending_is_off_when_the_switch_is_off(): void
    {
        Setting::put('termii_api_key', 'a-real-key');
        Setting::put('sms_enabled', false);

        $this->actingAs($this->admin)
            ->get(route('admin.sms.center'))
            ->assertOk()
            // A key with the switch off is still not ready, and saying "ready"
            // would send somebody hunting in the wrong place.
            ->assertSee('Not sending yet')
            ->assertSee('Switched off');
    }

    public function test_it_reports_ready_when_the_key_is_set_and_sending_is_on(): void
    {
        Setting::put('termii_api_key', 'a-real-key');
        Setting::put('sms_enabled', true);
        Setting::put('termii_sender_id', 'SACISCH');
        Setting::put('termii_channel', 'dnd');

        $this->actingAs($this->admin)
            ->get(route('admin.sms.center'))
            ->assertOk()
            ->assertSee('Ready to send')
            ->assertSee('SACISCH')
            ->assertSee('dnd');
    }

    public function test_somebody_who_cannot_see_messages_cannot_open_the_sms_centre(): void
    {
        $student = User::factory()->create();
        $student->assignRole('Student');

        $this->actingAs($student)
            ->get(route('admin.sms.center'))
            ->assertForbidden();
    }
}
