<?php

namespace LaraSlice\Support;

final class Directory
{
    /**
     * Delete a directory tree without following symbolic links: a linked directory is
     * never entered, and a $path that is itself a link is left alone.
     */
    public static function remove(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
