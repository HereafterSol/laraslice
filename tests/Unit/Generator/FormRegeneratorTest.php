<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FormRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * The route-prefix guard behind slice:regen-form: staging
 * re-derives route names from manifest tiers, which for
 * ported trees need not match the slice's registered names.
 * The remap keys off the dominant route() prefix, so it must
 * pick the slice's own CRUD prefix out of real blade noise.
 */
class FormRegeneratorTest extends TestCase
{
    public function test_dominant_prefix_is_the_slices_own(): void
    {
        $blade = <<<'BLADE'
            <a href="{{ route('attendances.index') }}">Back</a>
            <form action="{{ route('attendances.store') }}">
            <a href="{{ route('attendances.update', 1) }}">x</a>
            <a href="{{ route('dashboard') }}">home</a>
            BLADE;

        $this->assertSame('attendances', FormRegenerator::dominantRoutePrefix([$blade]));
    }

    public function test_dominant_prefix_null_without_route_calls(): void
    {
        $this->assertNull(FormRegenerator::dominantRoutePrefix(['<p>no routes</p>', '']));
    }

    public function test_dominant_prefix_counts_across_files(): void
    {
        $a = "route('erp_erp_erp_teams.index') route('teams.index')";
        $b = "route('teams.store') route('teams.update')";

        $this->assertSame('teams', FormRegenerator::dominantRoutePrefix([$a, $b]));
    }
}
