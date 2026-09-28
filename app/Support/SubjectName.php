<?php

namespace App\Support;

/**
 * Deciding whether a heading or a label names a particular paper.
 *
 * Two places have to agree on this: the scoresheet reader, which works out which
 * column holds which paper, and the resolver, which files a parsed row against a
 * paper. If they disagreed, a mark could be read from one column and written to
 * another paper — the one mistake in this pipeline that still looks correct.
 *
 * The rule is deliberately strict about extra words. "Mathematics Score" is
 * Mathematics, because "Score" says nothing about which paper it is. "Further
 * Mathematics" is NOT Mathematics, because "Further" says everything.
 */
class SubjectName
{
    /** Words that say nothing about WHICH paper a heading holds. */
    private const NOISE_WORDS = [
        'score', 'scores', 'mark', 'marks', 'total', 'obtained', 'result', 'results',
        'exam', 'paper', 'test', 'subject', 'ca', 'grade', 'out', 'of', 'the', 'in',
    ];

    /** Lower-cased, punctuation stripped, single-spaced. */
    public static function normalise(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /** Does this heading or label name this paper? */
    public static function matches(?string $header, ?string $name, ?string $code): bool
    {
        $header = self::normalise($header);

        if ($header === '') {
            return false;
        }

        foreach ([$name, $code] as $candidate) {
            $needle = self::normalise($candidate);

            if ($needle === '') {
                continue;
            }

            if ($header === $needle) {
                return true;
            }

            // "Mathematics Score", "Score in Mathematics": the paper is named in
            // full and everything around it is noise.
            if (self::noiseAround($header, $needle)) {
                return true;
            }

            // "Math", "Maths", "Chem" for Mathematics or Chemistry. Kept to a
            // single word and a long opening, so a heading like "Basic Science"
            // can never be folded into "Basic Technology".
            if (! str_contains($header, ' ')
                && strlen($header) >= 4
                && strlen($header) < strlen($needle)
                && str_starts_with($needle, substr($header, 0, 4))) {
                return true;
            }
        }

        return false;
    }

    /** Is the paper named in full, with only noise words around it? */
    private static function noiseAround(string $header, string $needle): bool
    {
        if (! str_contains($header, $needle)) {
            return false;
        }

        $rest = trim(str_replace($needle, ' ', $header));

        foreach (array_filter(explode(' ', $rest)) as $word) {
            // A number is a mark or a total, not a different paper.
            if (is_numeric($word)) {
                continue;
            }

            if (! in_array($word, self::NOISE_WORDS, true)) {
                return false;
            }
        }

        return true;
    }
}
