<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Student;
use App\Support\Surname;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The number guards the wrong half of the public lookups.
 *
 * SAC-00001, SAC-00002, SAC/2026/001 … are sequential, so anybody willing to
 * count can walk them. The surname is the half that has to do the work — and on
 * the admission status page the prize is a child's photograph.
 *
 * So this is about the comparison itself: forgiving in the ways a parent can
 * honestly differ, and never forgiving in the ways that would let a stranger in.
 */
class SurnameTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'is_current' => true,
            'is_admission_open' => true,
        ]);
    }

    private function applicant(string $lastName = 'Okafor', ?string $photo = null): Applicant
    {
        return Applicant::create([
            'registration_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => $lastName,
            'guardian_phone' => '08031234567',
            'guardian_email' => 'parent@example.com',
            'photo_path' => $photo,
            'academic_session_id' => $this->session->id,
            'submitted_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The ways a parent may honestly differ                               */
    /* ------------------------------------------------------------------ */

    public function test_capitals_and_stray_spaces_do_not_matter(): void
    {
        foreach (['Okafor', 'okafor', 'OKAFOR', '  Okafor  ', 'OkAfOr'] as $typed) {
            $this->assertTrue(Surname::matches('Okafor', $typed), "Failed on \"{$typed}\".");
        }
    }

    /** A parent who types the child's whole name into the surname box means well. */
    public function test_the_whole_name_is_accepted_when_it_contains_the_surname(): void
    {
        $this->assertTrue(Surname::matches('Okafor', 'Chidera Okafor'));
        $this->assertTrue(Surname::matches('Okafor', 'Okafor Chidera'));
    }

    /** A phone keyboard often cannot type the dotted vowels of an Igbo name. */
    public function test_a_dotted_vowel_still_finds_its_name(): void
    {
        $this->assertTrue(Surname::matches('Ọkafor', 'Okafor'), 'The record has Ọkafor, the parent typed Okafor.');
        $this->assertTrue(Surname::matches('Ọkafor', 'ọkafor'));
        $this->assertTrue(Surname::matches('Nwụbụ', 'Nwubu'));
        $this->assertTrue(Surname::matches('Nwaòbi', 'Nwaobi'));
    }

    /**
     * Knowing one half of a compound surname is enough — which also means a
     * near-miss on the other half still opens the record. That is deliberate: the
     * parent is proving they know the family name, and shutting somebody out of
     * their own child's record is the worse failure of the two.
     */
    public function test_a_compound_surname_matches_on_either_half(): void
    {
        $this->assertTrue(Surname::matches('Okafor-Bello', 'Okafor-Bello'));
        $this->assertTrue(Surname::matches('Okafor-Bello', 'okafor'));
        $this->assertTrue(Surname::matches('Okafor-Bello', 'Bello'));
        $this->assertTrue(Surname::matches('Okafor-Bello', 'Chidera Okafor'));

        // A name that shares nothing with it is still refused.
        $this->assertFalse(Surname::matches('Okafor-Bello', 'Adeyemi'));
        $this->assertFalse(Surname::matches('Okafor-Bello', 'Bella'));
    }

    /* ------------------------------------------------------------------ */
    /* The ways a stranger must never get in                               */
    /* ------------------------------------------------------------------ */

    public function test_a_wrong_surname_never_matches(): void
    {
        $this->assertFalse(Surname::matches('Okafor', 'Bello'));
        $this->assertFalse(Surname::matches('Okafor', 'Chidera Bello'));
    }

    /** Nearly right is not right. A prefix would hand the record over on a guess. */
    public function test_a_partial_surname_never_matches(): void
    {
        foreach (['Okaf', 'Oka', 'O', 'Okaforr', 'kafor'] as $nearly) {
            $this->assertFalse(Surname::matches('Okafor', $nearly), "Failed on \"{$nearly}\".");
        }
    }

    /** A blank box must never be a pass, and a record with no surname can never open. */
    public function test_nothing_matches_when_either_side_is_blank(): void
    {
        foreach ([null, '', '   ', '-'] as $blank) {
            $this->assertFalse(Surname::matches('Okafor', $blank), 'A blank surname must not match.');
            $this->assertFalse(Surname::matches($blank, 'Okafor'), 'A record with no surname must not match.');
        }

        $this->assertFalse(Surname::matches(null, null));
    }

    /* ------------------------------------------------------------------ */
    /* Through the actual page                                             */
    /* ------------------------------------------------------------------ */

    public function test_the_child_is_not_shown_to_a_wrong_surname(): void
    {
        $this->applicant('Okafor', 'photos/applicants/chidera.jpg');

        $this->get(route('public.status', [
            'registration_number' => 'SAC-00001',
            'surname' => 'Bello',
        ]))
            ->assertOk()
            ->assertSee('No match found')
            // Not the name, and not the photograph.
            ->assertDontSee('Chidera')
            ->assertDontSee('storage/photos/applicants/chidera.jpg', false);
    }

    public function test_the_lookup_will_not_run_without_a_surname(): void
    {
        $this->get(route('public.status', ['registration_number' => 'SAC-00001']))
            ->assertSessionHasErrors('surname')
            ->assertRedirect();
    }

    public function test_the_right_surname_opens_the_page(): void
    {
        $this->applicant();

        $this->get(route('public.status', [
            'registration_number' => 'SAC-00001',
            'surname' => '  okafor ',
        ]))
            ->assertOk()
            ->assertSee('Chidera Okafor');
    }

    /* ------------------------------------------------------------------ */
    /* The other two lookups, which asked for a surname and never checked   */
    /* ------------------------------------------------------------------ */

    private function student(): Student
    {
        return Student::create([
            'academic_session_id' => $this->session->id,
            'student_number' => 'SAC/2026/001',
            'admission_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
        ]);
    }

    public function test_results_need_the_surname_too(): void
    {
        $this->student();

        // Without it: refused outright.
        $this->get(route('public.results', ['student_number' => 'SAC/2026/001']))
            ->assertSessionHasErrors('surname');

        // With the wrong one: no match, and no child.
        $this->get(route('public.results', [
            'student_number' => 'SAC/2026/001',
            'surname' => 'Bello',
        ]))
            ->assertOk()
            ->assertSee('No match found')
            ->assertDontSee('Chidera');

        // With the right one: through.
        $this->get(route('public.results', [
            'student_number' => 'SAC/2026/001',
            'surname' => 'Okafor',
        ]))
            ->assertOk()
            ->assertDontSee('No match found');
    }

    public function test_fee_status_needs_the_surname_too(): void
    {
        $this->student();

        $this->get(route('public.fees', ['student_number' => 'SAC/2026/001']))
            ->assertSessionHasErrors('surname');

        $this->get(route('public.fees', [
            'student_number' => 'SAC/2026/001',
            'surname' => 'Bello',
        ]))
            ->assertOk()
            ->assertSee('No match found')
            ->assertDontSee('Chidera');
    }
}
