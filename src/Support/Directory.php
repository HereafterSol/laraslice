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

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                self::remove($child);
            } elseif (file_exists($child)) {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    /**
     * Recursively copy a directory tree to a new location.
     */
    public static function copy(string $source, string $destination): bool
    {
        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            return false;
        }

        $dir = opendir($source);
        if ($dir === false) {
            return false;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $srcPath = $source.DIRECTORY_SEPARATOR.$file;
            $dstPath = $destination.DIRECTORY_SEPARATOR.$file;
            if (is_dir($srcPath)) {
                if (! self::copy($srcPath, $dstPath)) {
                    closedir($dir);

                    return false;
                }
            } else {
                if (! copy($srcPath, $dstPath)) {
                    closedir($dir);

                    return false;
                }
            }
        }
        closedir($dir);

        return true;
    }
}
