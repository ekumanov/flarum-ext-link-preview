<?php

namespace Ekumanov\LinkPreview\Parser;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Extracts Open Graph + Twitter Card metadata from a page's <head>.
 *
 * Output shape matches the `opengraph` JSON column the display layer
 * (PostResourceFields) reads — a stable shape kept so migrating installs see
 * no rendering differences. Twitter Card tags are
 * merged in as fallbacks for missing OG fields — sites that ship only
 * Twitter tags (a real pattern) still produce usable cards.
 *
 * OG groups (og:image followed by og:image:secure_url/width/height) are
 * preserved as nested arrays per the spec — but only when the related tag
 * immediately follows its parent; the parser does not try to reassociate
 * arbitrary ordering, which matches how real sites emit them.
 */
final class OpenGraphParser
{
    /**
     * @return array{
     *   title:?string,
     *   type:?string,
     *   url:?string,
     *   site_name:?string,
     *   description:?string,
     *   images:list<array{url:string,secure_url?:string,alt?:string,width?:int,height?:int,type?:string}>
     * }|null Null if no usable metadata found.
     */
    public function parse(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 interpretation. Without the prepended xml-encoding hint
        // DOMDocument assumes Latin-1 and mangles non-ASCII titles.
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $metas = $xpath->query('//head/meta[@property or @name]');
        if ($metas === false || $metas->length === 0) {
            return null;
        }

        $result = [
            'title' => null,
            'type' => null,
            'url' => null,
            'site_name' => null,
            'description' => null,
            'images' => [],
        ];

        /** @var ?array<string,mixed> $currentImage */
        $currentImage = null;
        $twitter = [];

        foreach ($metas as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }

            $key = strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
            $value = trim($meta->getAttribute('content'));
            if ($key === '' || $value === '') {
                continue;
            }

            if (str_starts_with($key, 'og:')) {
                $this->applyOg(substr($key, 3), $value, $result, $currentImage);
            } elseif (str_starts_with($key, 'twitter:')) {
                $twitter[substr($key, 8)] = $value;
            }
        }

        // Flush any in-progress image group.
        if ($currentImage !== null) {
            $result['images'][] = $currentImage;
        }

        // Twitter fallbacks for absent OG fields.
        $result['title']       ??= $twitter['title']       ?? null;
        $result['description'] ??= $twitter['description'] ?? null;
        if ($result['images'] === [] && isset($twitter['image'])) {
            $img = ['url' => $twitter['image']];
            if (isset($twitter['image:alt'])) {
                $img['alt'] = $twitter['image:alt'];
            }
            $result['images'][] = $img;
        }
        $result['site_name'] ??= $twitter['site'] ?? null;

        if ($result['title'] === null && $result['description'] === null && $result['images'] === []) {
            return null;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result reference; mutated in place
     * @param array<string,mixed>|null   $currentImage reference; tracks the in-progress og:image group
     */
    private function applyOg(string $key, string $value, array &$result, ?array &$currentImage): void
    {
        // og:image starts a new image group. Flush the previous one first.
        if ($key === 'image') {
            if ($currentImage !== null) {
                $result['images'][] = $currentImage;
            }
            $currentImage = ['url' => $value];
            return;
        }

        // og:image:secure_url / width / height / alt / type — attach to the
        // current group if there is one; otherwise create an orphan group so
        // we don't lose the value (some pages emit dimensions without a base).
        if (str_starts_with($key, 'image:')) {
            $sub = substr($key, 6);
            if ($currentImage === null) {
                $currentImage = [];
            }
            if (in_array($sub, ['width', 'height'], true)) {
                $currentImage[$sub] = (int) $value;
            } else {
                $currentImage[$sub] = $value;
            }
            return;
        }

        switch ($key) {
            case 'title':
            case 'type':
            case 'url':
            case 'description':
                $result[$key] ??= $value;
                return;
            case 'site_name':
                $result['site_name'] ??= $value;
                return;
            // Silently ignore anything else (og:locale, og:video, og:audio,
            // og:determiner, og:see_also, etc.).
        }
    }
}
