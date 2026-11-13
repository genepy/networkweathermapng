<?php
/**
 * Boxes placed on the map: total traffic, traffic legend, link speed,
 * update time and free text boxes. They are drawn by DrawMap like the other
 * map furniture, so the PNG, the SVG, the live viewer and the kiosk show
 * them alike. Each box is a set of map hints, so the .conf stays readable
 * by engines that do not know them:
 *
 *   SET box_<id> <type> <x> <y>    type: total, legend, speed, updated, text
 *                                  (negative x or y count from the right or bottom edge)
 *   SET box_<id>_title <text>      title line ("-" for none; each type has a default)
 *   SET box_<id>_style <style>     legend: bar (gradient bar, 0% and 100%) or list
 *                                  total: graph (value and small graph) or plain
 *   SET box_<id>_text <text>       text box: lines separated by \n, "label | value" puts
 *                                  the value on the right of the row
 *   SET box_<id>_rows <source>     text box rows taken from the map: sites (nodes per
 *                                  site) or sites_load (busiest link load per site)
 *   SET box_<id>_width <px>        least width of the content (default per type)
 *
 * The total traffic graph needs a series that the map does not hold: the
 * application sets WeatherMap::$box_series (a list of [time, in, out]) from
 * the map's recordings before drawing.
 */
class WeatherMapBoxes {
	const TYPES = array('total', 'legend', 'speed', 'updated', 'text');
	const ROW_SOURCES = array('sites', 'sites_load');
	const PAD = 10;
	const RADIUS = 8;

	/** Colours of the IN and OUT series of the total traffic graph (as in the viewer's panel) */
	const IN_COLOUR = array(79, 143, 247);
	const OUT_COLOUR = array(232, 163, 61);

	/**
	 * The boxes of a map, in the order of their ids:
	 * id => [type, x, y, title, style, text, rows, width].
	 */
	static function all($map) {
		if (!is_array($map->hints)) {
			return array();
		}
		$boxes = array();
		foreach ($map->hints as $key => $value) {
			if (!preg_match('/^box_([A-Za-z0-9]+)$/', (string) $key, $m)) {
				continue;
			}
			$box = self::parse($map, $m[1], (string) $value);
			if ($box !== null) {
				$boxes[$m[1]] = $box;
			}
		}
		uksort($boxes, 'strnatcmp');
		return $boxes;
	}

	/** One box from its hints, null when SET box_<id> is not a valid box */
	static function parse($map, $id, $value) {
		if (!preg_match('/^(\w+)\s+(-?\d+)[\s,]+(-?\d+)$/', trim($value), $m) || !in_array(strtolower($m[1]), self::TYPES, true)) {
			return null;
		}
		$type = strtolower($m[1]);
		$hint = function ($name) use ($map, $id) {
			$v = $map->get_hint('box_' . $id . '_' . $name);
			return $v === null ? null : trim((string) $v);
		};
		$title = $hint('title');
		if ($title === null || $title === '') {
			$title = self::defaultTitle($type);
		} elseif ($title === '-') {
			$title = '';
		}
		$style = strtolower((string) $hint('style'));
		$styles = array('legend' => array('bar', 'list'), 'total' => array('graph', 'plain'));
		if (!isset($styles[$type]) || !in_array($style, $styles[$type], true)) {
			$style = isset($styles[$type]) ? $styles[$type][0] : '';
		}
		$rows = strtolower((string) $hint('rows'));

		return array(
			'id'    => (string) $id,
			'type'  => $type,
			'x'     => (int) $m[2],
			'y'     => (int) $m[3],
			'title' => $title,
			'style' => $style,
			'text'  => self::decode((string) $hint('text')),
			'rows'  => in_array($rows, self::ROW_SOURCES, true) ? $rows : '',
			'width' => max(0, min(2000, (int) $hint('width'))),
		);
	}

