<?php

namespace App\Support;

/**
 * Single source of truth for every permission in the platform and how the
 * seven roles map onto them. Seeders and the UI both read from here.
 */
class Permissions
{
    /** Grouped permissions: group => [permission => description]. */
    public static function groups(): array
    {
        return [
            'Admissions' => [
                'admissions.view' => 'View applicants and their details',
                'admissions.create' => 'Register a new applicant',
                'admissions.update' => 'Edit applicant records',
                'admissions.delete' => 'Delete applicant records',
                'admissions.approve' => 'Shortlist applicants',
                'admissions.export' => 'Export applicant lists',
                'admissions.letters' => 'Issue and print admission letters',
                'admissions.resit' => 'Register candidates for a resit examination',
            ],
            'Examinations' => [
                'exams.view' => 'View examinations',
                'exams.manage' => 'Create and edit examinations and subjects',
                'scores.view' => 'View scores',
                'scores.enter' => 'Type scores by hand',
                'scores.import' => 'Upload score sheets',
                'scores.verify' => 'Verify machine-read scores',
                'scores.override' => 'Correct or override any score',
            ],
            'Admission Decisions' => [
                'admissions.cutoff' => 'Set the cutoff mark per level',
                'admissions.decide' => 'Approve or reject applicants',
                'admissions.enrol' => 'Transfer admitted applicants into results and fees',
            ],
            'Students' => [
                'students.view' => 'View student records',
                'students.manage' => 'Edit and promote students',
                'students.import' => 'Bulk import students',
            ],
            'Fees' => [
                'fees.view' => 'View fee structures and invoices',
                'fees.manage' => 'Manage fee categories, structures and amounts',
                'fees.invoice' => 'Generate and edit invoices',
                'payments.record' => 'Record payments and issue receipts',
                'payments.void' => 'Reverse or void a payment',
                'fees.reports' => 'View fee collection reports',
            ],
            'Results' => [
                'results.view' => 'View computed results',
                'results.enter' => 'Enter continuous assessment and exam scores',
                'results.compute' => 'Compute term results and positions',
                'results.publish' => 'Publish results to students',
            ],
            /*
            | The "Students & Results" module.
            |
            | Held by the Super Admin alone for now. Each of these pages is a blank
            | placeholder, and handing a capability to a role before the page that
            | uses it has been designed would spread access without anybody deciding
            | it should be spread. Assign them as each page is built.
            */
            'Students & Results' => [
                'results.check' => "Look up any student's result on their behalf",
                'results.analytics' => 'View performance analytics across classes, terms and years',
                'results.pins' => 'Generate and manage result-checking PINs',
                'employees.manage' => 'Manage employee records',
                'attendance.manage' => 'Record and correct attendance',
                'alumni.manage' => 'Manage the alumni register',
            ],
            'Messaging' => [
                'sms.view' => 'View the SMS log',
                'sms.send' => 'Send and resend text messages',
                'sms.templates' => 'Edit the wording of text messages',
            ],
            'Portal' => [
                'portal.access' => 'Sign in to the student/parent portal',
                'portal.view-own-results' => 'View own or child results',
                'portal.view-own-fees' => 'View own or child fees and receipts',
            ],
            'Administration' => [
                'settings.manage' => 'Manage school settings and numbering',
                'users.manage' => 'Manage staff accounts and roles',
                'academics.manage' => 'Manage sessions, terms, classes and subjects',
                'reports.view' => 'View platform-wide reports',
                'audit.view' => 'View the activity log',
            ],
        ];
    }

    /** Flat list of every permission name. */
    public static function all(): array
    {
        return array_merge(...array_values(array_map('array_keys', self::groups())));
    }

    /**
     * Role => permissions. `*` means every permission (Super Admin).
     */
    public static function roles(): array
    {
        return [
            'Super Admin' => '*',

            'Exam Officer' => [
                'admissions.view',
                'admissions.letters',
                'admissions.resit',
                'exams.view', 'exams.manage',
                'scores.view', 'scores.enter', 'scores.import', 'scores.verify', 'scores.override',
                'admissions.cutoff', 'admissions.decide', 'admissions.enrol',
                'students.view',
                'results.view', 'results.compute',
                'sms.view', 'sms.send',
                'reports.view',
            ],

            'Admission Officer' => [
                'admissions.view', 'admissions.create', 'admissions.update',
                'admissions.approve', 'admissions.export',
                'admissions.letters', 'admissions.resit',
                'students.view',
                'sms.view', 'sms.send',
                'reports.view',
            ],

            'Teacher' => [
                'scores.view', 'scores.enter', 'scores.import',
                'results.view', 'results.enter',
                'students.view',
            ],

            'Bursar / Accounts' => [
                'fees.view', 'fees.manage', 'fees.invoice',
                'payments.record', 'payments.void', 'fees.reports',
                'students.view',
                'admissions.view',
                'sms.view', 'sms.send',
                'reports.view',
            ],

            'Student' => [
                'portal.access', 'portal.view-own-results', 'portal.view-own-fees',
            ],

            'Parent / Guardian' => [
                'portal.access', 'portal.view-own-results', 'portal.view-own-fees',
            ],
        ];
    }

    /** Effective permission list for a role. */
    public static function forRole(string $role): array
    {
        $granted = self::roles()[$role] ?? [];

        return $granted === '*' ? self::all() : $granted;
    }

    public static function groupFor(string $permission): ?string
    {
        foreach (self::groups() as $group => $permissions) {
            if (array_key_exists($permission, $permissions)) {
                return $group;
            }
        }

        return null;
    }
}
