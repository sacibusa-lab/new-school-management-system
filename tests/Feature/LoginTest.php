<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing in with the number on the record.
 *
 * The office signs in with an email address; a teacher signs in with the phone
 * number the school keeps for them. Both go in the same box, and the number is
 * written however the person writing it writes it — which is the thing this file
 * is really about: `+2348031234567`, `2348031234567` and `0803 123 4567` are one
 * account, not three.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();
    }

    /** The case the whole change exists for: a teacher with no email at all. */
    public function test_a_teacher_signs_in_with_their_phone_number(): void
    {
        $teacher = $this->teacher('08031234567');

        $this->post(route('login'), [
            'email' => '08031234567',
            'password' => 'password',
        ])->assertRedirect(route('admin.scores.index'));

        $this->assertAuthenticatedAs($teacher);
    }

    /**
     * However the number is written, it is the same line. The country code may be
     * there or not, the leading zero may be there or not, and the spaces and dashes
     * a person types out of habit are not part of the number.
     */
    public function test_the_number_is_read_with_or_without_the_country_code(): void
    {
        $teacher = $this->teacher('08031234567');

        $written = [
            '08031234567',
            '+2348031234567',
            '2348031234567',
            '002348031234567',
            '0803 123 4567',
            '0803-123-4567',
        ];

        foreach ($written as $typed) {
            $this->post(route('login'), ['email' => $typed, 'password' => 'password'])
                ->assertRedirect(route('admin.scores.index'));

            $this->assertAuthenticatedAs($teacher);

            $this->post(route('logout'));
        }
    }

    /** An office account still gets in the way it always did. */
    public function test_staff_still_sign_in_with_an_email_address(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@saci.test',
            'password' => 'password',
        ]);

        $admin->assignRole('Super Admin');

        $this->post(route('login'), [
            'email' => 'admin@saci.test',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_wrong_password_still_does_not_get_in(): void
    {
        $this->teacher('08031234567');

        $this->post(route('login'), [
            'email' => '08031234567',
            'password' => 'not the password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** A number nobody is on is not a way in either. */
    public function test_a_number_that_is_not_on_any_record_does_not_get_in(): void
    {
        $this->teacher('08031234567');

        $this->post(route('login'), [
            'email' => '08099999999',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    private function teacher(string $phone): User
    {
        $teacher = User::factory()->create([
            'email' => null,
            'phone' => $phone,
            'password' => 'password',
            'is_active' => true,
        ]);

        $teacher->assignRole('Teacher');

        return $teacher;
    }
}
