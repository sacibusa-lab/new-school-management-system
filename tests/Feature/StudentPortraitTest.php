<?php

namespace Tests\Feature;

use App\Services\Students\StudentPortraitService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The round slot on a printed slip.
 *
 * DomPDF draws a rounded border but does not clip a picture to it, so a portrait printed in
 * that slot has to have been cropped to a circle before it is handed over — otherwise the
 * ring is drawn around a square face. What makes it round is the transparency in the PNG,
 * which is the part that would break silently: a crop that forgot to clear the corners still
 * produces an image, and still looks like a photograph, until it is on paper.
 */
class StudentPortraitTest extends TestCase
{
    public function test_a_photograph_is_cropped_to_a_circle(): void
    {
        $this->putPortrait('photos/students/child.png', 40);

        $data = app(StudentPortraitService::class)->circled('photos/students/child.png', 40);

        $this->assertNotNull($data, 'a readable photograph should come back as something printable');

        $circle = imagecreatefromstring((string) base64_decode((string) str($data)->after('base64,'), true));

        $this->assertNotFalse($circle, 'the result should be an image');

        // The corners are outside the circle and must be clear, or the square edge prints.
        foreach ([[0, 0], [39, 0], [0, 39], [39, 39]] as [$x, $y]) {
            $this->assertSame(
                127,
                (imagecolorat($circle, $x, $y) >> 24) & 0x7F,
                "the corner at $x,$y should be transparent",
            );
        }

        // And the middle is the photograph, untouched: this one is drawn a flat red.
        $centre = imagecolorat($circle, 20, 20);

        $this->assertSame(0, ($centre >> 24) & 0x7F, 'the middle should be opaque');
        $this->assertSame(200, ($centre >> 16) & 0xFF, 'the middle should still be the photograph');
        $this->assertSame(40, ($centre >> 8) & 0xFF);

        imagedestroy($circle);
    }

    public function test_a_square_photograph_is_sized_to_the_slot(): void
    {
        $this->putPortrait('photos/students/child.png', 120);

        $data = app(StudentPortraitService::class)->circled('photos/students/child.png', 150);
        $circle = imagecreatefromstring((string) base64_decode((string) str($data)->after('base64,'), true));

        $this->assertSame(150, imagesx($circle));
        $this->assertSame(150, imagesy($circle));

        imagedestroy($circle);
    }

    public function test_a_child_with_no_photograph_gets_nothing_to_print(): void
    {
        Storage::fake('public');

        $portraits = app(StudentPortraitService::class);

        // Nothing to show, and nothing to complain about: the slip falls back to the initials.
        $this->assertNull($portraits->circled(null));
        $this->assertNull($portraits->circled('photos/students/nobody.png'));
    }

    public function test_a_file_that_is_not_a_picture_gets_nothing_to_print(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('photos/students/child.png', 'this is not a photograph');

        $this->assertNull(app(StudentPortraitService::class)->circled('photos/students/child.png'));
    }

    /**
     * A flat red square, standing in for a passport photograph that has been uploaded.
     */
    protected function putPortrait(string $path, int $side): void
    {
        Storage::fake('public');

        $image = imagecreatetruecolor($side, $side);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        imagedestroy($image);

        Storage::disk('public')->put($path, $png);
    }
}
