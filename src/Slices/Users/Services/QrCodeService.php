<?php

namespace LaraSlice\Slices\Users\Services;

/**
 * Renders QR codes on the server so TOTP secrets never leave the application.
 *
 * Requires the optional bacon/bacon-qr-code package. Without it, svg() returns
 * null and views fall back to the manual setup key and otpauth:// link.
 */
class QrCodeService
{
    public function available(): bool
    {
        return class_exists(\BaconQrCode\Writer::class);
    }

    public function svg(string $text, int $size = 192): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 1),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );

        return (new \BaconQrCode\Writer($renderer))->writeString($text);
    }
}
