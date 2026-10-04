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
        'fees' => 'Fees',
        'results' => 'Results',
        // The accounts the school holds with other people. One group per
        // provider, so each is a card of its own rather than a run of keys with
        // somebody else's name on them — see PAGES for the page they share.
        'api_paystack' => 'Paystack — fees collection',
        'api_termii' => 'Termii — text messages',
        // Not a company's name, because there is no one company: the school may hold
        // its key with Gemini, OpenAI or DeepSeek, and the card lets it say which. A
        // heading naming one of the three contradicts the field underneath it.
        'api_deepseek' => 'AI — reading scoresheets',
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
     * The API keys are the other page. They are not a setting the office changes
     * as it goes about the day — they are set once, when the school opens its
     * account with a provider, and then not looked at again. Kept on the general
     * page they were three company names to scroll past to reach the school's own
     * logo.
     *
     * Each provider's whole arrangement then lives together on that page. The switch
     * that turns text messages on used to sit on the general page while the key that
     * sends them sat here, a page apart: the office could read that text messages were
     * on without seeing whether there was a key to send them with, and could paste a
     * key in without noticing the switch was off. Turning text messages off is a
     * decision about Termii, so it is a field on Termii's card.
     *
     * A group not named here — including one this file has never heard of — belongs
     * to the general page, so nothing can be saved into invisibility.
     */
    public const PAGES = [
        'admissions' => ['admissions', 'letters'],
        'api' => ['api_paystack', 'api_termii', 'api_deepseek'],
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

        // Each provider reads the way it is set up: what the school is given to
        // identify it, then the secret that proves it.
        'api_paystack' => [
            'paystack_public_key',
            'paystack_secret_key',
            'paystack_dva_bank',
        ],

        // The switch first, then the credentials it turns on. Read top to bottom
        // the card answers “do text messages leave this school?” before it shows
        // the office the key that would send them.
        'api_termii' => [
            'sms_enabled',
            'termii_api_key',
            'termii_sender_id',
            'termii_channel',
        ],

        'api_deepseek' => [
            'ai_provider',
            'ai_api_key',
            'ai_model',
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

    /**
     * What a group is called when it is spoken about in a sentence.
     *
     * The heading says "Paystack — fees collection", which is right across the top of a
     * card and wrong in the middle of "… settings saved." The API page saves one card at
     * a time now, so the page has to be able to say which one it was.
     *
     * A group not named here has no brief, and the message falls back to the plain one.
     */
    public const BRIEF = [
        'api_paystack' => 'Paystack',
        'api_termii' => 'Termii',
        'api_deepseek' => 'AI reader',
    ];

    /**
     * The choices a field offers, where the field is a list rather than a box.
     *
     * A text box for a value the app recognises three spellings of is a box that can be
     * filled in wrongly, and one of these answers wrongly in a way nobody notices: a
     * provider it does not know is read as the AI being switched off, so a typo surfaces
     * when a scoresheet refuses to load rather than when it is typed. The bank that
     * issues virtual account numbers is the same argument, and its list is Paystack's
     * rather than this file's — see PaystackProvider::getVirtualAccountBanks.
     *
     * An empty key is offered where blank is an answer rather than an omission.
     */
    public const OPTIONS = [
        'ai_provider' => [
            '' => 'Switched off — every sheet is typed in by hand',
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI',
            'deepseek' => 'DeepSeek (text only — cannot read a photograph)',
        ],
    ];

    public static function headingFor(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst($group);
    }

    /** The short name for a group, or null where it has none. */
    public static function briefName(string $group): ?string
    {
        return self::BRIEF[$group] ?? null;
    }

    /**
     * The choices a field offers, keyed by setting key.
     *
     * @return array<string,string>
     */
    public static function options(string $key): array
    {
        return self::OPTIONS[$key] ?? [];
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
