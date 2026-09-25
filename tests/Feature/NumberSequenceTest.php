<?php

namespace Tests\Feature;

use App\Enums\SequenceType;
use App\Models\Setting;
use App\Services\NumberSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private NumberSequenceService $sequences;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flush();
        $this->sequences = app(NumberSequenceService::class);
    }

    public function test_registration_numbers_ascend_from_one(): void
    {
        $this->assertSame('SAC-00001', $this->sequences->nextAdmissionRegistrationNumber());
        $this->assertSame('SAC-00002', $this->sequences->nextAdmissionRegistrationNumber());
        $this->assertSame('SAC-00003', $this->sequences->nextAdmissionRegistrationNumber());
    }

    public function test_registration_number_grows_past_five_digits(): void
    {
        $this->sequences->setLastNumber(SequenceType::AdmissionRegistration, 'global', 99999);

        $this->assertSame('SAC-100000', $this->sequences->nextAdmissionRegistrationNumber());
    }

    public function test_student_numbers_restart_each_academic_year(): void
    {
        $this->assertSame('SAC/2026/001', $this->sequences->nextStudentNumber(2026));
        $this->assertSame('SAC/2026/002', $this->sequences->nextStudentNumber(2026));
        $this->assertSame('SAC/2026/003', $this->sequences->nextStudentNumber(2026));

        // A new year starts again from 001 — this is the behaviour the school asked for.
        $this->assertSame('SAC/2027/001', $this->sequences->nextStudentNumber(2027));
        $this->assertSame('SAC/2027/002', $this->sequences->nextStudentNumber(2027));

        // And the previous year is untouched.
        $this->assertSame('SAC/2026/004', $this->sequences->nextStudentNumber(2026));
    }

    public function test_the_two_series_do_not_interfere(): void
    {
        $this->sequences->nextAdmissionRegistrationNumber();
        $this->sequences->nextAdmissionRegistrationNumber();

        $this->assertSame('SAC/2026/001', $this->sequences->nextStudentNumber(2026));
    }

    public function test_preview_reports_without_consuming(): void
    {
        $this->sequences->nextAdmissionRegistrationNumber();

        $this->assertSame('SAC-00002', $this->sequences->preview(SequenceType::AdmissionRegistration));
        $this->assertSame('SAC-00002', $this->sequences->preview(SequenceType::AdmissionRegistration));

        // Still the same value once we actually allocate it.
        $this->assertSame('SAC-00002', $this->sequences->nextAdmissionRegistrationNumber());
    }

    public function test_prefixes_are_configurable_from_settings(): void
    {
        Setting::put('admission_number_prefix', 'SACI');
        Setting::put('student_number_prefix', 'SACI');

        $this->assertSame('SACI-00001', $this->sequences->nextAdmissionRegistrationNumber());
        $this->assertSame('SACI/2026/001', $this->sequences->nextStudentNumber(2026));
    }

    public function test_no_number_is_ever_issued_twice(): void
    {
        $issued = [];

        for ($i = 0; $i < 60; $i++) {
            $issued[] = $this->sequences->nextAdmissionRegistrationNumber();
        }

        $this->assertCount(60, array_unique($issued));
        $this->assertSame('SAC-00060', end($issued));
    }
}
