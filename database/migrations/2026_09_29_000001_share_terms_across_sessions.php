<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terms belong to every session, not to one.
 *
 * A term was a row of its own per session — "First Term of 2026/2027", "First Term
 * of 2027/2028" — which meant the office rebuilt the same three terms every year,
 * and the same term was a different record depending on the session. A term is
 * universal: First Term is First Term in every session. What differs is when it
 * runs, and that is the only thing left here per session.
 *
 * The dates move to `session_terms`, so next session's dates do not overwrite the
 * ones a report card was signed against. Everything that hangs off a term — an
 * assessment, a term result, a published result, an invoice — already records the
 * session as well, which is what keeps three sessions of results reachable.
 *
 * Existing duplicate terms (one per session, same position) are consolidated onto
 * the earliest row of that position, and everything pointing at a duplicate is
 * repointed before it is removed. Rows for the same student, session and term
 * cannot collide on the way, because duplicates came from *different* sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded because a first attempt that fails partway leaves the table behind,
        // and MySQL will not roll DDL back with the transaction that failed.
        if (! Schema::hasTable('session_terms')) {
            Schema::create('session_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
                $table->foreignId('term_id')->constrained()->cascadeOnDelete();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->timestamps();

                // One set of dates per term per session.
                $table->unique(['academic_session_id', 'term_id']);
            });
        }

        $this->moveDatesAndMergeDuplicates();

        // The foreign key first: MySQL refuses to drop an index a foreign key is
        // using, and the unique index below is exactly the one it is using.
        Schema::table('terms', function (Blueprint $table) {
            $table->dropForeign(['academic_session_id']);
        });

        Schema::table('terms', function (Blueprint $table) {
            $table->dropUnique('terms_academic_session_id_position_unique');
            $table->dropColumn(['academic_session_id', 'starts_on', 'ends_on']);
        });

        Schema::table('terms', function (Blueprint $table) {
            // One First Term, one Second Term, one Third Term — everywhere.
            $table->unique('position');
        });

        // Every session has every term, including the ones that existed before this
        // migration, so a session's terms are complete without anybody rebuilding
        // the list.
        $this->attachEveryTermToEverySession();
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropUnique(['position']);
            $table->foreignId('academic_session_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });

        // The dates go back onto the term, taking the current session's copy: the
        // old shape could not hold more than one set, which is why it changed.
        $current = DB::table('academic_sessions')->where('is_current', true)->value('id')
            ?? DB::table('academic_sessions')->orderBy('id')->value('id');

        DB::table('terms')->update(['academic_session_id' => $current]);

        foreach (DB::table('session_terms')->where('academic_session_id', $current)->get() as $dates) {
            DB::table('terms')->where('id', $dates->term_id)->update([
                'starts_on' => $dates->starts_on,
                'ends_on' => $dates->ends_on,
            ]);
        }

        DB::table('terms')->whereNull('academic_session_id')->delete();

        Schema::table('terms', function (Blueprint $table) {
            $table->unique(['academic_session_id', 'position']);
        });

        Schema::dropIfExists('session_terms');
    }

    /**
     * Carry each term's dates into the new table, keeping one row per position and
     * repointing anything that pointed at the ones being removed.
     */
    private function moveDatesAndMergeDuplicates(): void
    {
        $terms = DB::table('terms')->orderBy('position')->orderBy('id')->get();

        /** @var array<int,int> position => the term id being kept for it */
        $kept = [];

        foreach ($terms as $term) {
            $keeper = $kept[$term->position] ??= $term->id;

            if ($term->academic_session_id) {
                DB::table('session_terms')->insertOrIgnore([
                    'academic_session_id' => $term->academic_session_id,
                    'term_id' => $keeper,
                    'starts_on' => $term->starts_on,
                    'ends_on' => $term->ends_on,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($term->id === $keeper) {
                continue;
            }

            // Everything that named the duplicate now names the term it duplicates.
            foreach (['assessments', 'term_results', 'result_publications', 'invoices', 'fee_structures'] as $table) {
                if (Schema::hasColumn($table, 'term_id')) {
                    DB::table($table)->where('term_id', $term->id)->update(['term_id' => $keeper]);
                }
            }

            DB::table('session_terms')->where('term_id', $term->id)->update(['term_id' => $keeper]);
            DB::table('terms')->where('id', $term->id)->delete();
        }
    }

    /** The point of the change: a term exists for every session, not just its own. */
    private function attachEveryTermToEverySession(): void
    {
        $sessions = DB::table('academic_sessions')->pluck('id');
        $terms = DB::table('terms')->pluck('id');

        foreach ($sessions as $sessionId) {
            foreach ($terms as $termId) {
                DB::table('session_terms')->insertOrIgnore([
                    'academic_session_id' => $sessionId,
                    'term_id' => $termId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
