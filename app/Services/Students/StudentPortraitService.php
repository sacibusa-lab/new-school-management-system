<?php

namespace App\Services\Students;

use Illuminate\Support\Facades\Storage;

/**
 * A child's photograph, made ready to be printed.
 *
 * On a screen a photograph is cropped to a circle with two words of CSS — `rounded-full` and
 * `object-cover` — and every photograph in the app is shown that way, so the round slot on a
 * slip is where a face is expected. Paper has no such luck: DomPDF does not fetch an image
 * over HTTP, and although it draws a rounded border it does not clip the picture inside it,
 * so a portrait handed to it arrives square, with the ring drawn round it looking like a
 * mistake.
 *
 * So the crop is done here, beside the pixels, once: a square taken from the middle of the
 * photograph, scaled to the size the slip draws it, and then masked to a circle that carries
 * its own transparency in the PNG. DomPDF embeds that as an image with a soft mask, and the
 * corners print as paper.
 *
 * Anything it cannot read — a file that is missing, or a format this build of GD will not
 * open (webp, on some builds) — comes back as null, and the slip falls back to the child's
 * initials, which is what it shows for a child who has no photograph at all.
 */
class StudentPortraitService
{
    /**
     * Ten millimetres at about 380dpi, which is the size the slip draws it: enough that the
     * print is sharp, small enough that a sheet of two hundred of them is not a large file.
     */
    public const PIXELS = 150;

    /**
     * The photograph as a data URI, cropped to a circle.
     *
     * @return string|null null when there is no photograph, or it cannot be read
     */
    public function circled(?string $path, int $pixels = self::PIXELS): ?string
    {
        $source = $this->load($path);

        if ($source === null) {
            return null;
        }

        $masked = $this->cropToCircle($source, $pixels);

        imagedestroy($source);

        ob_start();
        imagepng($masked, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($masked);

        return $png === '' ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * The stored file as a GD image, or null if it is not there or GD will not open it.
     */
    protected function load(?string $path): ?\GdImage
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) Storage::disk('public')->get($path));

        return $image === false ? null : $image;
    }

    /**
     * The middle square of a photograph, scaled, with everything outside the circle cleared.
     *
     * The centre is taken rather than the whole frame because a passport photograph is taller
     * than it is wide: squashing one into a square would stretch the child's face, and a
     * circle taken off-centre cuts an ear in half. Everything in the frame between the middle
     * square and the edge of the circle is transparent, which is what makes it round.
     */
    protected function cropToCircle(\GdImage $source, int $pixels): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor($pixels, $pixels);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        imagefill($canvas, 0, 0, $clear);
        imagecopyresampled(
            $canvas,
            $source,
            0, 0,
            (int) (($width - $side) / 2), (int) (($height - $side) / 2),
            $pixels, $pixels,
            $side, $side,
        );

        $centre = $pixels / 2;
        $radiusSquared = $centre * $centre;

        // Overwriting is off while the corners are cleared, or the transparent pixels would
        // blend with the photograph underneath them instead of replacing it.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        for ($x = 0; $x < $pixels; $x++) {
            for ($y = 0; $y < $pixels; $y++) {
                $dx = $x - $centre + 0.5;
                $dy = $y - $centre + 0.5;

                if (($dx * $dx) + ($dy * $dy) > $radiusSquared) {
                    imagesetpixel($canvas, $x, $y, $clear);
                }
            }
        }

        imagealphablending($canvas, true);

        return $canvas;
    }
}
