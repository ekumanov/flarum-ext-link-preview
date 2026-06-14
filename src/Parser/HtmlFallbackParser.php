<?php

namespace Ekumanov\LinkPreview\Parser;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Used when OpenGraphParser can't produce a usable card — pages that don't
 * advertise og:* / twitter:* metadata but do have a <title> and maybe a
 * <meta name="description"> still deserve a basic card.
 *
 * Output shape matches the `fallback` and `icons` JSON columns the display
 * layer reads (PostResourceFields.firstImage / buildPreview()) — a stable
 * shape kept across migrations.
 */
final class HtmlFallbackParser
{
    /**
     * @return array{
     *   fallback: array{title:?string,description:?string}|null,
     *   icons: list<array{href:string,type?:string,sizes?:list<array{width:int,height:int}>}>
     * }
     */
    public function parse(string $html): array
    {
        $result = ['fallback' => null, 'icons' => []];

        if (trim($html) === '') {
            return $result;
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return $result;
        }

        $xpath = new DOMXPath($doc);

        $titleNode = $xpath->query('//head/title')->item(0);
        $title = $titleNode instanceof DOMElement ? trim($titleNode->textContent) : null;

        $descNode = $xpath->query('//head/meta[translate(@name,"DESCRIPTION","description")="description"][1]/@content')->item(0);
        $description = $descNode !== null ? trim($descNode->nodeValue) : null;

        if ($title !== null && $title !== '') {
            $result['fallback'] = [
                'title' => $title,
                'description' => $description !== null && $description !== '' ? $description : null,
            ];
        } elseif ($description !== null && $description !== '') {
            // Edge case: title missing but description present.
            $result['fallback'] = ['title' => null, 'description' => $description];
        }

        $icons = $xpath->query('//head/link[contains(translate(@rel,"ICON","icon"),"icon")]');
        if ($icons !== false) {
            foreach ($icons as $icon) {
                if (! $icon instanceof DOMElement) {
                    continue;
                }
                $href = trim($icon->getAttribute('href'));
                if ($href === '') {
                    continue;
                }
                $entry = ['href' => $href];
                $type = trim($icon->getAttribute('type'));
                if ($type !== '') {
                    $entry['type'] = $type;
                }
                $sizesAttr = trim($icon->getAttribute('sizes'));
                if ($sizesAttr !== '' && $sizesAttr !== 'any') {
                    $sizes = [];
                    foreach (preg_split('/\s+/', $sizesAttr) ?: [] as $size) {
                        if (preg_match('/^(\d+)x(\d+)$/i', $size, $m) === 1) {
                            $sizes[] = ['width' => (int) $m[1], 'height' => (int) $m[2]];
                        }
                    }
                    if ($sizes !== []) {
                        $entry['sizes'] = $sizes;
                    }
                }
                $result['icons'][] = $entry;
            }
        }

        return $result;
    }
}
