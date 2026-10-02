<?php

namespace App\Support;

/**
 * Phone numbers as the school writes them, and as the login reads them.
 *
 * The office types a number one way on a form, the spreadsheet arrives with
 * another, and the teacher types a third at the sign-in screen — `0803 123 4567`,
 * `+2348031234567`, `2348031234567` and `8031234567` are one and the same line.
 * This folds all of them onto a single stored shape, `08031234567`, so a number
 * only ever has to be compared with itself rather than with every spelling of
 * itself.
 *
 * Only the spellings of a Nigerian number are folded: `+234` (or `234`, or `00234`)
 * in front, and the leading zero that the local form keeps but the international
 * form drops. Anything that does not look like one of those — a foreign number, a
 * landline, an extension — is left exactly as it was typed, because guessing at a
 * number we do not recognise is worse than leaving it alone.
 */
final class PhoneNumber
{
    /**
     * The number in the one shape the application stores: digits only, in local
     * form. Null when there is nothing to store.
     */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone ?? '') ?? '';

        if ($digits === '') {
            return null;
        }

        // 00234… is the international prefix written the other way.
        if (str_starts_with($digits, '00234')) {
            $digits = substr($digits, 2);
        }

        // 2348031234567 and 8031234567 are both 08031234567. The country code is
        // only treated as one when what follows is a full local mobile number, so a
        // number that genuinely begins 234 is not mangled.
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) === 10 && in_array($digits[0], ['7', '8', '9'], true)) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * Whether a typed number is the same line as a stored one, whichever way each
     * of them was written.
     */
    public static function matches(?string $stored, ?string $typed): bool
    {
        $stored = self::normalize($stored);

        return $stored !== null && $stored === self::normalize($typed);
    }
}
