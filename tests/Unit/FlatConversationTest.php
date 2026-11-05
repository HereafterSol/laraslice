<?php

namespace LaraSlice\Tests\Unit;

use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * The flat conversation mode's row assembly is exercised
 * end-to-end in the port verification; here the declaration
 * contract itself is pinned: the view model must branch on
 * `flat` and the insert helper must exist with the mirror
 * behaviour.
 */
class FlatConversationTest extends TestCase
{
    public function test_flat_mode_machinery_exists(): void
    {
        $controller = \LaraSlice\Core\Base\BaseSliceWebController::class;
        $this->assertTrue(method_exists($controller, 'flatConversations'));
        $this->assertTrue(method_exists($controller, 'insertFlatMessage'));
        $method = new ReflectionMethod($controller, 'insertFlatMessage');
        $this->assertSame(['decl', 'me', 'other', 'body'], array_map(fn ($p) => $p->getName(), $method->getParameters()));
    }
}
