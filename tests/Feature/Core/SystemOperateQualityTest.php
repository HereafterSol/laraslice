<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Support\SystemOperate;
use LaraSlice\Tests\TestCase;

/**
 * The Operate Quality card's data contract: evidence,
 * drift and history blocks always present, each honestly
 * available or not. (In the test app there is no Mind and
 * no recorded evidence, so drift is unavailable and
 * evidence reads zero — the panel must say exactly that,
 * never crash or invent numbers.)
 */
class SystemOperateQualityTest extends TestCase
{
    public function test_quality_shape_and_honest_empties(): void
    {
        $quality = SystemOperate::quality();

        $this->assertArrayHasKey('evidence', $quality);
        $this->assertArrayHasKey('drift', $quality);
        $this->assertArrayHasKey('history', $quality);

        $this->assertTrue($quality['evidence']['available']);
        $this->assertSame(0, $quality['evidence']['checks']);
        $this->assertSame([], $quality['evidence']['failed']);

        $this->assertFalse($quality['drift']['available'], 'no Mind in the test app — drift must report unavailable');
    }
}
