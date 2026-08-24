<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountAssignment;
use App\Models\User;
use RuntimeException;

/**
 * Two defenses against employees reusing an old activation's proof
 * photo, applied to every submission:
 *
 *   1. hash the ORIGINAL uploaded bytes with SHA-256 so an exact re-
 *      upload of a saved file is caught deterministically. The hash is
 *      computed BEFORE watermarking — otherwise every watermark bumps
 *      the hash and duplicates would never match.
 *
 *   2. burn an ASCII watermark (employee id + account id + timestamp)
 *      into the image itself so if the same photo is ever re-used the
 *      wrong employee's / date's stamp is visible right on it.
 *
 * ASCII-only because GD's built-in bitmap fonts don't render Arabic —
 * the goal is a permanent, human-readable trace, not localised copy.
 * A determined re-encoder can defeat SHA-256 alone (screenshot the
 * photo → new bytes → new hash); the watermark is the second wall.
 */
class ProofImageProcessor
{
    /**
     * Compute the SHA-256 hex hash of the file at $path, then overwrite
     * the file with a watermarked version. Returns the pre-watermark
     * hash so the caller can store it for duplicate detection.
     */
    public function process(string $path, User $employee, AccountAssignment $assignment): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read proof image at {$path}");
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Failed to hash proof image');
        }

        $this->watermark($path, $this->watermarkText($employee, $assignment));

        return $hash;
    }

    private function watermarkText(User $employee, AccountAssignment $assignment): string
    {
        return sprintf(
            'EMP:%d  ACC:#%d  %s',
            $employee->id,
            $assignment->account_id,
            now()->format('Y-m-d H:i'),
        );
    }

    /**
     * In-place watermark using GD only. Detects image type from the
     * file header (not the extension — phone-camera uploads sometimes
     * lie about extension), draws a semi-transparent black bar with
     * white ASCII text along the bottom edge, and writes back as JPEG.
     */
    private function watermark(string $path, string $text): void
    {
        $info = @getimagesize($path);
        if ($info === false) {
            // Not a recognisable image — leave the file alone rather than
            // corrupting a proof the supervisor still needs to see.
            return;
        }

        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default        => false,
        };

        if ($img === false) {
            return;
        }

        try {
            $width = imagesx($img);
            $height = imagesy($img);

            // Font 5 is the largest built-in GD bitmap font (9x15px). The
            // bar height fits it with a little breathing room on top/bottom.
            $font = 5;
            $charW = imagefontwidth($font);
            $charH = imagefontheight($font);
            $textW = $charW * strlen($text);
            $barH = $charH + 8;

            // Semi-transparent black bar spans the full width along the
            // bottom edge — deters cropping just to lose the mark.
            $barY = max(0, $height - $barH);
            $barColor = imagecolorallocatealpha($img, 0, 0, 0, 40);
            imagefilledrectangle($img, 0, $barY, $width, $height, $barColor);

            $textColor = imagecolorallocate($img, 255, 255, 255);
            $x = max(4, (int) (($width - $textW) / 2));
            $y = $barY + 4;
            imagestring($img, $font, $x, $y, $text, $textColor);

            // Always write JPEG — normalises format so the reviewer's
            // browser doesn't have to worry about which decoder to use,
            // and keeps file sizes predictable.
            imagejpeg($img, $path, 88);
        } finally {
            imagedestroy($img);
        }
    }
}
