<?php

namespace LaraSlice\Tests\Unit\Users;

use LaraSlice\Slices\Users\Services\QrCodeService;
use PHPUnit\Framework\TestCase;

class QrCodeServiceTest extends TestCase
{
    public function test_it_renders_an_svg_qr_code(): void
    {
        $service = new QrCodeService;

        $this->assertTrue($service->available());
        $svg = $service->svg('otpauth://totp/LaraSlice:sara%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=LaraSlice');

        $this->assertNotNull($svg);
        $this->assertStringContainsString('<svg', $svg);
    }
}
