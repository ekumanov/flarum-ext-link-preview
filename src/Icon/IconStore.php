<?php

namespace Ekumanov\LinkPreview\Icon;

use Illuminate\Contracts\Filesystem\Cloud;
use Throwable;

/**
 * Keeps site icons on the forum's own disk so readers never fetch them from
 * anyone else.
 *
 * Why this exists: a hot-linked favicon means every reader's browser opens a
 * connection to every domain a discussion links to. That is the same leak this
 * extension refused Google's favicon service over — we had simply spread it
 * across many origins instead of concentrating it in one. Serving the icons
 * ourselves removes it entirely, and removes N DNS lookups and TLS handshakes
 * from every page along with it.
 *
 * Bytes are stored verbatim. Measured across a live install the mean icon is
 * 4.6 KB and 53% of them are `.ico`, which neither GD nor a plain PHP decoder
 * can read — so re-encoding would mean an Imagick dependency for a few hundred
 * kilobytes across the whole forum. Not worth it, and not decoding untrusted
 * images is worth something on its own.
 *
 * SECURITY. These bytes came from an attacker-influenceable URL and are about
 * to be served from the forum's own origin, so:
 *
 *  - Only formats identified by their own magic bytes are stored. The URL's
 *    extension is never consulted — one production row's icon href ends in
 *    `.php`.
 *  - SVG is refused outright and always will be. It is a document format that
 *    can carry script, and served from our origin that script would run in it.
 *    50 of 2370 icons are SVG; those keep the monogram, which is a cheap price
 *    for closing a stored-XSS hole.
 *  - The filename is the SHA-256 of the content plus an extension we chose,
 *    so nothing from the remote host reaches the path, and identical icons
 *    collapse onto one file.
 */
final class IconStore
{
    /**
     * Magic-byte signatures we are willing to re-serve, mapped to the
     * extension we store them under — which is what makes the web server
     * label them correctly on the way back out.
     *
     * @var list<array{ext:string,magic:string,offset:int}>
     */
    private const SIGNATURES = [
        ['ext' => 'png', 'magic' => "\x89PNG\r\n\x1a\n", 'offset' => 0],
        ['ext' => 'gif', 'magic' => 'GIF87a', 'offset' => 0],
        ['ext' => 'gif', 'magic' => 'GIF89a', 'offset' => 0],
        ['ext' => 'jpg', 'magic' => "\xFF\xD8\xFF", 'offset' => 0],
        // ICO: reserved 0, type 1. Type 2 is a cursor and has no business here.
        ['ext' => 'ico', 'magic' => "\x00\x00\x01\x00", 'offset' => 0],
    ];

    /** Smallest plausible icon; anything shorter is a truncated or empty body. */
    private const MIN_BYTES = 24;

    /**
     * @param ?Cloud $disk null when the disk could not be built at all — a
     *                     read-only assets directory makes the local adapter
     *                     throw in its constructor, and an admin in that
     *                     position should get hot-linked icons, not a 500.
     */
    public function __construct(private readonly ?Cloud $disk = null) {}

    public function isAvailable(): bool
    {
        return $this->disk !== null;
    }

    /**
     * @return string|null the stored filename, or null if these bytes are not
     *                     something we are prepared to serve
     */
    public function store(string $bytes): ?string
    {
        $ext = self::identify($bytes);
        if ($ext === null) {
            return null;
        }

        if ($this->disk === null) {
            return null;
        }

        $name = hash('sha256', $bytes).'.'.$ext;

        try {
            // Content-addressed, so an existing file is byte-identical and
            // rewriting it would only churn the disk.
            if (! $this->disk->exists($name)) {
                $this->disk->put($name, $bytes);
            }
        } catch (Throwable) {
            return null; // read-only assets dir, full disk — degrade, never fail a fetch
        }

        return $name;
    }

    public function url(string $name): ?string
    {
        if ($this->disk === null) {
            return null;
        }

        try {
            return $this->disk->url($name);
        } catch (Throwable) {
            return null;
        }
    }

    public function exists(string $name): bool
    {
        if ($this->disk === null) {
            return false;
        }

        try {
            return $this->disk->exists($name);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(string $name): void
    {
        if ($this->disk === null) {
            return;
        }

        try {
            $this->disk->delete($name);
        } catch (Throwable) {
            // nothing to do — prune is advisory
        }
    }

    /**
     * @return list<string> every stored filename, for orphan pruning
     */
    public function all(): array
    {
        if ($this->disk === null) {
            return [];
        }

        try {
            return array_values(array_map('basename', $this->disk->files()));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The stored extension is decided by the content and nothing else.
     */
    public static function identify(string $bytes): ?string
    {
        if (strlen($bytes) < self::MIN_BYTES) {
            return null;
        }

        foreach (self::SIGNATURES as $sig) {
            if (substr($bytes, $sig['offset'], strlen($sig['magic'])) === $sig['magic']) {
                return $sig['ext'];
            }
        }

        // WebP is a RIFF container: "RIFF" <4-byte length> "WEBP".
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }
}
