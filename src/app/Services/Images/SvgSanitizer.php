<?php
/**
 * NetworkWeathermapNG - cleans an uploaded SVG so it can be served and drawn
 * without running anything.
 *
 * Allow-list based: only known drawing elements and attributes are kept.
 * Scripts, event handlers, foreignObject and links out of the file go; a
 * DOCTYPE or ENTITY makes the file refused (XXE, "billion laughs"); href
 * may only point inside the file (#id) or to an embedded PNG/JPEG/GIF;
 * url() in styles may only point inside the file; @import is removed.
 */

declare(strict_types=1);

namespace App\Services\Images;

class SvgSanitizer
{
    public const MAX_BYTES = 1024 * 1024;
    private const SVG_NS = 'http://www.w3.org/2000/svg';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath', 'image', 'marker', 'pattern', 'clipPath', 'mask',
        'linearGradient', 'radialGradient', 'stop',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
        'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
        'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
        'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset',
        'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence',
    ];

    private const ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'lang', 'xml:space',
        'version', 'baseProfile', 'width', 'height', 'viewBox', 'preserveAspectRatio',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr',
        'd', 'points', 'pathLength', 'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust',
        'startOffset', 'method', 'spacing', 'side',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'opacity', 'color', 'display', 'visibility', 'overflow',
        'clip-path', 'clip-rule', 'mask', 'filter', 'marker-start', 'marker-mid', 'marker-end',
        'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'font-stretch',
        'text-anchor', 'text-decoration', 'dominant-baseline', 'alignment-baseline',
        'baseline-shift', 'letter-spacing', 'word-spacing', 'writing-mode', 'direction',
        'stop-color', 'stop-opacity', 'offset', 'gradientUnits', 'gradientTransform',
        'spreadMethod', 'patternUnits', 'patternContentUnits', 'patternTransform',
        'clipPathUnits', 'maskUnits', 'maskContentUnits', 'markerUnits', 'markerWidth',
        'markerHeight', 'refX', 'refY', 'orient', 'shape-rendering', 'text-rendering',
        'image-rendering', 'color-interpolation', 'color-interpolation-filters', 'paint-order',
        'vector-effect', 'mix-blend-mode', 'isolation', 'flood-color', 'flood-opacity',
        'lighting-color', 'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'mode',
        'type', 'values', 'operator', 'k1', 'k2', 'k3', 'k4', 'stdDeviation', 'edgeMode',
        'order', 'kernelMatrix', 'divisor', 'bias', 'targetX', 'targetY', 'preserveAlpha',
        'surfaceScale', 'diffuseConstant', 'specularConstant', 'specularExponent',
        'kernelUnitLength', 'scale', 'xChannelSelector', 'yChannelSelector', 'radius',
        'baseFrequency', 'numOctaves', 'seed', 'stitchTiles', 'azimuth', 'elevation',
        'z', 'pointsAtX', 'pointsAtY', 'pointsAtZ', 'limitingConeAngle',
        'tableValues', 'slope', 'intercept', 'amplitude', 'exponent',
        'href', 'xlink:href',
    ];

    /**
     * The cleaned SVG text.
     *
     * @throws \RuntimeException with a message for the person uploading
     */
    public function sanitize(string $svg): string
    {
        if (strlen($svg) > self::MAX_BYTES) {
            throw new \RuntimeException(__('SVG file too large'));
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            throw new \RuntimeException(__('SVG with a DOCTYPE or ENTITY declaration is refused'));
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;
        if (!$loaded || !$root || $root->localName !== 'svg' || !in_array($root->namespaceURI, [self::SVG_NS, null], true)) {
            throw new \RuntimeException(__('not a valid SVG file'));
        }
        if ($doc->doctype !== null) {
            throw new \RuntimeException(__('SVG with a DOCTYPE or ENTITY declaration is refused'));
        }

        $this->cleanElement($root);

        $out = new \DOMDocument('1.0', 'UTF-8');
        $out->appendChild($out->importNode($root, true));
        if (!$out->documentElement->hasAttribute('xmlns')) {
            $out->documentElement->setAttribute('xmlns', self::SVG_NS);
        }

        return (string) $out->saveXML($out->documentElement) . "\n";
    }

    private function cleanElement(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            /** @var \DOMAttr $attr */
            $name = $attr->prefix ? $attr->prefix . ':' . $attr->localName : $attr->localName;
            if ($attr->namespaceURI === self::XLINK_NS) {
                $name = 'xlink:' . $attr->localName;
            }
            if (!in_array($name, self::ATTRIBUTES, true) || !$this->safeValue($name, $attr->value)) {
                $element->removeAttributeNode($attr);
            } elseif ($name === 'style') {
                $attr->value = $this->cleanCss($attr->value);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $known = in_array($child->localName, self::ELEMENTS, true)
                    && in_array($child->namespaceURI, [self::SVG_NS, null], true);
                if (!$known) {
                    $element->removeChild($child);
                    continue;
                }
                $this->cleanElement($child);
                if ($child->localName === 'style') {
                    $css = $this->cleanCss($child->textContent);
                    while ($child->firstChild) {
                        $child->removeChild($child->firstChild);
                    }
                    $child->appendChild($child->ownerDocument->createTextNode($css));
                }
            } elseif ($child instanceof \DOMText) {
                continue; // text and CDATA
            } else {
                $element->removeChild($child); // comments, processing instructions, entity references
            }
        }
    }

    private function safeValue(string $name, string $value): bool
    {
        $plain = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', $value));

        if ($name === 'href' || $name === 'xlink:href') {
            return str_starts_with($value, '#') && !str_contains($value, '(')
                || preg_match('#^data:image/(png|jpeg|gif);base64,[A-Za-z0-9+/=\s]+$#i', $value) === 1;
        }

        // url(...) attributes (fill, clip-path, filter, mask, markers): internal references only
        if (str_contains($plain, 'url(')) {
            return preg_match_all('/url\(/', $plain) === preg_match_all('/url\(["\']?#/', $plain);
        }

        return !str_contains($plain, 'javascript:') && !str_contains($plain, 'expression(');
    }

    /** Drops @import, escapes and every url() that does not point inside the file. */
    public function cleanCss(string $css): string
    {
        // CSS escapes can spell url( or @import in ways the rules below
        // would not see: such styles are dropped whole.
        if (str_contains($css, '\\')) {
            return '';
        }
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace_callback(
            '/url\(\s*(["\']?)(.*?)\1\s*\)/is',
            fn(array $m): string => str_starts_with(trim($m[2]), '#') && !preg_match('/[()]/', $m[2]) ? $m[0] : 'none',
            $css
        );
        // a url( left unbalanced or hidden with escapes is dropped too
        $css = (string) preg_replace('/url\s*\((?!\s*["\']?#)|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', '', $css);

        return $css;
    }
}
