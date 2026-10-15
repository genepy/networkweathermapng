<?php
/**
 * Records what DrawMap() paints so the same map can be written as SVG.
 *
 * The PNG renderer stays the reference: while a collector is active, the
 * drawing helpers (wimagefilledpolygon, wimagepolygon, myimagestring, node
 * blits) report each shape here as well. Links, link labels and node labels
 * come out as vectors; icons, the background picture and the map furniture
 * (title, legend, timestamp) are embedded as images.
 *
 * Usage:
 *   $svg = WeatherMapSvg::render($map);            // after ReadConfig/ReadData
 *   $svg = WeatherMapSvg::render($map, ['font_url' => fn($n, $font) => '/font/'.$n]);
 */
class WeatherMapSvg {
	/** @var WeatherMapSvg|null */
	static $active = null;

	var $image = null;
	var $map = null;
	var $items = array();
	var $owner = null;
	/** What the shapes being drawn are for, such as "bwlabel-in" */
	var $role = null;
	var $options = array();

	function __construct(&$map, $options = array()) {
		$this->map = $map;
		$this->options = $options;
	}

	/**
	 * Draw the map and return it as an SVG document.
	 */
	static function render(&$map, $options = array()) {
		$collector = new WeatherMapSvg($map, $options);
		$previous = self::$active;
		self::$active = $collector;

		try {
			ob_start();
			$map->DrawMap('null');
			ob_end_clean();
		} finally {
			self::$active = $previous;
		}

		return $collector->toSvg();
	}

	/** Called by DrawMap once the canvas exists. */
	function start($image) {
		$this->image = $image;
	}

	function accepts($image) {
		return $this->image !== null && $image === $this->image;
	}

	/** Everything recorded until the next call belongs to this map item. */
	function begin($kind, $name = null, $id = null) {
		if ($kind === null) {
			$this->owner = null;
		} else {
			$this->owner = array('kind' => $kind, 'name' => $name, 'id' => $id);
		}
	}

	function background($colour, $file) {
		$this->items[] = array('type' => 'background', 'fill' => $colour, 'href' => $file != '' ? self::dataUri($file) : null);
	}

	function polygon($points, $colour, $filled) {
		$rgba = $this->rgba($colour);
		$last = count($this->items) - 1;

		// An outline drawn right after the fill of the same shape belongs to it
		if (!$filled && $last >= 0 && $this->items[$last]['type'] == 'polygon'
			&& $this->items[$last]['points'] == $points && $this->items[$last]['owner'] == $this->owner) {
			$this->items[$last]['stroke'] = $rgba;
			return;
		}

		$this->items[] = array(
			'type'   => 'polygon',
			'owner'  => $this->owner,
			'role'   => $this->role,
			'points' => array_values($points),
			'fill'   => $filled ? $rgba : null,
			'stroke' => $filled ? null : $rgba,
		);
	}

	function text($fontnumber, $x, $y, $string, $colour, $angle) {
		$this->items[] = array(
			'type'   => 'text',
			'owner'  => $this->owner,
			'role'   => $this->role,
			'font'   => $fontnumber,
			'x'      => $x,
			'y'      => $y,
			'text'   => $string,
			'fill'   => $this->rgba($colour),
			'angle'  => $angle,
		);
	}

	function node($node) {
		if (empty($node->svgparts)) {
			return;
		}

		$this->items[] = array_merge(array('type' => 'node', 'owner' => $this->owner), $node->svgparts);
	}

	/** A transparent layer painted with things we do not draw as vectors. */
	function raster($layer) {
		ob_start();
		imagepng($layer);
		$png = ob_get_clean();

		$this->items[] = array('type' => 'raster', 'owner' => $this->owner, 'href' => 'data:image/png;base64,' . base64_encode($png));
	}

	function rgba($colour) {
		if ($colour instanceof Colour) {
			return $colour->is_real() ? array($colour->r, $colour->g, $colour->b, 1) : null;
		}

		if (is_array($colour)) {
			return ($colour[0] >= 0) ? array($colour[0], $colour[1], $colour[2], 1) : null;
		}

		if ($this->image === null || !is_int($colour) || $colour < 0) {
			return null;
		}

		$c = imagecolorsforindex($this->image, $colour);

		return array($c['red'], $c['green'], $c['blue'], round(1 - $c['alpha'] / 127, 3));
	}

