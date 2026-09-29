<?php

namespace App\Support;

/**
 * Comparing the surname a parent types with the one on the record.
 *
 * The public lookups are guarded by a number and a surname, and the number is the
 * weak half: SAC-00001, SAC-00002, SAC/2026/001 … can simply be counted through.
 * The surname is the half that has to do the work, so it is compared here rather
 * than by eye in three controllers.
 *
 * It is forgiving in the ways a parent can honestly differ — capitalisation, stray
 * spaces, typing the child's whole name into the surname box, and a phone keyboard
 * that cannot produce the dotted vowels of an Igbo name — and strict about the one
 * thing that matters: a surname nobody typed never matches, and neither does a
 * record that has no surname to compare against.
 */
class Surname
{
    /**
     * Letters a phone keyboard often cannot type, folded to their plain form.
     *
     * "Ọkafor" and "Okafor" are the same family; refusing a parent access to their
     * own child's record over a diacritic would be a poor trade for no security.
     */
    private const FOLDED = [
        'ị' => 'i', 'ọ' => 'o', 'ụ' => 'u', 'ṅ' => 'n',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ń' => 'n', 'ç' => 'c', 'ñ' => 'n', 'ý' => 'y',
    ];

    public static function matches(?string $stored, ?string $typed): bool
    {
        $expected = self::words($stored);
        $given = self::words($typed);

        // A record carrying no surname cannot be opened by anything, and an empty
        // box is not a surname. Both must be a refusal, never a pass.
        if ($expected === [] || $given === []) {
            return false;
        }

        // "Okafor", "okafor", "Chidera Okafor" and "Okafor-Bello" all reach a child
        // whose surname is Okafor or Okafor-Bello.
        return array_intersect($expected, $given) !== [];
    }

    /**
     * The name broken into comparable words.
     *
     * Hyphens split as well as spaces, so knowing the first half of a compound
     * surname is enough.
     *
     * @return array<int,string>
     */
    protected static function words(?string $value): array
    {
        $normalised = self::fold($value);

        if ($normalised === '') {
            return [];
        }

        $words = preg_split('/[^a-z0-9]+/', $normalised) ?: [];

        return array_values(array_filter($words, fn (string $word) => $word !== ''));
    }

    /** Lower-case, strip accents, and leave only what a surname is made of. */
    protected static function fold(?string $value): string
    {
        $folded = mb_strtolower(trim((string) $value));

        $folded = strtr($folded, self::FOLDED);

        // Anything still carrying a combining mark (a letter typed as base + accent
        // rather than as one character) loses the mark.
        return (string) preg_replace('/\p{Mn}+/u', '', $folded);
    }
}
