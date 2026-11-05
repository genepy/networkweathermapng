<?php
/**
 * Geographic placement of a map on a basemap (Web Mercator).
 *
 * A map with a basemap names the point at the centre of its canvas
 * (SET basemap_center <lat> <lon>) and a web map zoom level
 * (SET basemap_zoom <z>) at which one map pixel is one pixel of the web
 * map. Zoom levels count 512-pixel world tiles, as MapLibre GL does, so
 * the whole world is 512 * 2^z pixels wide. A node with SET geo <lat> <lon>
 * is placed on its point; POSITION is still written, for the tools that
 * do not know about the basemap.
 *
 * The same formulas are in src/public/assets/js/basemap.js.
 */
class WeatherMapGeo
{
	const TILE = 512;
	const MAX_LAT = 85.05112878;

	/** The frame of a map: [lat, lon, zoom], or null when it has no basemap or no valid frame */
	static function frame($map)
	{
		$url = isset($map->hints['basemap']) ? trim((string) $map->hints['basemap']) : '';
		if ($url === '') {
			return null;
		}
		$centre = self::latLon(isset($map->hints['basemap_center']) ? $map->hints['basemap_center'] : '');
		$zoom = isset($map->hints['basemap_zoom']) ? $map->hints['basemap_zoom'] : '';
		if ($centre === null || !is_numeric($zoom) || $zoom < 0 || $zoom > 22) {
			return null;
		}
		return array($centre[0], $centre[1], (float) $zoom);
	}

	/** "lat lon" (or "lat,lon") as [lat, lon], null when it is not a point */
	static function latLon($text)
	{
		$parts = preg_split('/[\s,;]+/', trim((string) $text));
		if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
			return null;
		}
		$lat = (float) $parts[0];
		$lon = (float) $parts[1];
		if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
			return null;
		}
		return array($lat, $lon);
	}

	/** A point as SET geo writes it: six decimals, about ten centimetres */
	static function format($lat, $lon)
	{
		return self::num($lat) . ' ' . self::num($lon);
	}

	private static function num($v)
	{
		$s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
		return $s === '-0' ? '0' : $s;
	}

	/** Longitude and latitude as world coordinates from 0 to 1 */
	static function world($lat, $lon)
	{
		$lat = max(-self::MAX_LAT, min(self::MAX_LAT, $lat));
		$s = sin(deg2rad($lat));
		return array(($lon + 180) / 360, 0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI));
	}

	/** World coordinates from 0 to 1 as [lat, lon] */
	static function unworld($wx, $wy)
	{
		$lon = $wx * 360 - 180;
		$lat = rad2deg(atan(sinh(M_PI * (1 - 2 * $wy))));
		return array($lat, $lon);
	}

	/** Map pixels of a point, for a frame [lat, lon, zoom] and a canvas size */
	static function project($frame, $width, $height, $lat, $lon)
	{
		$size = self::TILE * pow(2, $frame[2]);
		$c = self::world($frame[0], $frame[1]);
		$p = self::world($lat, $lon);
		return array($width / 2 + ($p[0] - $c[0]) * $size, $height / 2 + ($p[1] - $c[1]) * $size);
	}

	/** The point under map pixels, as [lat, lon] */
	static function unproject($frame, $width, $height, $x, $y)
	{
		$size = self::TILE * pow(2, $frame[2]);
		$c = self::world($frame[0], $frame[1]);
		return self::unworld($c[0] + ($x - $width / 2) / $size, $c[1] + ($y - $height / 2) / $size);
	}

	/** The point of a node (SET geo), or null */
	static function nodePoint($node)
	{
		return isset($node->hints['geo']) ? self::latLon($node->hints['geo']) : null;
	}

	/**
	 * Put the nodes that have a point where it is on the basemap. They no
	 * longer follow a parent node (POSITION parent x y).
	 */
	static function placeNodes($map)
	{
		$frame = self::frame($map);
		if ($frame === null) {
			return;
		}
		foreach ($map->nodes as $node) {
			if ($node->name === 'DEFAULT' || $node->name === ':: DEFAULT ::') {
				continue;
			}
			$p = self::nodePoint($node);
			if ($p === null) {
				continue;
			}
			list($x, $y) = self::project($frame, $map->width, $map->height, $p[0], $p[1]);
			$node->x = (int) round($x);
			$node->y = (int) round($y);
			$node->relative_to = '';
			$node->relative_resolved = true;
			$node->polar = false;
			$node->original_x = $node->x;
			$node->original_y = $node->y;
		}
	}

	/** After a node moved: its point follows, when it has one and the map a frame */
	static function follow($map, $node)
	{
		$frame = self::frame($map);
		if ($frame === null || self::nodePoint($node) === null) {
			return;
		}
		list($lat, $lon) = self::unproject($frame, $map->width, $map->height, $node->x, $node->y);
		$node->hints['geo'] = self::format($lat, $lon);
	}
}
