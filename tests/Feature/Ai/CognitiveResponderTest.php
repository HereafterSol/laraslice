<?php

namespace LaraSlice\Tests\Feature\Ai;

use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Tests\TestCase;

/**
 * The built-in responder (no provider connected) must answer
 * the question asked, from live facts, with its sources
 * labeled — and never with the old inventory dump that listed
 * every slice name in one line regardless of the question.
 */
class CognitiveResponderTest extends TestCase
{
    private function respond(string $message): string
    {
        $engine = app(AiEngine::class);
        $ref = new \ReflectionMethod($engine, 'generateCognitiveResponse');
        $context = [
            'framework' => 'LaraSlice',
            'version' => '2.0.2',
            'current_page' => '/admin/projects',
            'active_slices' => [
                'Projects' => ['title' => 'Projects', 'domain' => 'Delivery', 'enabled' => true, 'version' => '1.2.0', 'description' => 'Project delivery and milestones.', 'dependencies' => ['Tasks']],
                'Tasks' => ['title' => 'Tasks', 'domain' => 'Delivery', 'enabled' => true, 'version' => '1.0.0', 'description' => '', 'dependencies' => []],
                'Invoices' => ['title' => 'Invoices', 'domain' => 'Finance', 'enabled' => true, 'version' => '1.1.0', 'description' => '', 'dependencies' => []],
            ],
            'database_metrics' => ['users_total' => 24, 'roles_total' => 5, 'tables_count' => 258, 'audit_logs_total' => 91],
            'studio_capabilities' => [],
        ];

        return $ref->invokeArgs($engine, [$message, $context, ['path' => '/admin/projects'], []]);
    }

    public function test_inventory_is_counts_by_domain_not_a_name_dump(): void
    {
        $reply = $this->respond('What slices do we have?');

        $this->assertStringContainsString('**3 slices** across **2 domains**', $reply);
        $this->assertStringContainsString('**Delivery** — 2 slices', $reply);
        $this->assertStringContainsString('_Source: slice registry (live)._', $reply);
    }

    public function test_domain_question_lists_that_domain(): void
    {
        $reply = $this->respond('Show me the Finance slices');

        $this->assertStringContainsString('**Finance** — 1 slices', $reply);
        $this->assertStringContainsString('Invoices', $reply);
    }

    public function test_named_slice_gets_its_facts(): void
    {
        $reply = $this->respond('Tell me about Projects');

        $this->assertStringContainsString('**Projects**', $reply);
        $this->assertStringContainsString('Project delivery and milestones.', $reply);
        $this->assertStringContainsString('**Depends on:** Tasks', $reply);
        $this->assertStringContainsString('_Source: slice registry (live)._', $reply);
    }

    public function test_health_is_live_counts(): void
    {
        $reply = $this->respond('Is the system healthy?');

        $this->assertStringContainsString('**Users:** 24', $reply);
        $this->assertStringContainsString('**Tables:** 258', $reply);
        $this->assertStringContainsString('_Source: database (live counts)._', $reply);
    }

    public function test_fallback_is_short_honest_and_dump_free(): void
    {
        $reply = $this->respond('Why is the sky blue?');

        $this->assertStringContainsString('built-in assistant', $reply);
        $this->assertStringNotContainsString('Registered Slices:', $reply);
        $this->assertLessThan(900, strlen($reply), 'the fallback stays a short answer, not a screen of inventory');
    }
}
