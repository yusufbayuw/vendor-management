<?php

namespace App\Support\Auth;

use RuntimeException;

final class LoginCaptcha
{
    private const SESSION_KEY = 'auth.login_captcha';

    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 5;

    private const TTL_SECONDS = 300;

    /** @return array{hash:string,image_light:string,image_dark:string,expires_at:int} */
    public static function ensure(): array
    {
        $challenge = session()->get(self::SESSION_KEY);

        if (
            is_array($challenge)
            && isset($challenge['hash'], $challenge['image_light'], $challenge['image_dark'], $challenge['expires_at'])
            && ((int) $challenge['expires_at'] > now()->timestamp)
        ) {
            return $challenge;
        }

        return self::refresh();
    }

    /** @return array{hash:string,image_light:string,image_dark:string,expires_at:int} */
    public static function refresh(): array
    {
        $answer = self::generateAnswer();

        $challenge = [
            'hash' => self::hash($answer),
            'image_light' => self::renderImage($answer, dark: false),
            'image_dark' => self::renderImage($answer, dark: true),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ];

        session()->put(self::SESSION_KEY, $challenge);

        return $challenge;
    }

    public static function lightImageDataUri(): string
    {
        return self::ensure()['image_light'];
    }

    public static function darkImageDataUri(): string
    {
        return self::ensure()['image_dark'];
    }

    public static function validate(?string $answer): bool
    {
        $challenge = session()->get(self::SESSION_KEY);

        if (
            ! is_array($challenge)
            || ! isset($challenge['hash'], $challenge['expires_at'])
            || ((int) $challenge['expires_at'] <= now()->timestamp)
            || blank($answer)
        ) {
            self::refresh();

            return false;
        }

        $isValid = hash_equals(
            (string) $challenge['hash'],
            self::hash((string) $answer),
        );

        if ($isValid) {
            session()->forget(self::SESSION_KEY);

            return true;
        }

        self::refresh();

        return false;
    }

    private static function generateAnswer(): string
    {
        $answer = '';
        $maxIndex = strlen(self::CHARSET) - 1;

        for ($index = 0; $index < self::LENGTH; $index++) {
            $answer .= self::CHARSET[random_int(0, $maxIndex)];
        }

        return $answer;
    }

    private static function hash(string $answer): string
    {
        return hash_hmac(
            'sha256',
            strtoupper(trim($answer)),
            (string) config('app.key', 'vendor-management-login-captcha'),
        );
    }

    private static function renderImage(string $answer, bool $dark): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PHP GD extension diperlukan untuk menampilkan captcha login.');
        }

        $sourceWidth = 150;
        $sourceHeight = 32;
        $targetWidth = 300;
        $targetHeight = 64;

        $source = imagecreatetruecolor($sourceWidth, $sourceHeight);
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (($source === false) || ($target === false)) {
            throw new RuntimeException('Captcha login gagal membuat kanvas gambar.');
        }

        if ($dark) {
            $background = imagecolorallocate($source, 24, 24, 27);
            $text = imagecolorallocate($source, 250, 250, 250);
            $line = imagecolorallocate($source, 82, 82, 91);
            $dot = imagecolorallocate($source, 113, 113, 122);
        } else {
            $background = imagecolorallocate($source, 248, 250, 252);
            $text = imagecolorallocate($source, 30, 41, 59);
            $line = imagecolorallocate($source, 148, 163, 184);
            $dot = imagecolorallocate($source, 100, 116, 139);
        }

        imagefilledrectangle($source, 0, 0, $sourceWidth, $sourceHeight, $background);

        for ($index = 0; $index < 3; $index++) {
            imageline(
                $source,
                random_int(0, $sourceWidth),
                random_int(0, $sourceHeight),
                random_int(0, $sourceWidth),
                random_int(0, $sourceHeight),
                $line,
            );
        }

        for ($index = 0; $index < 90; $index++) {
            imagesetpixel(
                $source,
                random_int(0, $sourceWidth - 1),
                random_int(0, $sourceHeight - 1),
                $dot,
            );
        }

        foreach (str_split($answer) as $index => $character) {
            imagestring(
                $source,
                5,
                17 + ($index * 23),
                8 + random_int(-2, 2),
                $character,
                $text,
            );
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        imagepng($target, null, 6);
        $binary = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (! is_string($binary)) {
            throw new RuntimeException('Captcha login gagal merender gambar.');
        }

        return 'data:image/png;base64,'.base64_encode($binary);
    }
}
