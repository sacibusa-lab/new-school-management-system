<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sections become something the school owns rather than a letter typed per class.
 *
 * A class has always carried its own `arm`: a plain string, so "A" existed once per
 * class that used it and nowhere on its own. The office's model is the other way
 * round — sections are created first, and a class is then built from its name and
 * one of them, which is what makes JSS1A and JSS1B two classes rather than two
 * spellings. Those letters are turned into rows here and the classes are pointed at
 * them, so nothing already entered is lost.
 *
 * `name` on the class stays: it is the label the school reads ("JSS1A"), written
 * once from the class name and the section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();            // A
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('level_id')
                ->constrained('sections')->nullOnDelete();
        });

        // Every arm already in use becomes a section, in alphabetical order, and the
        // classes using it are pointed at it.
        $arms = DB::table('school_classes')
            ->whereNotNull('arm')
            ->where('arm', '!=', '')
            ->distinct()
            ->orderBy('arm')
            ->pluck('arm');

        foreach ($arms as $index => $arm) {
            $id = DB::table('sections')->insertGetId([
                'name' => $arm,
                'order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('school_classes')->where('arm', $arm)->update(['section_id' => $id]);
        }

        Schema::table('school_classes', function (Blueprint $table) {
            // One class per class-name per section: JSS1A cannot exist twice.
            $table->unique(['level_id', 'section_id'], 'school_class_level_section_unique');
        });

        // Its own call: MySQL runs the whole of one Schema::table as one ALTER, and
        // an index added over a column being dropped in the same statement is the
        // kind of thing that only fails on somebody else's database.
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('arm');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('arm')->nullable()->after('name');
            $table->dropUnique('school_class_level_section_unique');
        });

        // Put the letters back where they came from before the sections go.
        foreach (DB::table('school_classes')->whereNotNull('section_id')->get() as $class) {
            DB::table('school_classes')->where('id', $class->id)->update([
                'arm' => DB::table('sections')->where('id', $class->section_id)->value('name'),
            ]);
        }

        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });

        Schema::dropIfExists('sections');
    }
};
