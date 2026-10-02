<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * How the settings page is arranged.
 *
 * The page used to be thrown at the screen in whatever order the database gave
 * it back — `orderBy('group')->orderBy('key')` — which is alphabetical twice
 * over. So the office saw Admissions, then School branding, then Fees, then
 * Admission letters, then SMS, then Numbering, and inside the school's own
 * branding the first field was **Address**, because an address sorts before a
 * name. The deliberate order the controller declared was never used for anything
 * but the heading text.
 *
 * Arrangement is knowledge about the school, not about the data, so it lives
 * here — the same way Permissions holds the role map — and the settings table
 * needs no column for it.
 *
 * Nothing here is authoritative about what exists: a setting the database holds
 * but this file has not caught up with is still shown, appended after the ones
 * that were listed. A setting that silently vanished from the page would be far
 * worse than one shown in the wrong place.
 */
class SettingLayout
{
    /**
     * The order the groups are shown in, and what each is called.
     *
     * Who we are, then the numbers we hand out, then the admission journey, then
     * the money, then the results. This is the order a school sets itself up in.
     *
     * It is one list whatever page a group ends up on — see PAGES for that.
     */
    public const GROUPS = [
        'branding' => 'School branding',
        'numbering' => 'Numbering',
        'admissions' => 'Admissions',
        'letters' => 'Admission letters',
        'messaging' => 'Text messages (SMS)',
        'fees' => 'Fees',
        'results' => 'Results',
        'general' => 'General',
    ];

    /**
     * Which groups are set up on a page of their own.
     *
     * Admissions and the letter that goes out with an offer are a sitting's work:
     * the office opens the application, sets the cutoff and the fee, and writes the
     * letter it will send. On one long page that is something to scroll past on the
     * way to the school's logo, which is how the office found it.
     *
     * A group not named here — including one this file has never heard of — belongs
     * to the general page, so nothing can be saved into invisibility.
     */
    public const PAGES = [
        'admissions' => ['admissions', 'letters'],
    ];

    /**
     * The order fields are shown in inside each group.
     *
     * A prefix comes before its padding, a switch comes before the credentials it
     * turns on, and the sentence a parent reads comes before the signature under
     * it.
     */
    public const FIELDS = [
        'branding' => [
            'school_name',
            'school_motto',
            'school_tagline',
            'contact_address',
            'contact_phone',
            'contact_email',
            'school_website',
            'school_logo',
            'school_favicon',
            // Last of the branding, because it is the picture that carries all of
            // the above on the documents that leave the school.
            'letterhead_image',
        ],

        // Two number series, each prefix-then-digits, so the pairs read together
        // rather than sorting apart.
        'numbering' => [
            'admission_number_prefix',
            'admission_number_padding',
            'student_number_prefix',
            'student_number_padding',
            'invoice_prefix',
            'receipt_prefix',
        ],

        'admissions' => [
            'default_cutoff_mark',
            'application_fee',
            'entrance_exam_subjects',
            'registration_open',
            'resit_enabled',
        ],

        'letters' => [
            'admission_letter_title',
            'admission_letter_body',
            'admission_letter_signatory',
            'admission_letter_signatory_title',
            // The picture of the signature, under the name and title it belongs to.
            'signature_image',
            'admission_letter_note',
        ],

        'messaging' => [
            'sms_enabled',
            'termii_sender_id',
            'termii_channel',
            'termii_api_key',
        ],

        'fees' => [
            'currency',
            'currency_symbol',
            'invoice_due_days',
        ],

        'results' => [
            'ca_max_total',
            'exam_max_total',
        ],
    ];

    /**
     * Fields that take a whole row whatever their type.
     *
     * A whole sentence, or an address, looks wrong squeezed into half a row with
     * a gap beside it. `text` and `image` settings already take the full width.
     */
    public const WIDE = [
        'school_tagline',
        'contact_address',
        'admission_letter_note',
    ];

    public static function headingFor(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst($group);
    }

    /** The page a group is set up on. Anything not declared belongs to the general one. */
    public static function pageFor(string $group): string
    {
        foreach (self::PAGES as $page => $groups) {
            if (in_array($group, $groups, true)) {
                return $page;
            }
        }

        return 'general';
    }

    /** Whether a field should span the row rather than sit in one column. */
    public static function isWide(string $key): bool
    {
        return in_array($key, self::WIDE, true);
    }

    /**
     * The whole page, in the order it is drawn.
     *
     * With no page named, every group is drawn — which is what the tests of the
     * arrangement want, and what a caller showing everything would use. Naming one
     * narrows it to that page's groups.
     *
     * @param  Collection<string,Collection<int,Setting>>  $settings  grouped by group key
     * @return array<int,array{key:string,label:string,items:Collection<int,Setting>}>
     */
    public static function arrange(Collection $settings, ?string $page = null): array
    {
        return self::groupOrder($settings->keys())
            ->when(
                $page !== null,
                fn (Collection $groups) => $groups->filter(fn (string $group) => self::pageFor($group) === $page),
            )
            ->values()
            ->map(fn (string $group) => [
                'key' => $group,
                'label' => self::headingFor($group),
                'items' => self::fieldsIn($settings->get($group, collect()), $group),
            ])
            ->all();
    }

    /**
     * Which groups to draw, in order.
     *
     * Declared groups that hold nothing are dropped — an empty "General" heading
     * is furniture. Anything the database has and this file does not is kept and
     * put last, so a new setting can never be saved into invisibility.
     *
     * @param  Collection<int,string>  $present
     * @return Collection<int,string>
     */
    public static function groupOrder(Collection $present): Collection
    {
        $declared = array_keys(self::GROUPS);

        return collect($declared)
            ->intersect($present)
            ->merge($present->diff($declared))
            ->values();
    }

    /**
     * One group's fields, in order.
     *
     * @param  Collection<int,Setting>  $items
     * @return Collection<int,Setting>
     */
    public static function fieldsIn(Collection $items, string $group): Collection
    {
        $order = self::FIELDS[$group] ?? [];
        $byKey = $items->keyBy('key');

        $listed = collect($order)
            ->map(fn (string $key) => $byKey->get($key))
            ->filter()
            ->values();

        // Whatever is left keeps its alphabetical order rather than being lost.
        $rest = $items
            ->reject(fn ($setting) => in_array($setting->key, $order, true))
            ->values();

        return $listed->merge($rest);
    }
}
