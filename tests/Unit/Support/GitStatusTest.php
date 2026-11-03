<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\GitStatus;
use PHPUnit\Framework\TestCase;

/**
 * The Operate Git card's reader: declarations only, read-
 * only, and honest when Git or the repository is absent.
 */
class GitStatusTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (! $this->gitAvailable()) {
            $this->markTestSkipped('git binary not available in this environment');
        }
        $this->root = sys_get_temp_dir().'/ls-git-'.uniqid();
        mkdir($this->root.'/porting/ui-spec', 0777, true);
        mkdir($this->root.'/app/Slices/ERP/Orders', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
    }

    private function gitAvailable(): bool
    {
        $process = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return false;
        }
        stream_get_contents($pipes[1]); // drain first — closing the read end early SIGPIPEs the child
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    private function git(string ...$args): void
    {
        $process = proc_open(array_merge(['git', '-C', $this->root], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), 'git '.implode(' ', $args));
    }

    private function initRepoWithCommittedSlice(): void
    {
        file_put_contents($this->root.'/app/Slices/ERP/Orders/slice.yaml', "name: Orders\n");
        file_put_contents($this->root.'/README.md', "not a declaration\n");
        $this->git('init', '-b', 'main');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        $this->git('add', '-A');
        $this->git('commit', '-m', 'Baseline declarations');
    }

    public function test_a_plain_directory_is_not_a_repository(): void
    {
        $status = GitStatus::forDeclarations($this->root);

        $this->assertFalse($status['available']);
        $this->assertStringContainsString('not a Git repository', $status['reason']);
    }

    public function test_committed_declarations_read_clean_with_a_last_commit(): void
    {
        $this->initRepoWithCommittedSlice();

        $status = GitStatus::forDeclarations($this->root);

        $this->assertTrue($status['available']);
        $this->assertSame('main', $status['branch']);
        $this->assertSame(0, $status['dirty_count']);
        $this->assertSame('Baseline declarations', $status['last_commit']['subject']);
    }

    public function test_only_declaration_changes_count_as_dirty(): void
    {
        $this->initRepoWithCommittedSlice();

        // A non-declaration change must NOT dirty the card.
        file_put_contents($this->root.'/README.md', "changed\n");
        // A modified Heart and an untracked Body must.
        file_put_contents($this->root.'/app/Slices/ERP/Orders/slice.yaml', "name: Orders\nversion: 2\n");
        file_put_contents($this->root.'/porting/ui-spec/orders.ui.yaml', "slice: Orders\n");

        $status = GitStatus::forDeclarations($this->root);

        $this->assertSame(2, $status['dirty_count']);
        $paths = array_column($status['dirty'], 'path');
        $this->assertContains('app/Slices/ERP/Orders/slice.yaml', $paths);
        $this->assertContains('porting/ui-spec/orders.ui.yaml', $paths);
        $this->assertNotContains('README.md', $paths, 'the reader is scoped to the declarations, not the whole tree');
    }
}