	static function defaultTitle($type) {
		$titles = array('total' => 'Total traffic', 'legend' => 'Traffic', 'speed' => 'Link speed', 'updated' => 'Updated at', 'text' => '');
		return $titles[$type];
	}

	/** \n is a new line and \\ a backslash, as in map notes */
	static function decode($text) {
		return preg_replace_callback('/\\\\([\\\\n])/', function ($m) {
			return $m[1] === 'n' ? "\n" : '\\';
		}, $text);
	}

	static function encode($text) {
		$lines = preg_split('/\r?\n/', (string) $text);
		return implode('\n', array_map(function ($line) {
			return str_replace('\\', '\\\\', rtrim($line));
		}, $lines));
	}

	/** Whether one of the boxes draws the total traffic graph (it needs WeatherMap::$box_series) */
	static function wantsSeries($map) {
		foreach (self::all($map) as $box) {
			if ($box['type'] === 'total' && $box['style'] === 'graph') {
				return true;
			}
		}
		return false;
	}

	/** Draw every box of the map on $image (the furniture layer of DrawMap) */
	static function drawAll($map, $image) {
		$svg = WeatherMapSvg::$active;
		foreach (self::all($map) as $id => $box) {
			if ($svg) {
				$svg->begin('box', $id, 'BOX:' . $id);
			}
			self::draw($map, $image, $box);
		}
		if ($svg) {
			$svg->begin(null);
		}
	}

	static function draw($map, $image, $box) {
		$font = $map->keyfont;
		list($junk, $lineheight) = $map->myimagestringsize($font, 'Ag');
		$lineheight = max(8, (int) $lineheight);

		// the content: a list of blocks, each [kind, height, width, data]
		$blocks = self::content($map, $box, $font, $lineheight);

		$pad = self::PAD;
		$width = $box['width'] > 0 ? $box['width'] : array('total' => 200, 'legend' => 180, 'speed' => 0, 'updated' => 0, 'text' => 0)[$box['type']];
		$height = 0;
		if ($box['title'] !== '') {
			list($tw) = $map->myimagestringsize($font, $box['title']);
			$width = max($width, $tw);
			$height += $lineheight + 8;
		}
		foreach ($blocks as $b) {
			$width = max($width, $b[2]);
			$height += $b[1];
		}
		if (!$blocks && $box['title'] === '') {
			$height = $lineheight;
			$width = max($width, 40);
		}
		$width = (int) ceil($width);
		$boxw = $width + 2 * $pad;
		$boxh = (int) ceil($height) + 2 * $pad - ($blocks ? 0 : ($box['title'] !== '' ? 8 : 0));

		$x = $box['x'];
		$y = $box['y'];
		if ($x < 0) {
			$x += $map->width - $boxw;
		}
		if ($y < 0) {
			$y += $map->height - $boxh;
		}

		$bg = $map->colours['DEFAULT']['KEYBG'];
		$outline = $map->colours['DEFAULT']['KEYOUTLINE'];
		$text = $map->colours['DEFAULT']['KEYTEXT']['gdref1'];

		$card = wm_rounded_rect_points($x, $y, $x + $boxw, $y + $boxh, self::RADIUS);
		if (!is_none($bg)) {
			wimagefilledpolygon($image, $card, count($card) / 2, $bg['gdref1']);
		}
		if (!is_none($outline)) {
			wimagepolygon($image, $card, count($card) / 2, $outline['gdref1']);
		}

		$cx = $x + $pad;
		$cy = $y + $pad;
		if ($box['title'] !== '') {
			$map->myimagestring($image, $font, $cx, $cy + $lineheight, $box['title'], $text);
			$cy += $lineheight + 8;
		}

		foreach ($blocks as $b) {
			self::drawBlock($map, $image, $b, $cx, $cy, $width, $font, $lineheight, $text);
			$cy += $b[1];
		}

		$map->imap->addArea('Rectangle', 'BOX:' . $box['id'], '', array($x, $y, $x + $boxw, $y + $boxh));
	}

