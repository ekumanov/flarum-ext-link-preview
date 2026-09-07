<?php

namespace Ekumanov\LinkPreview\Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * A real local disk in a temp directory. Using Laravel's own adapter rather
 * than a hand-written double means the store's exists/put/url/files behaviour
 * is exercised for real — which matters, because what this class stands in for
 * is the thing that writes attacker-supplied bytes to disk.
 */
final class TempDisk
{
    public static function make(string $root, string $url = 'https://forum.test/assets/icons'): FilesystemAdapter
    {
        // No eager mkdir: the local adapter creates directories when it
        // writes, which is what lets a test point at an unwritable location
        // and watch the store degrade rather than throw.
        $adapter = new LocalFilesystemAdapter($root);

        return new FilesystemAdapter(new Flysystem($adapter), $adapter, ['url' => $url]);
    }

    public static function cleanup(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        foreach (glob($root.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($root);
    }
}
