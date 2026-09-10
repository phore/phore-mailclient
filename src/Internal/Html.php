<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/** @internal Small positive allowlist for explicitly supplied signature HTML. */
final class Html
{
    public static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }
    public static function literal(string $value): string { return '<p>' . nl2br(self::escape($value), false) . '</p>'; }
    public static function sanitize(string $html, array $images): string
    {
        if (preg_match('//u', $html) !== 1) { throw new InvalidArgumentException('Invalid signature UTF-8.'); }
        $doc = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $body = $doc->getElementsByTagName('body')->item(0);
        $walk = static function (DOMNode $parent) use (&$walk, $images): void {
            foreach (iterator_to_array($parent->childNodes) as $node) {
                if (!$node instanceof DOMElement) {
                    if ($node->nodeType !== XML_TEXT_NODE) { $parent->removeChild($node); }
                    continue;
                }
                $tag = strtolower($node->tagName);
                if (in_array($tag, ['script','style','form','iframe','object','embed','svg','math','input','button','textarea','link','meta','base'], true)) {
                    $parent->removeChild($node); continue;
                }
                $walk($node);
                if (!in_array($tag, ['p','div','span','br','strong','b','em','i','u','s','a','img','table','tbody','thead','tr','td','th','font','hr','ul','ol','li','blockquote'], true)) {
                    while ($node->firstChild) { $parent->insertBefore($node->firstChild, $node); }
                    $parent->removeChild($node); continue;
                }
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    $name = strtolower($attribute->name); $value = $attribute->value; $safe = false;
                    if (in_array($name, ['alt','title'], true)) { $safe = true; }
                    if (in_array($name, ['width','height','cellpadding','cellspacing','border','colspan','rowspan','size'], true)) { $safe = preg_match('/^[0-9]{1,4}%?$/D', $value) === 1; }
                    if ($name === 'align') { $safe = in_array($value, ['left','right','center','justify'], true); }
                    if ($name === 'color') { $safe = preg_match('/^(#[0-9a-f]{3,8}|[a-z]+)$/Di', $value) === 1; }
                    if ($name === 'face') { $safe = preg_match('/^[a-zA-Z ,\-]+$/D', $value) === 1; }
                    if ($name === 'href' && $tag === 'a') { $safe = preg_match('/^(https?:\/\/|mailto:)[^\x00-\x20\x7f]+$/Di', $value) === 1; }
                    if ($name === 'src' && $tag === 'img') {
                        if (str_starts_with($value, 'cid:')) {
                            $alias = substr($value, 4);
                            if (!isset($images[$alias])) { throw new InvalidArgumentException('Unmapped signature Content-ID.'); }
                            $node->setAttribute('src', 'cid:' . $images[$alias]); $safe = true;
                        }
                    }
                    if ($name === 'style') {
                        $styles = [];
                        foreach (explode(';', $value) as $style) {
                            $pair = explode(':', $style, 2);
                            if (count($pair) !== 2) { continue; }
                            [$property, $v] = array_map('trim', $pair);
                            if (in_array(strtolower($property), ['color','background-color','font-family','font-size','font-weight','font-style','text-decoration','text-align','vertical-align','line-height','padding','margin','border','border-collapse','width','height'], true)
                                && preg_match('/^[a-zA-Z0-9 #.,%\-]+$/D', $v)
                                && !preg_match('/(url|expression|import|behavior|binding)/i', $v)) { $styles[] = strtolower($property) . ':' . $v; }
                        }
                        $node->setAttribute('style', implode(';', $styles)); $safe = $styles !== [];
                    }
                    if (!$safe) { $node->removeAttribute($name); }
                }
                if ($tag === 'img' && !$node->hasAttribute('src')) {
                    $parent->replaceChild($node->ownerDocument->createTextNode($node->getAttribute('alt')), $node);
                }
            }
        };
        $walk($body); $out = '';
        foreach ($body->childNodes as $node) { $out .= $doc->saveHTML($node); }
        return $out;
    }
}