	/** What a box shows, as blocks: [kind, height, least width, data] */
	static function content($map, $box, $font, $lineheight) {
		$rowh = $lineheight + 10;
		switch ($box['type']) {
			case 'updated':
				$stamp = (string) $map->datestamp;
				list($w) = $map->myimagestringsize($font, $stamp);
				return array(array('line', $rowh, $w, $stamp));

			case 'speed':
				$steps = wm_link_width_steps($map->get_hint('link_width_table'), $map->kilo);
				$blocks = array();
				foreach ($steps as $step) {
					list($w) = $map->myimagestringsize($font, $step[2]);
					$blocks[] = array('speed', max($rowh, $step[1] + 6), 48 + $w, $step);
				}
				return $blocks;

			case 'legend':
				if ($box['style'] === 'list') {
					$blocks = array();
					foreach (self::scaleBands($map) as $band) {
						list($w) = $map->myimagestringsize($font, $band[2]);
						$blocks[] = array('band', $rowh, 24 + $w, $band);
					}
					return $blocks;
				}
				return array(array('bar', 14 + 6 + $lineheight + 2, 120, null));

			case 'total':
				list($in, $out) = self::trafficTotal($map);
				$values = array(
					array('IN', $in === null ? '-' : self::rate($in, $map->kilo), self::IN_COLOUR),
					array('OUT', $out === null ? '-' : self::rate($out, $map->kilo), self::OUT_COLOUR),
				);
				$w = 0;
				foreach ($values as $v) {
					list($vw) = $map->myimagestringsize($font, $v[1]);
					$w = max($w, $vw + 12);
				}
				$blocks = array(array('total', 2 * $lineheight + 8, 2 * $w + 16, $values));
				$series = is_array($map->box_series) ? $map->box_series : array();
				if ($box['style'] === 'graph' && count($series) >= 2) {
					$blocks[] = array('graph', 48, 120, $series);
				}
				return $blocks;

			case 'text':
				$lines = $box['text'] === '' ? array() : explode("\n", $box['text']);
				$rows = array();
				foreach ($lines as $line) {
					$parts = explode('|', $line, 2);
					$rows[] = array(trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : '');
				}
				if ($box['rows'] !== '') {
					$rows = array_merge($rows, self::siteRows($map, $box['rows']));
				}
				$blocks = array();
				foreach ($rows as $i => $row) {
					list($lw) = $map->myimagestringsize($font, $row[0] === '' ? ' ' : $row[0]);
					list($vw) = $map->myimagestringsize($font, $row[1] === '' ? ' ' : $row[1]);
					$blocks[] = array('row', $rowh, $lw + ($row[1] === '' ? 0 : $vw + 24), array($row[0], $row[1], $i > 0));
				}
				return $blocks;
		}
		return array();
	}

