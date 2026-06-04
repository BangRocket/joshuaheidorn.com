<?php

declare(strict_types=1);

namespace App\Support;

final class PortableText
{
    /**
     * @param array<int,array<string,mixed>> $blocks
     */
    public function toMarkdown(array $blocks): string
    {
        $pieces = [];

        foreach ($blocks as $block) {
            $type = $block['_type'] ?? null;

            if ($type === 'block') {
                $pieces[] = [
                    'md' => $this->renderBlock($block),
                    'list' => isset($block['listItem']),
                ];
            } elseif ($type === 'image') {
                $pieces[] = ['md' => $this->renderImage($block), 'list' => false];
            }
            // Unknown block types are skipped.
        }

        $result = '';
        foreach ($pieces as $i => $piece) {
            if ($piece['md'] === '') {
                continue;
            }
            if ($i > 0) {
                $bothLists = $piece['list'] && ($pieces[$i - 1]['list'] ?? false);
                $result .= $bothLists ? "\n" : "\n\n";
            }
            $result .= $piece['md'];
        }

        return trim($result) . "\n";
    }

    private function renderBlock(array $block): string
    {
        $text = $this->renderChildren($block['children'] ?? [], $block['markDefs'] ?? []);

        if (isset($block['listItem'])) {
            $level = max(1, (int) ($block['level'] ?? 1));
            $indent = str_repeat('  ', $level - 1);
            $marker = $block['listItem'] === 'number' ? '1.' : '-';

            return $indent . $marker . ' ' . $text;
        }

        return match ($block['style'] ?? 'normal') {
            'h1' => '# ' . $text,
            'h2' => '## ' . $text,
            'h3' => '### ' . $text,
            'h4' => '#### ' . $text,
            'blockquote' => '> ' . $text,
            default => $text,
        };
    }

    private function renderChildren(array $children, array $markDefs): string
    {
        $defs = [];
        foreach ($markDefs as $def) {
            if (isset($def['_key'])) {
                $defs[$def['_key']] = $def;
            }
        }

        $out = '';
        foreach ($children as $child) {
            if (($child['_type'] ?? 'span') !== 'span') {
                continue;
            }

            $text = $child['text'] ?? '';
            $marks = $child['marks'] ?? [];

            $href = null;
            foreach ($marks as $mark) {
                if (isset($defs[$mark]) && ($defs[$mark]['_type'] ?? '') === 'link') {
                    $href = $defs[$mark]['href'] ?? null;
                }
            }

            if (in_array('code', $marks, true)) {
                $text = '`' . $text . '`';
            }
            if (in_array('strong', $marks, true)) {
                $text = '**' . $text . '**';
            }
            if (in_array('em', $marks, true)) {
                $text = '_' . $text . '_';
            }
            if ($href !== null) {
                $text = '[' . $text . '](' . $href . ')';
            }

            $out .= $text;
        }

        return $out;
    }

    private function renderImage(array $block): string
    {
        $alt = $block['alt'] ?? '';
        $ref = $block['asset']['_ref'] ?? ($block['asset']['id'] ?? '');

        return '![' . $alt . '](ASSET:' . $ref . ')';
    }
}
