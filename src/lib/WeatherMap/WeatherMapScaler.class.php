<?php
/**
 * NetworkWeathermapNG - resizes a map and moves its content with it.
 *
 * Every coordinate is multiplied by the change of size: node positions
 * (and the offsets of nodes placed relative to another node), link VIA
 * points, legends, title and timestamps. Nothing has to be placed again.
 *
 * Options:
 * - keep_aspect: same factor on both axes, content centred in the new size;
 * - scale_sizes: also scale link widths and fixed icon sizes.
 *
 * Background images keep their own size.
 */

class WeatherMapScaler {
	/**
	 * @return array{0: float, 1: float, 2: float, 3: float} x factor, y factor, x shift, y shift
	 */
	static function factors($old_width, $old_height, $new_width, $new_height, $keep_aspect) {
		$sx = $new_width / max(1, $old_width);
		$sy = $new_height / max(1, $old_height);
		$dx = 0;
		$dy = 0;

		if ($keep_aspect) {
			$s  = min($sx, $sy);
			$dx = ($new_width - $old_width * $s) / 2;
			$dy = ($new_height - $old_height * $s) / 2;
			$sx = $s;
			$sy = $s;
		}

		return array($sx, $sy, $dx, $dy);
	}

	static function scale(&$map, $new_width, $new_height, $keep_aspect = false, $scale_sizes = false) {
		$new_width  = max(1, intval($new_width));
		$new_height = max(1, intval($new_height));

		list($sx, $sy, $dx, $dy) = self::factors($map->width, $map->height, $new_width, $new_height, $keep_aspect);
		self::transform($map, $sx, $sy, $dx, $dy, $new_width, $new_height, $scale_sizes);
	}

	/**
	 * A new canvas of $new_width x $new_height with everything moved by
	 * ($dx, $dy): the canvas grows or shrinks on its left and top sides
	 * while nothing moves against the others.
	 */
	static function shift(&$map, $dx, $dy, $new_width, $new_height) {
		self::transform($map, 1, 1, (int) $dx, (int) $dy, max(1, intval($new_width)), max(1, intval($new_height)), false);
	}

	/** Every position times ($sx, $sy) plus ($dx, $dy), on a canvas of the new size */
	static function transform(&$map, $sx, $sy, $dx, $dy, $new_width, $new_height, $scale_sizes) {
		$sd = sqrt($sx * $sy); // for distances that have no direction

		$px = function ($x) use ($sx, $dx) { return intval(round($x * $sx + $dx)); };
		$py = function ($y) use ($sy, $dy) { return intval(round($y * $sy + $dy)); };

		foreach ($map->nodes as $name => $node) {
			if ($name == 'DEFAULT' || is_null($node->x)) {
				continue;
			}

			if ($node->relative_to != '') {
				// Offsets from the anchor node shrink or grow, the anchor moves on its own
				if ($node->polar) {
					$map->nodes[$name]->original_x = intval(round($node->original_x * $sd));
				} else {
					$map->nodes[$name]->original_x = intval(round($node->original_x * $sx));
					$map->nodes[$name]->original_y = intval(round($node->original_y * $sy));
				}
			}

			$map->nodes[$name]->x = $px($node->x);
			$map->nodes[$name]->y = $py($node->y);

			if ($scale_sizes && $node->iconscalew > 0 && $node->iconscaleh > 0) {
				$map->nodes[$name]->iconscalew = max(1, intval(round($node->iconscalew * $sd)));
				$map->nodes[$name]->iconscaleh = max(1, intval(round($node->iconscaleh * $sd)));
			}
		}

		foreach ($map->links as $name => $link) {
			foreach ($link->vialist as $i => $via) {
				if (isset($via[2])) {
					// VIA <node> dx dy: offset from a node
					$map->links[$name]->vialist[$i][0] = intval(round($via[0] * $sx));
					$map->links[$name]->vialist[$i][1] = intval(round($via[1] * $sy));
				} else {
					$map->links[$name]->vialist[$i][0] = $px($via[0]);
					$map->links[$name]->vialist[$i][1] = $py($via[1]);
				}
			}

			if ($scale_sizes && is_numeric($link->width)) {
				$map->links[$name]->width = max(1, round($link->width * $sd, 1));
			}
		}

		// a zone keeps covering the same nodes
		foreach ($map->zones as $zone) {
			$x2 = $px($zone->x + $zone->width);
			$y2 = $py($zone->y + $zone->height);
			$zone->x = $px($zone->x);
			$zone->y = $py($zone->y);
			$zone->width = max(1, $x2 - $zone->x);
			$zone->height = max(1, $y2 - $zone->y);
			// a moved label stays at the same spot of the zone
			$zone->labeloffset = array(intval(round($zone->labeloffset[0] * $sx)), intval(round($zone->labeloffset[1] * $sy)));
			if ($scale_sizes) {
				$zone->radius = min(WeatherMapZone::RADIUS_MAX, intval(round($zone->radius * $sd)));
			}
		}

		foreach ($map->keyx as $scale => $x) {
			if ($x >= 0 && $map->keyy[$scale] >= 0) {
				$map->keyx[$scale] = $px($x);
				$map->keyy[$scale] = $py($map->keyy[$scale]);
			}
		}

		foreach (array(array('titlex', 'titley'), array('timex', 'timey'), array('mintimex', 'mintimey'), array('maxtimex', 'maxtimey')) as $pair) {
			list($kx, $ky) = $pair;
			// negative positions mean "not placed", 0 0 means "default corner"
			if ($map->$kx >= 0 && $map->$ky >= 0 && ($map->$kx != 0 || $map->$ky != 0)) {
				$map->$kx = $px($map->$kx);
				$map->$ky = $py($map->$ky);
			}
		}

		// the status bar placed on the map (SET viewer_panels / kiosk_panels ... updated:map:<x>,<y>)
		foreach (array('viewer_panels', 'kiosk_panels') as $hint) {
			if (isset($map->hints[$hint])) {
				$map->hints[$hint] = preg_replace_callback('/\bupdated:map:(\d+),(\d+)/', function ($m) use ($px, $py) {
					return 'updated:map:' . max(0, $px((int) $m[1])) . ',' . max(0, $py((int) $m[2]));
				}, (string) $map->hints[$hint]);
			}
		}

		$map->width  = $new_width;
		$map->height = $new_height;
	}
}
