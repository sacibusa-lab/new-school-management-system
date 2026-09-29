<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every view has to survive being compiled.
 *
 * A Blade syntax error is invisible until somebody happens to open that page —
 * `view:cache` happily compiles a template whose PHP will not parse, and the
 * test suite stays green while the screen returns a 500. That is exactly how a
 * directive glued to the end of a word ("not passed@if (...)") shipped: Blade
 * only compiles a directive that starts at a word boundary, so the `@if` stayed
 * literal and its `@endif` became an orphan, which is a PHP parse error.
 *
 * This walks every template, compiles it, and parses the result, so the whole
 * class of mistake fails here instead of in front of a parent.
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_every_blade_view_compiles_to_parseable_php(): void
    {
        $views = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'));

        $this->assertGreaterThan(20, $views->count(), 'the views should have been found');

        $compiler = app('blade.compiler');

        $batch = [];

        foreach ($views as $view) {
            $batch[$view->getRelativePathname()] = $compiler->compileString(File::get($view->getPathname()));
        }

        // Linting each view in its own process costs about half a second of
        // process startup on Windows, which is minutes across the whole tree, so
        // they go through the parser together. Each one is wrapped in its own
        // function: concatenated raw, an unclosed block in one template could be
        // closed by another and the whole thing would pass — which is precisely
        // the mistake this test exists to catch.
        $wrapped = [];

        foreach (array_values($batch) as $index => $compiled) {
            $wrapped[] = "<?php function __view_{$index}() { ?>\n" . $compiled . "\n<?php } ?>";
        }

        if ($this->parses(implode("\n", $wrapped), 'batch.php')) {
            $this->assertTrue(true);

            return;
        }

        $broken = [];

        foreach ($batch as $name => $compiled) {
            if (! $this->parses("<?php function __view() { ?>\n" . $compiled . "\n<?php } ?>", 'single.php')) {
                $broken[] = $name;
            }
        }

        $this->assertSame([], $broken, "These views do not compile:\n  " . implode("\n  ", $broken));
    }

    /**
     * Hand the PHP to the real parser, which is the only thing that knows.
     *
     * token_get_all() is not a substitute: it does not reject every syntax error
     * that the parser does, so it would quietly pass a broken view.
     */
    private function parses(string $php, string $fileName): bool
    {
        $directory = storage_path('framework/testing/blade-compile');

        File::ensureDirectoryExists($directory);

        $path = $directory . '/' . $fileName;
        File::put($path, $php);

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

        File::delete($path);

        if (File::isEmptyDirectory($directory)) {
            File::deleteDirectory($directory);
        }

        return $status === 0;
    }
}
