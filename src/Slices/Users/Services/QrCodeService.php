<?php

namespace LaraSlice\Slices\Users\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

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
        return class_exists(Writer::class);
    }

    public function svg(string $text, int $size = 192): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $renderer = new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd
        );

        return (new Writer($renderer))->writeString($text);
    }
}
