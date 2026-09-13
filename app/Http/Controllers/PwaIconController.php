<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class PwaIconController extends Controller
{
    private const SIZES = [96, 180, 192, 512];

    public function __invoke(int $size): Response
    {
        abort_unless(in_array($size, self::SIZES, true), 404);
        abort_unless(
            function_exists('imagecreatetruecolor'),
            500,
            'PHP GD extension diperlukan untuk menampilkan icon PWA.',
        );

        $image = imagecreatetruecolor($size, $size);
        abort_if($image === false, 500, 'Icon PWA tidak dapat dibuat.');

        imageantialias($image, true);

        $amber = imagecolorallocate($image, 245, 158, 11);
        $slate = imagecolorallocate($image, 30, 41, 59);
        $white = imagecolorallocate($image, 255, 255, 255);

        imagefill($image, 0, 0, $slate);

        $center = (int) round($size / 2);
        $disc = (int) round($size * 0.72);
        imagefilledellipse($image, $center, $center, $disc, $disc, $amber);

        $boxWidth = (int) round($size * 0.36);
        $boxHeight = (int) round($size * 0.26);
        $left = (int) round(($size - $boxWidth) / 2);
        $top = (int) round(($size - $boxHeight) / 2);
        $right = $left + $boxWidth;
        $bottom = $top + $boxHeight;

        imagefilledrectangle($image, $left, $top, $right, $bottom, $slate);
        imagesetthickness($image, max(2, (int) round($size * 0.025)));
        imageline($image, $left, $top, $center, $top - (int) round($size * 0.10), $white);
        imageline($image, $center, $top - (int) round($size * 0.10), $right, $top, $white);
        imageline(
            $image,
            (int) round($size * 0.38),
            (int) round($size * 0.56),
            (int) round($size * 0.62),
            (int) round($size * 0.56),
            $white,
        );

        ob_start();
        $written = imagepng($image, null, 9);
        $binary = ob_get_clean();
        imagedestroy($image);

        abort_if($written === false || ! is_string($binary), 500, 'Icon PWA tidak dapat dirender.');

        return response($binary, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Length' => (string) strlen($binary),
        ]);
    }
}