	/**
	 * The file GD draws for an icon: an .svg icon is drawn from the .png
	 * next to it.
	 */
	static function rasterFor($file) {
		if (preg_match('/\.svg$/i', $file)) {
			$png = substr($file, 0, -4) . '.png';
			return is_readable($png) ? $png : $file;
		}
		return $file;
	}

	/**
	 * The vector version of an icon, if there is one: the .svg itself, or
	 * an .svg next to the .png. The SVG output uses it instead of the bitmap.
	 */
	static function vectorFor($file) {
		if (preg_match('/\.svg$/i', $file)) {
			return is_readable($file) ? $file : null;
		}
		if (preg_match('/\.(png|gif|jpe?g)$/i', $file)) {
			$svg = preg_replace('/\.(png|gif|jpe?g)$/i', '.svg', $file);
			return is_readable($svg) ? $svg : null;
		}
		return null;
	}

	static function dataUri($file) {
		if (!is_readable($file)) {
			return null;
		}

		$mime = 'image/png';
		if (preg_match('/\.jpe?g$/i', $file)) {
			$mime = 'image/jpeg';
		} elseif (preg_match('/\.gif$/i', $file)) {
			$mime = 'image/gif';
		} elseif (preg_match('/\.svg$/i', $file)) {
			$mime = 'image/svg+xml';
		}

		return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file));
	}

	static function gdToDataUri($im) {
		ob_start();
		imagesavealpha($im, true);
		imagepng($im);
		return 'data:image/png;base64,' . base64_encode(ob_get_clean());
	}

	// ------------------------------------------------------------------
	// Output

	function toSvg() {
		$w = (int) $this->map->width;
		$h = (int) $this->map->height;

		$out = array();
		$out[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%d" height="%d" viewBox="0 0 %d %d" class="wm-map">', $w, $h, $w, $h);
		$out[] = $this->fontFaces();

		$group = null;
		foreach ($this->items as $item) {
			$key = isset($item['owner']) && $item['owner'] ? $item['owner']['kind'] . ':' . $item['owner']['name'] : null;

			if ($key !== $group) {
				if ($group !== null) {
					$out[] = '</g>';
				}
				if ($key !== null) {
					$o = $item['owner'];
					$out[] = sprintf('<g class="wm-%s" data-kind="%s" data-name="%s" data-id="%s">',
						$o['kind'], $o['kind'], self::esc($o['name']), self::esc((string) $o['id']));
				}
				$group = $key;
			}

			$out[] = $this->item($item);
		}

		if ($group !== null) {
			$out[] = '</g>';
		}

		$out[] = '</svg>';

		return implode("\n", array_filter($out, 'strlen'));
	}

	function item($item) {
		switch ($item['type']) {
			case 'background':
				$s = sprintf('<rect class="wm-bg" pointer-events="none" x="0" y="0" width="%d" height="%d"%s/>', $this->map->width, $this->map->height, self::paint('fill', $item['fill']));
				if ($item['href']) {
					$s .= sprintf('<image class="wm-bgimage" pointer-events="none" x="0" y="0" href="%s"/>', self::esc($item['href']));
				}
				return $s;

			case 'polygon':
				$pts = array();
				for ($i = 0; $i + 1 < count($item['points']); $i += 2) {
					$pts[] = self::num($item['points'][$i]) . ',' . self::num($item['points'][$i + 1]);
				}
				return sprintf('<polygon%s points="%s"%s%s/>', self::roleClass($item), implode(' ', $pts),
					$item['fill'] ? self::paint('fill', $item['fill']) : ' fill="none"',
					$item['stroke'] ? self::paint('stroke', $item['stroke']) : '');

			case 'text':
				return $this->textElement($item['font'], $item['x'], $item['y'], $item['text'], $item['fill'], $item['angle'], self::roleClass($item));

			case 'node':
				return $this->nodeElements($item);

			case 'raster':
				return sprintf('<image class="wm-furniture" pointer-events="none" x="0" y="0" width="%d" height="%d" href="%s"/>', $this->map->width, $this->map->height, $item['href']);
		}

		return '';
	}

	function nodeElements($n) {
		$s = '';

		if (!empty($n['icon'])) {
			$i = $n['icon'];
			$s .= sprintf('<image class="wm-icon" x="%s" y="%s" width="%s" height="%s" href="%s"/>',
				self::num($i['x']), self::num($i['y']), self::num($i['w']), self::num($i['h']), $i['href']);
		}

		if (!empty($n['label'])) {
			$l = $n['label'];
			$box = sprintf('x="%s" y="%s" width="%s" height="%s"', self::num($l['x1']), self::num($l['y1']),
				self::num($l['x2'] - $l['x1']), self::num($l['y2'] - $l['y1']));

			if ($l['bg']) {
				$s .= '<rect class="wm-labelbox" ' . $box . self::paint('fill', $l['bg']) . '/>';
			}
			if ($l['outline']) {
				$s .= '<rect ' . $box . ' fill="none"' . self::paint('stroke', $l['outline']) . '/>';
			}
			if ($l['shadow']) {
				$s .= $this->textElement($l['font'], $l['tx'] + 1, $l['ty'] + 1, $l['text'], $l['shadow'], $l['angle']);
			}
			$s .= $this->textElement($l['font'], $l['tx'], $l['ty'], $l['text'], $l['colour'], $l['angle']);
		}

		return $s;
	}

	/**
	 * Text placed like myimagestring(): (x, y) is the left end of the baseline
	 * for TrueType fonts and the bottom-left corner for GD's built-in fonts.
	 */
	function textElement($fontnumber, $x, $y, $string, $fill, $angle, $extra = '') {
		$font = $this->fontInfo($fontnumber);
		$lines = explode("\n", $string);
		$attrs = sprintf(' font-family="%s" font-size="%s"%s', $font['family'], self::num($font['size']), self::paint('fill', $fill));

		if ($angle != 0) {
			$attrs .= sprintf(' transform="rotate(%s %s %s)"', self::num(-$angle), self::num($x), self::num($y));
		}

		$s = '';
		$baseline = $y - $font['descent'];

		foreach ($lines as $i => $line) {
			if ($line === '') {
				continue;
			}

			list($lw) = $this->map->myimagestringsize($fontnumber, $line);
			$s .= sprintf('<text%s x="%s" y="%s" textLength="%s" lengthAdjust="spacingAndGlyphs" xml:space="preserve"%s>%s</text>',
				$extra, self::num($x), self::num($baseline + $i * $font['lineheight']), self::num($lw), $attrs, self::esc($line));
		}

		return $s;
	}

	function fontInfo($n) {
		$map = $this->map;

		if ($n > 5 && isset($map->fonts[$n]) && $map->fonts[$n]->type == 'truetype') {
			// GD renders TrueType at 96 dpi
			$px = $map->fonts[$n]->size * 96 / 72;
			return array('family' => "wmfont$n, sans-serif", 'size' => $px, 'descent' => 0, 'lineheight' => $px * 1.2);
		}

		if ($n > 5 && isset($map->fonts[$n]) && $map->fonts[$n]->type == 'gd') {
			$n = $map->fonts[$n]->gdnumber;
		}

		if ($n < 1 || $n > 5) {
			$n = 5;
		}

		$h = imagefontheight($n);
		// GD's medium and giant fonts are bold
		$family = ($n == 3 || $n == 5) ? 'monospace" font-weight="bold' : 'monospace';

		return array('family' => $family, 'size' => $h, 'descent' => round($h * 0.2), 'lineheight' => $h);
	}

	function fontFaces() {
		$faces = '';

		foreach ($this->map->fonts as $n => $font) {
			if ($font->type != 'truetype' || !is_readable($font->file)) {
				continue;
			}

			if (isset($this->options['font_url'])) {
				$src = call_user_func($this->options['font_url'], $n, $font);
			} else {
				$src = 'data:font/ttf;base64,' . base64_encode(file_get_contents($font->file));
			}

			$faces .= sprintf("@font-face{font-family:wmfont%d;src:url(\"%s\")}\n", $n, $src);
		}

		return $faces === '' ? '' : "<defs><style>\n" . $faces . "</style></defs>";
	}

	static function roleClass($item) {
		if (!empty($item['role'])) {
			return ' class="wm-' . $item['role'] . '"';
		}
		return $item['type'] == 'polygon' ? ' class="wm-arrow"' : ' class="wm-comment"';
	}

	static function paint($attr, $rgba) {
		if (!$rgba) {
			return " $attr=\"none\"";
		}

		$s = sprintf(' %s="#%02x%02x%02x"', $attr, $rgba[0], $rgba[1], $rgba[2]);
		if ($rgba[3] < 1) {
			$s .= sprintf(' %s-opacity="%s"', $attr, $rgba[3]);
		}

		return $s;
	}

	static function num($v) {
		$v = round((float) $v, 2);
		return ($v == (int) $v) ? (string) (int) $v : (string) $v;
	}

	static function esc($s) {
		return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
}