	static function drawBlock($map, $image, $b, $x, $y, $width, $font, $lineheight, $text) {
		list($kind, $height, $junk, $data) = $b;
		$outline = $map->colours['DEFAULT']['KEYOUTLINE'];

		switch ($kind) {
			case 'line':
				$map->myimagestring($image, $font, $x, $y + $lineheight + 4, $data, $text);
				return;

			case 'row':
				list($label, $value, $rule) = $data;
				if ($rule && !is_none($outline)) {
					wimageline($image, $x, $y, $x + $width, $y, $outline['gdref1']);
				}
				$base = $y + $lineheight + 5;
				if ($label !== '') {
					$map->myimagestring($image, $font, $x, $base, $label, $text);
				}
				if ($value !== '') {
					list($vw) = $map->myimagestringsize($font, $value);
					$map->myimagestring($image, $font, $x + $width - $vw, $base, $value, $text);
				}
				return;

			case 'speed':
				$mid = $y + $height / 2;
				$half = max(0.5, $data[1] / 2);
				wimagefilledrectangle($image, $x, (int) round($mid - $half), $x + 36, (int) round($mid + $half - 1), $text);
				$map->myimagestring($image, $font, $x + 48, (int) round($mid + $lineheight / 2), $data[2], $text);
				return;

			case 'band':
				list($colour, $colour2, $label) = $data;
				$c = self::allocate($image, $colour);
				wimagefilledrectangle($image, $x, $y + 4, $x + 15, $y + $height - 5, $c);
				if ($colour2 !== null) {
					// a band that blends: its upper colour on the right half
					wimagefilledrectangle($image, $x + 8, $y + 4, $x + 15, $y + $height - 5, self::allocate($image, $colour2));
				}
				$map->myimagestring($image, $font, $x + 24, $y + $lineheight + 5, $label, $text);
				return;

			case 'bar':
				self::drawBar($map, $image, $x, $y + 2, $width, 14);
				$labels = array('0%', '100%');
				$base = $y + 2 + 14 + 6 + $lineheight;
				$map->myimagestring($image, $font, $x, $base, $labels[0], $text);
				list($w) = $map->myimagestringsize($font, $labels[1]);
				$map->myimagestring($image, $font, $x + $width - $w, $base, $labels[1], $text);
				return;

			case 'total':
				$col = (int) floor(($width - 16) / 2) + 16;
				foreach ($data as $i => $v) {
					$vx = $x + $i * $col;
					$dot = self::allocate($image, $v[2]);
					wimagefilledrectangle($image, $vx, $y + $lineheight / 2 - 2, $vx + 6, $y + $lineheight / 2 + 4, $dot);
					$map->myimagestring($image, $font, $vx + 10, $y + $lineheight, $v[0], $text);
					$map->myimagestring($image, $font, $vx, $y + 2 * $lineheight + 4, $v[1], $text);
				}
				return;

			case 'graph':
				self::drawGraph($map, $image, $data, $x, $y + 4, $width, $height - 8, $outline);
				return;
		}
	}

	/** The scale as a bar, smooth in the SVG (a linear gradient) */
	static function drawBar($map, $image, $x, $y, $width, $height) {
		$map->DrawScaleBar($image, $x, $y, $x + $width - 1, $y + $height - 1, 'DEFAULT');
	}

	/** IN above the middle line and OUT under it, both on the same scale */
	static function drawGraph($map, $image, $series, $x, $y, $width, $height, $outline) {
		$peak = 0;
		foreach ($series as $p) {
			$peak = max($peak, (float) $p[1], (float) $p[2]);
		}
		$t0 = (float) $series[0][0];
		$t1 = (float) $series[count($series) - 1][0];
		$span = max(1, $t1 - $t0);
		$mid = $y + $height / 2;
		$half = $height / 2 - 1;

		foreach (array(1 => array(-1, self::IN_COLOUR), 2 => array(1, self::OUT_COLOUR)) as $k => $spec) {
			list($sign, $rgb) = $spec;
			$points = array();
			foreach ($series as $p) {
				$px = $x + $width * ((float) $p[0] - $t0) / $span;
				$v = $peak > 0 ? (float) $p[$k] / $peak : 0;
				$points[] = (int) round($px);
				$points[] = (int) round($mid + $sign * $v * $half);
			}
			$points[] = $x + $width;
			$points[] = (int) round($mid);
			$points[] = $x;
			$points[] = (int) round($mid);
			wimagefilledpolygon($image, $points, count($points) / 2, self::allocate($image, $rgb));
		}
		if (!is_none($outline)) {
			wimageline($image, $x, (int) round($mid), $x + $width, (int) round($mid), $outline['gdref1']);
		}
	}

