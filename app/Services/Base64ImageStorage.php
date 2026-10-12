<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Base64ImageStorage
{
    public const MAX_IMAGE_BYTES = 300 * 1024;

    public function store(
        ?string $dataUri,
        string $directory,
        string $field,
        string $imageLabel
    ): ?string {
        if (empty($dataUri)) {
            return null;
        }

        // Data URI header aur Base64 data ko alag karta hai.
        $separatorPosition = strpos($dataUri, ',');

        if (
            $separatorPosition === false
            || ! preg_match(
                '/\Adata:image\/[A-Za-z0-9.+-]+;base64\z/i',
                substr($dataUri, 0, $separatorPosition)
            )
        ) {
            throw ValidationException::withMessages([
                $field => "The {$imageLabel} must be a valid Base64 data URI image.",
            ]);
        }

        $encodedImage = substr($dataUri, $separatorPosition + 1);
        $maxEncodedLength = intdiv(self::MAX_IMAGE_BYTES + 2, 3) * 4;

        // Bari image ko decode karne se pehle reject karta hai.
        if (strlen($encodedImage) > $maxEncodedLength) {
            $this->throwTooLarge($field, $imageLabel);
        }

        if ($encodedImage === '' || ! preg_match('/\A[A-Za-z0-9+\/=]+\z/', $encodedImage)) {
            throw ValidationException::withMessages([
                $field => "The {$imageLabel} must contain valid Base64 data.",
            ]);
        }

        $imageContents = base64_decode($encodedImage, true);

        if ($imageContents === false) {
            throw ValidationException::withMessages([
                $field => "The {$imageLabel} must contain valid Base64 data.",
            ]);
        }

        if (strlen($imageContents) > self::MAX_IMAGE_BYTES) {
            $this->throwTooLarge($field, $imageLabel);
        }

        // Asal file content se tasdeeq karta hai ke file image hai.
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($imageContents);

        if (! str_starts_with($mimeType, 'image/')) {
            throw ValidationException::withMessages([
                $field => "The uploaded {$imageLabel} must be an image.",
            ]);
        }

        $extension = preg_replace('/[^a-z0-9]/', '', strtolower(substr($mimeType, strlen('image/'))));

        if ($extension === '') {
            throw ValidationException::withMessages([
                $field => "The uploaded {$imageLabel} type is not supported.",
            ]);
        }

        $path = trim($directory, '/').'/'.str()->uuid().'.'.$extension;

        if (! Storage::disk('public')->put($path, $imageContents)) {
            throw new \RuntimeException("Failed to store the {$imageLabel}.");
        }

        return $path;
    }

    private function throwTooLarge(string $field, string $imageLabel): never
    {
        throw ValidationException::withMessages([
            $field => "The {$imageLabel} must be no larger than 300 KB.",
        ]);
    }
}
