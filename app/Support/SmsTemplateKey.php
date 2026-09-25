<?php

namespace App\Support;

/**
 * The SMS messages the platform can send, the wording they start with, and the
 * placeholders each one understands.
 *
 * The office edits the wording in Settings; the keys stay fixed because code
 * refers to them.
 */
class SmsTemplateKey
{
    public const APPLICANT_REGISTERED = 'applicant_registered';
    public const APPLICANT_ADMITTED = 'applicant_admitted';
    public const APPLICANT_REJECTED = 'applicant_rejected';
    public const RESIT_REGISTERED = 'resit_registered';
    public const PAYMENT_RECEIVED = 'payment_received';
    public const RESULTS_PUBLISHED = 'results_published';
    public const EXAM_SCHEDULED = 'exam_scheduled';

    /**
     * key => [name, description, body, placeholders]
     *
     * @return array<string,array{name:string,description:string,body:string,placeholders:array<int,string>}>
     */
    public static function defaults(): array
    {
        return [
            self::APPLICANT_REGISTERED => [
                'name' => 'Application received',
                'description' => 'Sent to the guardian the moment an application is submitted.',
                'body' => 'Dear {guardian_name}, {full_name} has been registered for admission to {school_name}. '
                    . 'Registration number: {registration_number}. Please keep it safe. Class applied for: {class}.',
                'placeholders' => ['guardian_name', 'full_name', 'first_name', 'surname', 'school_name', 'registration_number', 'class', 'session'],
            ],

            self::EXAM_SCHEDULED => [
                'name' => 'Examination scheduled',
                'description' => 'Tells a candidate when and where to sit the entrance examination.',
                'body' => 'Dear {guardian_name}, the entrance examination for {full_name} ({registration_number}) '
                    . 'holds on {exam_date} at {venue}. Please arrive by {exam_time} with the registration slip.',
                'placeholders' => ['guardian_name', 'full_name', 'registration_number', 'exam_date', 'exam_time', 'venue', 'school_name'],
            ],

            self::APPLICANT_ADMITTED => [
                'name' => 'Admitted',
                'description' => 'Sent when an applicant passes the cutoff and is admitted.',
                'body' => 'Congratulations! {full_name} has been admitted into {class} at {school_name} '
                    . 'for {session}. Admission number: {admission_number}. Report to the school office with this number.',
                'placeholders' => ['full_name', 'first_name', 'surname', 'class', 'school_name', 'session', 'admission_number', 'registration_number', 'average', 'cutoff'],
            ],

            self::APPLICANT_REJECTED => [
                'name' => 'Not admitted',
                'description' => 'Sent when an applicant does not meet the cutoff mark.',
                'body' => 'Dear {guardian_name}, we are sorry to inform you that {full_name} did not meet the cutoff mark '
                    . 'for {class} at {school_name}. You may apply for a resit examination. '
                    . 'Please call the school office for advice.',
                'placeholders' => ['guardian_name', 'full_name', 'registration_number', 'class', 'school_name', 'average', 'cutoff'],
            ],

            self::RESIT_REGISTERED => [
                'name' => 'Resit registered',
                'description' => 'Confirms a resit examination has been booked.',
                'body' => 'Dear {guardian_name}, {full_name} ({registration_number}) has been registered for a resit '
                    . 'examination at {school_name}. You will be told the date in due course.',
                'placeholders' => ['guardian_name', 'full_name', 'registration_number', 'school_name', 'session'],
            ],

            self::PAYMENT_RECEIVED => [
                'name' => 'Payment received',
                'description' => 'Receipt confirmation sent to the guardian after a payment is recorded.',
                'body' => 'Dear {guardian_name}, we have received {amount} for {full_name} ({admission_number}). '
                    . 'Receipt number: {receipt_number}. Outstanding balance: {balance}. Thank you. — {school_name}',
                'placeholders' => ['guardian_name', 'full_name', 'admission_number', 'amount', 'receipt_number', 'balance', 'school_name'],
            ],

            self::RESULTS_PUBLISHED => [
                'name' => 'Results published',
                'description' => 'Tells a guardian their child\'s term result is ready to check.',
                'body' => 'Dear {guardian_name}, the {term} result for {full_name} ({admission_number}) has been published. '
                    . 'Check it at {results_url} using the admission number.',
                'placeholders' => ['guardian_name', 'full_name', 'admission_number', 'term', 'session', 'school_name', 'results_url'],
            ],
        ];
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    /** @return array<int,string> */
    public static function placeholders(string $key): array
    {
        return self::defaults()[$key]['placeholders'] ?? [];
    }

    public static function label(string $key): string
    {
        return self::defaults()[$key]['name'] ?? $key;
    }
}