	/** Bands of the DEFAULT scale: [colour, colour2 or null, "10-50%"] from the lowest */
	static function scaleBands($map) {
		$bands = array();
		foreach ((array) ($map->colours['DEFAULT'] ?? array()) as $band) {
			if (!is_array($band) || !empty($band['special']) || !isset($band['bottom'], $band['top'], $band['red1']) || (int) $band['red1'] < 0) {
				continue;
			}
			$two = isset($band['red2']) && (int) $band['red2'] >= 0
				? array((int) $band['red2'], (int) $band['green2'], (int) $band['blue2']) : null;
			$bands[] = array(
				array((int) $band['red1'], (int) $band['green1'], (int) $band['blue1']),
				$two,
				($band['bottom'] == $band['top'] ? '' : self::num($band['bottom']) . '-') . self::num($band['top']) . '%',
				(float) $band['bottom'],
			);
		}
		usort($bands, function ($a, $b) {
			return $a[3] <=> $b[3];
		});
		return $bands;
	}

	/** The sum of the IN and OUT rates of the links that count in the total (not SET traffic_total 0) */
	static function trafficTotal($map) {
		$in = null;
		$out = null;
		foreach ($map->links as $link) {
			if (!isset($link->a, $link->b) || $link->name === 'DEFAULT') {
				continue;
			}
			$v = isset($link->hints['traffic_total']) ? $link->hints['traffic_total'] : '1';
			if (is_scalar($v) && trim((string) $v) === '0') {
				continue;
			}
			if (is_numeric($link->bandwidth_in ?? null)) {
				$in = ($in ?? 0.0) + (float) $link->bandwidth_in;
			}
			if (is_numeric($link->bandwidth_out ?? null)) {
				$out = ($out ?? 0.0) + (float) $link->bandwidth_out;
			}
		}
		return array($in, $out);
	}

	/** Rows fed from the map: [site, node count] or [site, busiest load %], busiest first */
	static function siteRows($map, $source) {
		$sites = array();
		foreach ($map->nodes as $node) {
			if (is_null($node->x) || $node->name === 'DEFAULT') {
				continue;
			}
			$site = isset($node->hints['site']) && is_scalar($node->hints['site'])
				? trim(preg_replace('/\s+/u', ' ', (string) $node->hints['site'])) : '';
			if ($site === '') {
				continue;
			}
			if (!isset($sites[$site])) {
				$sites[$site] = array('nodes' => 0, 'load' => null);
			}
			$sites[$site]['nodes']++;
		}
		if ($source === 'sites_load') {
			foreach ($map->links as $link) {
				if (!isset($link->a, $link->b) || $link->name === 'DEFAULT') {
					continue;
				}
				$pct = max((float) $link->inpercent, (float) $link->outpercent);
				foreach (array($link->a, $link->b) as $end) {
					$site = isset($end->hints['site']) && is_scalar($end->hints['site'])
						? trim(preg_replace('/\s+/u', ' ', (string) $end->hints['site'])) : '';
					if ($site !== '' && isset($sites[$site])) {
						$sites[$site]['load'] = max((float) $sites[$site]['load'], $pct);
					}
				}
			}
		}
		$key = $source === 'sites_load' ? 'load' : 'nodes';
		uksort($sites, function ($a, $b) use ($sites, $key) {
			return array((float) $sites[$b][$key], $a) <=> array((float) $sites[$a][$key], $b);
		});
		$rows = array();
		foreach ($sites as $name => $s) {
			$rows[] = array((string) $name, $source === 'sites_load'
				? ($s['load'] === null ? '-' : self::num(round($s['load'])) . '%')
				: (string) $s['nodes']);
		}
		return $rows;
	}

	/** 40700000000 -> "40.7 Gbps" */
	static function rate($bits, $kilo) {
		$nice = nice_bandwidth($bits, $kilo ?: 1000);
		if (preg_match('/^([\d.,]+)([KMGT]?)$/', $nice, $m)) {
			return $m[1] . ' ' . ($m[2] === 'K' ? 'k' : $m[2]) . 'bps';
		}
		return $nice . ' bps';
	}

	static function num($v) {
		$v = (float) $v;
		return $v == floor($v) ? (string) (int) $v : rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
	}

	static function allocate($image, $rgb) {
		return imagecolorallocate($image, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
	}
}
