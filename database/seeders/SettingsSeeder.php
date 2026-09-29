<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Branding
            ['key' => 'school_name', 'value' => 'Saci Schools', 'group' => 'branding', 'label' => 'School name'],
            ['key' => 'school_tagline', 'value' => 'Knowledge, Character, Service', 'group' => 'branding', 'label' => 'Tagline'],
            ['key' => 'school_motto', 'value' => 'Learn. Lead. Serve.', 'group' => 'branding', 'label' => 'Motto'],
            ['key' => 'contact_email', 'value' => 'info@saci.test', 'group' => 'branding', 'label' => 'Contact email'],
            ['key' => 'contact_phone', 'value' => '0800 000 0000', 'group' => 'branding', 'label' => 'Contact phone'],
            ['key' => 'contact_address', 'value' => '1 School Road, Lagos, Nigeria', 'group' => 'branding', 'label' => 'Address'],
            // Uploaded images, not typed text. See the add_branding_image_settings
            // migration: adding these here alone would not reach a live database,
            // because this seeder overwrites every value it touches.
            ['key' => 'school_logo', 'value' => null, 'group' => 'branding', 'type' => 'image', 'label' => 'School logo'],
            ['key' => 'school_favicon', 'value' => null, 'group' => 'branding', 'type' => 'image', 'label' => 'Browser favicon'],

            // Numbering
            ['key' => 'admission_number_prefix', 'value' => 'SAC', 'group' => 'numbering', 'label' => 'Admission number prefix'],
            ['key' => 'admission_number_padding', 'value' => '5', 'group' => 'numbering', 'type' => 'int', 'label' => 'Admission number digits'],
            ['key' => 'student_number_prefix', 'value' => 'SAC', 'group' => 'numbering', 'label' => 'Student number prefix'],
            ['key' => 'student_number_padding', 'value' => '3', 'group' => 'numbering', 'type' => 'int', 'label' => 'Student number digits'],
            ['key' => 'invoice_prefix', 'value' => 'INV', 'group' => 'numbering', 'label' => 'Invoice prefix'],
            ['key' => 'receipt_prefix', 'value' => 'RCP', 'group' => 'numbering', 'label' => 'Receipt prefix'],

            // Admissions
            ['key' => 'registration_open', 'value' => '1', 'group' => 'admissions', 'type' => 'bool', 'label' => 'Registration open'],
            ['key' => 'application_fee', 'value' => '5000', 'group' => 'admissions', 'type' => 'int', 'label' => 'Application fee (₦)'],
            ['key' => 'default_cutoff_mark', 'value' => '50', 'group' => 'admissions', 'label' => 'Default cutoff mark'],
            // The papers every entrance candidate sits. Pre-selected on the exam form.
            ['key' => 'entrance_exam_subjects', 'value' => '["MTH","ENG","GPR"]', 'group' => 'admissions', 'type' => 'json', 'label' => 'Standard entrance examination papers'],
            ['key' => 'resit_enabled', 'value' => '1', 'group' => 'admissions', 'type' => 'bool', 'label' => 'Allow resit applications'],

            // Admission letters
            ['key' => 'admission_letter_title', 'value' => 'Letter of Admission', 'group' => 'letters', 'label' => 'Letter heading'],
            ['key' => 'admission_letter_body', 'value' => "Dear {guardian_name},\n\nWe are pleased to inform you that {full_name} has been offered admission into {class} at {school_name} for the {session} academic session.\n\nAdmission number: {admission_number}\nRegistration number: {registration_number}\nEntrance examination average: {average} (cutoff mark: {cutoff})\n\nPlease report to the school office with this letter to complete registration and pay the required fees. This offer is subject to verification of the documents you submitted.\n\nCongratulations.", 'group' => 'letters', 'type' => 'text', 'label' => 'Letter body'],
            ['key' => 'admission_letter_signatory_title', 'value' => 'Principal', 'group' => 'letters', 'label' => 'Signatory title'],
            ['key' => 'admission_letter_signatory', 'value' => '', 'group' => 'letters', 'label' => 'Signatory name'],
            ['key' => 'admission_letter_note', 'value' => 'This offer is subject to verification of the documents you submitted. Please bring the originals when you report.', 'group' => 'letters', 'type' => 'text', 'label' => 'Note printed at the foot of the letter'],

            // Messaging
            ['key' => 'sms_enabled', 'value' => '1', 'group' => 'messaging', 'type' => 'bool', 'label' => 'Send text messages'],
            ['key' => 'termii_api_key', 'value' => '', 'group' => 'messaging', 'label' => 'Termii API key'],
            ['key' => 'termii_sender_id', 'value' => 'SACISCH', 'group' => 'messaging', 'label' => 'Termii sender ID'],
            ['key' => 'termii_channel', 'value' => 'generic', 'group' => 'messaging', 'label' => 'Termii channel'],

            // Fees
            ['key' => 'currency', 'value' => 'NGN', 'group' => 'fees', 'label' => 'Currency code'],
            ['key' => 'currency_symbol', 'value' => '₦', 'group' => 'fees', 'label' => 'Currency symbol'],
            ['key' => 'invoice_due_days', 'value' => '30', 'group' => 'fees', 'type' => 'int', 'label' => 'Invoice due days'],

            // Results
            ['key' => 'ca_max_total', 'value' => '40', 'group' => 'results', 'type' => 'int', 'label' => 'CA total marks'],
            ['key' => 'exam_max_total', 'value' => '60', 'group' => 'results', 'type' => 'int', 'label' => 'Exam total marks'],
        ];

        foreach ($settings as $setting) {
            $metadata = [
                'group' => $setting['group'],
                'type' => $setting['type'] ?? 'string',
                'label' => $setting['label'] ?? null,
            ];

            $existing = Setting::query()->where('key', $setting['key'])->first();

            /*
             * This seeder is run against live databases — `migrate --seed` on a
             * fresh clone, as the README says — so it must never write a VALUE
             * over one that is already there. Doing that would silently reset the
             * school's name, phone number, number prefixes and admission-letter
             * wording back to these defaults, and nobody would know until a parent
             * saw the wrong address on a letter.
             *
             * So it only creates what is missing. The metadata (label, group,
             * type) is still kept in step, because that is how a corrected type or
             * a renamed label reaches an installation that already has the row.
             */
            if ($existing) {
                $existing->update($metadata);

                continue;
            }

            Setting::create($metadata + [
                'key' => $setting['key'],
                'value' => $setting['value'],
            ]);
        }

        Setting::flush();
    }
}
