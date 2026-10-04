<?php

namespace App\Support;

use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * The class filter, as one list of choices rather than two boxes.
 *
 * A class is a year group and a section together — JSS1A, neither JSS1 nor A — so asking
 * for one in two goes asks the office to say a single thing twice, with a filter button
 * between the halves. Every screen that narrows by class draws the same control from here.
 *
 * The list is grouped by year group rather than written flat, because the year group is a
 * heading and spelling it into six labels makes six things to read where there is one. The
 * arms are named by their letter alone for the same reason: the heading above them has
 * already said which year group they belong to.
 *
 * An answer travels as two numbers in one parameter, `level:section`, because that is what
 * one control can carry. Two things about it are deliberate:
 *
 *  - **Section 0 is the year group whole.** "All of JSS1" is a question the office asks
 *    across the counter, and a list of arms cannot express it.
 *  - **An answer nobody offered is not an error.** A value typed into the address bar, or
 *    left over from the two boxes this replaced, is read as what it does say rather than
 *    refused — the filter still narrows by whichever half of it makes sense.
 */
class ClassOptions
{
    /** What the two numbers in an answer are separated by. */
    private const SEPARATOR = ':';

    /**
     * Every class the school runs, under the year group it belongs to.
     *
     * Built from the classes that exist rather than from every pairing of a year group and
     * a section, so a year group running three arms offers three and one running five
     * offers five.
     *
     * @return array<string,array<string,string>> year group name => [answer => label]
     */
    public static function grouped(): array
    {
        $options = [];

        foreach (self::classes() as $class) {
            $level = $class->level;
            $section = $class->section;

            if ($level === null) {
                continue;
            }

            $options[$level->name] ??= [self::answer($level->id, 0) => 'All of '.$level->name];

            if ($section !== null) {
                $options[$level->name][self::answer($level->id, $section->id)] = $section->name;
            }
        }

        return $options;
    }

    /**
     * The year group and the section an answer stands for.
     *
     * 0 on either side means "all of them", which is also what an answer that says nothing
     * gives — so an absent filter and a filter asking for everybody are the same thing.
     *
     * @return array{level:int,section:int}
     */
    public static function parse(?string $answer): array
    {
        [$level, $section] = array_pad(explode(self::SEPARATOR, (string) $answer, 2), 2, '0');

        return ['level' => (int) $level, 'section' => (int) $section];
    }

    /**
     * How a pair of numbers is written, which is how a control recognises its own choice.
     */
    public static function answer(int $level, int $section): string
    {
        return $level.self::SEPARATOR.$section;
    }

    /**
     * The classes, in the order the school puts them in.
     *
     * Sorted here rather than in SQL: the order of a class is the order of two other
     * tables, and one read of a few dozen rows is easier to follow than the joins it would
     * take to ask the database for the same thing.
     *
     * @return Collection<int,SchoolClass>
     */
    private static function classes(): Collection
    {
        return SchoolClass::query()
            ->whereHas('level', fn ($level) => $level->active())
            ->with(['level', 'section'])
            ->get()
            ->sortBy(fn (SchoolClass $class) => sprintf(
                '%03d-%03d-%s',
                $class->level?->order ?? 999,
                $class->section?->order ?? 999,
                $class->name,
            ));
    }
}
