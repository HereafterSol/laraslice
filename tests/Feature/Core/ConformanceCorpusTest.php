<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Core\Conformance\ConformanceRunner;
use LaraSlice\Tests\TestCase;

/**
 * Cross-runtime conformance corpus (Proposal #7): the
 * declaration corpus in conformance/corpus projects through
 * the Laravel runtime's metadata projector and every entry
 * conforms — pinned facts, structure, canonical stability,
 * ladder reproducibility. The committed fidelity table must
 * match a fresh run byte for byte, and a corrupted copy of
 * the corpus must FAIL: a suite that cannot fail is not a
 * conformance suite.
 *
 * Pre-Phase-D the runner and corpus did not exist: every
 * test fails by absence.
 */
final class ConformanceCorpusTest extends TestCase
{
    public function test_the_corpus_conforms_against_the_laravel_runtime(): void
    {
        $runner = new ConformanceRunner;
        $result = $runner->run();

        $this->assertGreaterThanOrEqual(6, $result['entries_total']);
        foreach ($result['entries'] as $slug => $entry) {
            $this->assertTrue(
                $entry['passed'],
                "Corpus entry {$slug} must conform: ".json_encode([
                    'facts' => array_values(array_filter($entry['facts'], static fn ($f) => ! $f['ok'])),
                    'invariants' => array_values(array_filter($entry['invariants'], static fn ($i) => ! $i['ok'])),
                ])
            );
        }
        $this->assertTrue($result['passed']);
        $this->assertSame($result['entries_total'], $result['entries_passed']);
    }

    public function test_the_committed_fidelity_table_is_current(): void
    {
        $runner = new ConformanceRunner;
        $fresh = $runner->fidelityTable($runner->run());
        $committed = file_get_contents(ConformanceRunner::fidelityTablePath());

        $this->assertSame(
            $fresh,
            $committed,
            'conformance/fidelity-table.md has drifted from the runner output — regenerate with: php artisan slice:corpus --write-table'
        );
    }

    public function test_a_corrupted_entry_fails_the_run(): void
    {
        $source = ConformanceRunner::defaultCorpusDir().'/synthetic-relations';
        $dir = sys_get_temp_dir().'/laraslice-corpus-corrupt-'.uniqid();
        mkdir($dir.'/synthetic-relations', 0777, true);
        try {
            copy($source.'/slice.yaml', $dir.'/synthetic-relations/slice.yaml');
            $expected = json_decode((string) file_get_contents($source.'/expected.json'), true);
            $expected['facts']['fields_count'] = 99; // a fact the Heart does not support
            file_put_contents($dir.'/synthetic-relations/expected.json', json_encode($expected));

            $result = (new ConformanceRunner($dir))->run();

            $this->assertFalse($result['passed']);
            $this->assertFalse($result['entries']['synthetic-relations']['passed']);
            $failedFacts = array_column(
                array_filter($result['entries']['synthetic-relations']['facts'], static fn ($f) => ! $f['ok']),
                'fact'
            );
            $this->assertContains('fields_count', $failedFacts);
        } finally {
            $this->app['files']->deleteDirectory($dir);
        }
    }

    public function test_a_declaration_removed_from_a_heart_fails_the_run(): void
    {
        $source = ConformanceRunner::defaultCorpusDir().'/synthetic-relations';
        $dir = sys_get_temp_dir().'/laraslice-corpus-corrupt-'.uniqid();
        mkdir($dir.'/synthetic-relations', 0777, true);
        try {
            // The Heart loses its hasOne relation; the pinned
            // relation set no longer matches the projection.
            $heart = (string) file_get_contents($source.'/slice.yaml');
            $heart = str_replace(
                "      - name: profile\n        type: hasOne\n        model: corpus_profile\n        foreign_key: corpus_widget_id\n",
                '',
                $heart
            );
            file_put_contents($dir.'/synthetic-relations/slice.yaml', $heart);
            copy($source.'/expected.json', $dir.'/synthetic-relations/expected.json');

            $result = (new ConformanceRunner($dir))->run();

            $this->assertFalse($result['passed']);
            $failedFacts = array_column(
                array_filter($result['entries']['synthetic-relations']['facts'], static fn ($f) => ! $f['ok']),
                'fact'
            );
            $this->assertContains('relations (name+kind set)', $failedFacts);
        } finally {
            $this->app['files']->deleteDirectory($dir);
        }
    }

    public function test_the_artisan_command_runs_green(): void
    {
        $this->artisan('slice:corpus')->assertExitCode(0);
    }
}
