<?php
/**
 * Records what DrawMap() paints so the same map can be written as SVG.
 *
 * The PNG renderer stays the reference: while a collector is active, the
 * drawing helpers (wimagefilledpolygon, wimagepolygon, myimagestring, node
 * blits) report each shape here as well. Links, link labels, node labels and
 * the map furniture (title, legends, timestamps) come out as vectors; icons
 * without an .svg twin and the background picture are embedded as images.
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
	/** Whether a saturated link was drawn: the pulse style is then added */
	var $hasHot = false;
	var $options = array();
	/** Other GD images whose drawing is part of the map: id => [dx, dy] */
	var $adopted = array();
	/** Offset of the image accepted last, applied to what is recorded next */
	var $offset = array(0, 0);

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

	/**
	 * Whether shapes drawn on $image are recorded. A true answer also sets
	 * the offset applied to the shape reported next.
	 */
	function accepts($image) {
		if ($this->image === null || !is_object($image)) {
			return false;
		}
		if ($image === $this->image) {
			$this->offset = array(0, 0);
			return true;
		}
		$id = spl_object_id($image);
		if (isset($this->adopted[$id]) && $this->adopted[$id][0] === $image) {
			$this->offset = array($this->adopted[$id][1], $this->adopted[$id][2]);
			return true;
		}
		return false;
	}

	/**
	 * A scratch image (such as a legend box) that is later copied onto the
	 * map at ($dx, $dy): what is drawn on it is recorded at that place.
	 */
	function adopt($image, $dx, $dy) {
		if (is_object($image)) {
			$this->adopted[spl_object_id($image)] = array($image, $dx, $dy);
		}
	}

	/**
	 * A GD rectangle. Only recorded for a map item (the furniture): the
	 * canvas fill is reported by background().
	 */
	function rect($x1, $y1, $x2, $y2, $colour, $filled) {
		if ($this->owner === null) {
			return;
		}
		$rgba = $this->rgba($colour);
		if (!$rgba) {
			return;
		}
		list($dx, $dy) = $this->offset;
		$this->items[] = array(
			'type'   => 'rect',
			'owner'  => $this->owner,
			'x1'     => min($x1, $x2) + $dx,
			'y1'     => min($y1, $y2) + $dy,
			'x2'     => max($x1, $x2) + $dx,
			'y2'     => max($y1, $y2) + $dy,
			'colour' => $rgba,
			'filled' => $filled,
		);
	}

	/** A one-pixel GD line, recorded for a map item only */
	function line($x1, $y1, $x2, $y2, $colour) {
		if ($this->owner === null) {
			return;
		}
		$rgba = $this->rgba($colour);
		if (!$rgba) {
			return;
		}
		list($dx, $dy) = $this->offset;
		$this->items[] = array(
			'type'   => 'line',
			'owner'  => $this->owner,
			'points' => array($x1 + $dx, $y1 + $dy, $x2 + $dx, $y2 + $dy),
			'colour' => $rgba,
		);
	}

	/** Everything recorded until the next call belongs to this map item. */
	function begin($kind, $name = null, $id = null, $hot = false) {
		if ($kind === null) {
			$this->owner = null;
		} else {
			$this->owner = array('kind' => $kind, 'name' => $name, 'id' => $id, 'hot' => $hot);
		}
		if ($hot) {
			$this->hasHot = true;
		}
	}

	/**
	 * Links at SATURATED % or more pulse and glow, on maps with
	 * SET traffic_pulse 1 (the PHP Weathermap 2.0 hint). Still under
	 * prefers-reduced-motion: the glow stays, the pulse stops.
	 */
	const SATURATED = 80;

	static function isSaturated($map, $link) {
		return (string) $map->get_hint('traffic_pulse') === '1'
			&& max((float) $link->inpercent, (float) $link->outpercent) >= self::SATURATED;
	}

	/**
	 * SET link_glow 1: a soft glow in the colour of each link (the Night
	 * theme). SVG only: the PNG keeps plain links.
	 */
	static function hasGlow($map) {
		return (string) $map->get_hint('link_glow') === '1';
	}

	/** The glow filter and its style, '' when the map has no glow */
	function glowStyle() {
		if (!self::hasGlow($this->map)) {
			return '';
		}
		// a saturated link keeps its own red glow and pulse
		return '<defs><filter id="wm-glow" x="-50%" y="-50%" width="200%" height="200%" color-interpolation-filters="sRGB">'
			. '<feGaussianBlur in="SourceGraphic" stdDeviation="2.5" result="blur"/>'
			. '<feComponentTransfer in="blur" result="soft"><feFuncA type="linear" slope="0.8"/></feComponentTransfer>'
			. '<feMerge><feMergeNode in="soft"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>' . "\n"
			. "<style>\n.wm-map .wm-link:not(.wm-hot) .wm-arrow{filter:url(#wm-glow)}\n</style>";
	}

	/**
	 * SET link_flow 1: the viewer moves glowing dots along the links, in
	 * the direction of the traffic. The SVG only carries the path of each
	 * direction (invisible); the motion is left to the viewer script, so
	 * the editor, the PNG and a downloaded SVG stay still.
	 */
	static function hasFlow($map) {
		return (string) $map->get_hint('link_flow') === '1';
	}

	/**
	 * Record the paths the traffic of $link follows: OUT from A to the
	 * split point and IN from B back to it, or OUT over the whole link
	 * for a one-way link.
	 */
	function flow($link, $width) {
		if ($this->owner === null || !self::hasFlow($this->map)) {
			return;
		}
		$points = $link->curvepoints;
		if (!is_array($points) || count($points) < 2 || !isset($points[0][2])) {
			return;
		}
		$total = $points[count($points) - 1][2];
		if ($total <= 0) {
			return;
		}
		$oneway = $link->linkstyle == 'oneway';
		$split = $oneway ? $total : $total * max(0, min(100, (float) $link->splitpos)) / 100;

		$runs = array(array('out', 0, $split, $link->outpercent));
		if (!$oneway) {
			$runs[] = array('in', $total, $split, $link->inpercent);
		}
		foreach ($runs as $run) {
			$spine = self::spine($points, $run[1], $run[2]);
			if (count($spine) < 2) {
				continue;
			}
			$this->items[] = array(
				'type'   => 'flow',
				'owner'  => $this->owner,
				'dir'    => $run[0],
				'points' => $spine,
				'load'   => max(0, (float) $run[3]),
				'width'  => (float) $width,
			);
		}
	}

	/**
	 * The part of a spine ([x, y, distance] points) between two distances,
	 * from $from to $to (backwards when $to is smaller), as [x, y] points
	 * at least 2 px apart.
	 */
	static function spine($points, $from, $to) {
		$lo = min($from, $to);
		$hi = max($from, $to);
		$at = function ($d) use ($points) {
			$n = count($points);
			for ($i = 1; $i < $n; $i++) {
				if ($points[$i][2] >= $d) {
					$a = $points[$i - 1];
					$b = $points[$i];
					$span = $b[2] - $a[2];
					$f = $span > 0 ? ($d - $a[2]) / $span : 0;
					return array($a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f);
				}
			}
			return array($points[$n - 1][0], $points[$n - 1][1]);
		};

		$out = array($at($lo));
		foreach ($points as $p) {
			if ($p[2] > $lo && $p[2] < $hi) {
				$out[] = array($p[0], $p[1]);
			}
		}
		$out[] = $at($hi);

		$kept = array();
		foreach ($out as $i => $p) {
			$last = end($kept);
			if ($last && $i < count($out) - 1 && abs($p[0] - $last[0]) + abs($p[1] - $last[1]) < 2) {
				continue;
			}
			$kept[] = $p;
		}
		return $from > $to ? array_reverse($kept) : $kept;
	}

	function hotStyle() {
		if (!$this->hasHot) {
			return '';
		}
		return "<style>\n"
			. ".wm-map .wm-hot .wm-arrow{filter:drop-shadow(0 0 3px rgba(255,64,0,.9));animation:wm-pulse 1.6s ease-in-out infinite}\n"
			. "@keyframes wm-pulse{50%{opacity:.6;filter:drop-shadow(0 0 6px rgba(255,64,0,.9))}}\n"
			. "@media (prefers-reduced-motion:reduce){.wm-map .wm-hot .wm-arrow{animation:none}}\n"
			. "</style>";
	}

	/**
	 * @param array|null $box x, y, width, height of the picture on the canvas
	 *                        (WeatherMap::background_box), null for its natural place
	 */
	function background($colour, $file, $box = null) {
		$this->items[] = array('type' => 'background', 'fill' => $colour, 'href' => $file != '' ? self::dataUri($file) : null, 'box' => $box);
	}

	function polygon($points, $colour, $filled) {
		$rgba = $this->rgba($colour);
		$last = count($this->items) - 1;
		$points = $this->shift(array_values($points));

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
			'points' => $points,
			'fill'   => $filled ? $rgba : null,
			'stroke' => $filled ? null : $rgba,
		);
	}

	function text($fontnumber, $x, $y, $string, $colour, $angle) {
		$x += $this->offset[0];
		$y += $this->offset[1];
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

	/**
	 * A text on a path (a comment that follows a curved link): $points is
	 * its baseline, $length the width the engine measured.
	 */
	function textOnPath($fontnumber, $points, $string, $colour, $length) {
		$shifted = array();
		foreach ($points as $p) {
			$shifted[] = array($p[0] + $this->offset[0], $p[1] + $this->offset[1]);
		}
		$this->items[] = array(
			'type'   => 'textpath',
			'owner'  => $this->owner,
			'role'   => $this->role,
			'font'   => $fontnumber,
			'points' => $shifted,
			'text'   => $string,
			'fill'   => $this->rgba($colour),
			'length' => $length,
		);
	}

	function zone($zone) {
		$this->items[] = array(
			'type'    => 'zone',
			'owner'   => $this->owner,
			'x'       => $zone->x,
			'y'       => $zone->y,
			'w'       => $zone->width,
			'h'       => $zone->height,
			'fill'    => ($zone->fillcolour !== null && $zone->opacity > 0) ? array($zone->fillcolour[0], $zone->fillcolour[1], $zone->fillcolour[2], round($zone->opacity / 100, 3)) : null,
			'stroke'  => ($zone->outlinecolour !== null && $zone->outlinewidth > 0) ? array($zone->outlinecolour[0], $zone->outlinecolour[1], $zone->outlinecolour[2], 1) : null,
			'width'   => $zone->outlinewidth,
			'dashed'  => $zone->outlinestyle == 'dashed',
		);
	}

	function node($node) {
		if (empty($node->svgparts)) {
			return;
		}

		$this->items[] = array_merge(array('type' => 'node', 'owner' => $this->owner), $node->svgparts);
	}

	/** Flat x, y list moved by the current offset */
	function shift($points) {
		if ($this->offset[0] == 0 && $this->offset[1] == 0) {
			return $points;
		}
		for ($i = 0; $i + 1 < count($points); $i += 2) {
			$points[$i] += $this->offset[0];
			$points[$i + 1] += $this->offset[1];
		}
		return $points;
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
		$out[] = $this->glowStyle();
		$out[] = $this->hotStyle();

		$group = null;
		foreach ($this->items as $item) {
			$key = isset($item['owner']) && $item['owner'] ? $item['owner']['kind'] . ':' . $item['owner']['name'] : null;

			if ($key !== $group) {
				if ($group !== null) {
					$out[] = '</g>';
				}
				if ($key !== null) {
					$o = $item['owner'];
					$out[] = sprintf('<g class="wm-%s%s" data-kind="%s" data-name="%s" data-id="%s">',
						$o['kind'], empty($o['hot']) ? '' : ' wm-hot', $o['kind'], self::esc($o['name']), self::esc((string) $o['id']));
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
					$box = isset($item['box']) ? $item['box'] : null;
					$size = '';
					if ($box) {
						// the box was measured on the picture: its own ratio is already kept or dropped on purpose
						$size = sprintf(' width="%d" height="%d" preserveAspectRatio="none"', $box[2], $box[3]);
					}
					$s .= sprintf('<image class="wm-bgimage" pointer-events="none" x="%d" y="%d"%s href="%s"/>',
						$box ? $box[0] : 0, $box ? $box[1] : 0, $size, self::esc($item['href']));
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

			case 'flow':
				$d = array();
				foreach ($item['points'] as $i => $p) {
					$d[] = ($i ? 'L' : 'M') . self::num($p[0]) . ' ' . self::num($p[1]);
				}
				return sprintf('<path class="wm-flow" data-dir="%s" data-load="%s" data-width="%s" d="%s" fill="none" stroke="none" pointer-events="none"/>',
					$item['dir'], self::num(round($item['load'], 1)), self::num($item['width']), implode('', $d));

			case 'text':
				return $this->textElement($item['font'], $item['x'], $item['y'], $item['text'], $item['fill'], $item['angle'], self::roleClass($item));

			case 'textpath':
				return $this->textPathElement($item);

			case 'node':
				return $this->nodeElements($item);

			case 'zone':
				// the stroke sits inside the box, like the GD outline
				$inset = $item['stroke'] ? $item['width'] / 2 : 0;
				$s = sprintf('<rect class="wm-zone-box" x="%s" y="%s" width="%s" height="%s"%s/>',
					self::num($item['x']), self::num($item['y']), self::num($item['w'] + 1), self::num($item['h'] + 1),
					$item['fill'] ? self::paint('fill', $item['fill']) : ' fill="none"');
				if ($item['stroke']) {
					$s .= sprintf('<rect class="wm-zone-outline" pointer-events="none" x="%s" y="%s" width="%s" height="%s" fill="none" stroke-width="%s"%s%s/>',
						self::num($item['x'] + $inset), self::num($item['y'] + $inset),
						self::num($item['w'] + 1 - 2 * $inset), self::num($item['h'] + 1 - 2 * $inset),
						self::num($item['width']), self::paint('stroke', $item['stroke']),
						$item['dashed'] ? sprintf(' stroke-dasharray="%s"', self::num(6 + 2 * $item['width'])) : '');
				}
				return $s;

			case 'rect':
				// GD fills both end pixels; its outline is a 1 px line on them
				if ($item['filled']) {
					return sprintf('<rect x="%s" y="%s" width="%s" height="%s"%s shape-rendering="crispEdges"/>',
						self::num($item['x1']), self::num($item['y1']),
						self::num($item['x2'] - $item['x1'] + 1), self::num($item['y2'] - $item['y1'] + 1),
						self::paint('fill', $item['colour']));
				}
				return sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="none" stroke-width="1"%s shape-rendering="crispEdges"/>',
					self::num($item['x1'] + 0.5), self::num($item['y1'] + 0.5),
					self::num($item['x2'] - $item['x1']), self::num($item['y2'] - $item['y1']),
					self::paint('stroke', $item['colour']));

			case 'line':
				$p = $item['points'];
				return sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke-width="1"%s shape-rendering="crispEdges"/>',
					self::num($p[0] + 0.5), self::num($p[1] + 0.5), self::num($p[2] + 0.5), self::num($p[3] + 0.5),
					self::paint('stroke', $item['colour']));

			case 'raster':
				return sprintf('<image class="wm-furniture" pointer-events="none" x="0" y="0" width="%d" height="%d" href="%s"/>', $this->map->width, $this->map->height, $item['href']);
		}

		return '';
	}

	function nodeElements($n) {
		$s = '';

		if (!empty($n['badge'])) {
			return $this->badgeElements($n['badge']);
		}

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
			// a map note: one element per line, each placed by the engine
			$lines = isset($l['lines']) ? $l['lines'] : array(array($l['tx'], $l['ty'], $l['text']));
			$extra = isset($l['lines']) ? ' class="wm-note"' : '';
			foreach ($lines as $line) {
				if ($l['shadow']) {
					$s .= $this->textElement($l['font'], $line[0] + 1, $line[1] + 1, $line[2], $l['shadow'], $l['angle'], $extra);
				}
				$s .= $this->textElement($l['font'], $line[0], $line[1], $line[2], $l['colour'], $l['angle'], $extra);
			}
		}

		return $s;
	}

	/** A badge node: shadow, ring and disc, then the icon or the initials; the label is the tooltip */
	function badgeElements($b) {
		$s = '';
		if ($b['title'] !== '') {
			$s .= '<title>' . self::esc($b['title']) . '</title>';
		}
		$s .= sprintf('<circle class="wm-badge" cx="%s" cy="%s" r="%s"%s%s style="filter:drop-shadow(0 1.5px 1.5px rgba(0,0,0,.45))"/>',
			self::num($b['cx']), self::num($b['cy']), self::num($b['ring'] ? $b['r'] - $b['ring_width'] / 2 : $b['r']),
			self::paint('fill', $b['fill']),
			$b['ring'] ? self::paint('stroke', $b['ring']) . sprintf(' stroke-width="%s"', self::num($b['ring_width'])) : '');
		if (!empty($b['inner'])) {
			$i = $b['inner'];
			$s .= sprintf('<image class="wm-icon" x="%s" y="%s" width="%s" height="%s" href="%s"/>',
				self::num($i['x']), self::num($i['y']), self::num($i['w']), self::num($i['h']), $i['href']);
		} elseif (!empty($b['text'])) {
			$t = $b['text'];
			$s .= $this->textElement($t['font'], $t['x'], $t['y'], $t['text'], $t['colour'], 0, ' class="wm-badge-text"');
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

	/**
	 * The path and its text. The id comes from the path itself, so the same
	 * map drawn twice in a page gives the same id to the same path.
	 */
	function textPathElement($item) {
		$font = $this->fontInfo($item['font']);
		$d = '';
		foreach ($item['points'] as $i => $p) {
			$d .= ($i ? ' L' : 'M') . self::num($p[0]) . ' ' . self::num($p[1]);
		}
		$id = 'wm-cp-' . substr(md5($d), 0, 12);

		return sprintf('<path id="%s" d="%s" fill="none" stroke="none" pointer-events="none"/>', $id, $d)
			. sprintf('<text%s font-family="%s" font-size="%s"%s xml:space="preserve"><textPath href="#%s" xlink:href="#%s" textLength="%s" lengthAdjust="spacingAndGlyphs">%s</textPath></text>',
				self::roleClass($item), $font['family'], self::num($font['size']), self::paint('fill', $item['fill']),
				$id, $id, self::num($item['length']), self::esc($item['text']));
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
