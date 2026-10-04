<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The AI provider was seeded with the four characters `null`.
 *
 * Not "no provider" — the string. It was meant as a stand-in, and it reached the
 * settings page, where the office read `null` in a box labelled Provider and had no
 * way to tell whether the app was broken or nobody had chosen yet.
 *
 * The reader itself was never fooled: it accepts gemini, openai or deepseek and
 * nothing else, so `null` was read as the AI being switched off all along. Only the
 * page was wrong — but the page is the one the office looks at.
 *
 * Cleared rather than defaulted to a provider, because an empty box is what "not
 * chosen yet" looks like. The seeder alone could not have reached a live database:
 * it never writes a value over one that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'ai_provider')
            ->where('value', 'null')
            ->update(['value' => '']);
    }

    /**
     * Nothing to undo. `null` was never a provider, and by the time this could be
     * rolled back the empty value has become how "switched off" is written — putting
     * the placeholder back would switch the reader off for a school that had chosen
     * to leave it off, which is the same thing, and print a word in the box that
     * means nothing to whoever reads it next.
     */
    public function down(): void {}
};
