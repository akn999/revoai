<?php

namespace App\Products;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Server-side allowlist sanitizer for product descriptions (FR-PRD-007, NFR-SEC-008).
 * Only h2, h3, p, ul, ol, li, strong, em, u, s and br survive; every attribute is removed, then
 * class="ql-direction-rtl" is added to headings and paragraphs of Arabic content only.
 */
class HtmlSanitizer
{
    private const BLOCK_RTL = ['h2', 'h3', 'p'];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form', 'head', 'title', 'link', 'meta', 'textarea', 'select', 'option', 'button', 'input'];

    public function sanitize(?string $html, string $language): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="revo-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('revo-root') ?? $document->documentElement;
        $output = $root ? trim($this->children($root, $language === 'ar')) : '';

        if ($output === '') {
            return '';
        }

        // Plain text or inline-only markup is wrapped so the result is always block-level HTML.
        if (! preg_match('/^<(h2|h3|p|ul|ol)\b/i', $output)) {
            $output = '<p'.($language === 'ar' ? ' class="'.config('revo.product.rtl_class').'"' : '').'>'.$output.'</p>';
        }

        return $output;
    }

    private function children(DOMNode $node, bool $rtl): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $this->render($child, $rtl);
        }

        return $html;
    }

    private function render(DOMNode $node, bool $rtl): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->wholeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            return '';
        }

        if (! in_array($tag, (array) config('revo.product.html_tags'), true)) {
            return $this->children($node, $rtl);
        }

        if ($tag === 'br') {
            return '<br>';
        }

        $attribute = $rtl && in_array($tag, self::BLOCK_RTL, true) ? ' class="'.config('revo.product.rtl_class').'"' : '';

        return "<{$tag}{$attribute}>".$this->children($node, $rtl)."</{$tag}>";
    }
}
