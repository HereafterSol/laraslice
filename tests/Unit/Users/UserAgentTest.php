<?php

namespace LaraSlice\Tests\Unit\Users;

use LaraSlice\Slices\Users\Support\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function agents(): array
    {
        return [
            'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'iOS', 'Safari', 'Mobile'],
            'iPad Chrome' => ['Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/124.0 Mobile/15E148 Safari/604.1', 'iOS', 'Chrome', 'Tablet'],
            'Android Chrome' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36', 'Android', 'Chrome', 'Mobile'],
            'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 Edg/124.0', 'Windows', 'Edge', 'Desktop'],
            'Windows Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 OPR/110.0', 'Windows', 'Opera', 'Desktop'],
            'macOS Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', 'macOS', 'Safari', 'Desktop'],
            'Linux Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0', 'Linux', 'Firefox', 'Desktop'],
            'empty' => ['', 'Unknown', 'Unknown', 'Desktop'],
        ];
    }

    #[DataProvider('agents')]
    public function test_parse(string $ua, string $os, string $browser, string $deviceType): void
    {
        $this->assertSame(['os' => $os, 'browser' => $browser, 'deviceType' => $deviceType], UserAgent::parse($ua));
    }
}
