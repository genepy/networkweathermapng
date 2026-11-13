<?php
/**
 * A zone: a labelled rectangle drawn behind links and nodes, used to group
 * the equipment of one site, room or area.
 *
 *   ZONE paris
 *       POSITION 20 30
 *       SIZE 400 250
 *       LABEL Paris DC
 *       LABELFONT 3
 *       LABELPOS NW
 *       FILLCOLOR 235 242 255
 *       OUTLINECOLOR 120 140 180
 *       LABELFONTCOLOR 40 60 100
 *       OPACITY 100
 *       OUTLINEWIDTH 1
 *       OUTLINESTYLE dashed
 *       SHAPE rounded
 *       RADIUS 16
 *       LABELOFFSET 10 -4
 *
 * SHAPE is rect (the default), rounded (corners of RADIUS pixels) or
 * ellipse. LABELOFFSET moves the label from its LABELPOS place.
 * Colours may also be "none". Zones are always drawn under every link and
 * node, in the order they appear in the file.
 */
class WeatherMapZone {
	const LABEL_POSITIONS = array('NW', 'N', 'NE', 'SW', 'S', 'SE');
	const PADDING = 4;
	const SHAPES = array('rect', 'rounded', 'ellipse');
	const RADIUS_MAX = 500;
	/** How far LABELOFFSET may move the label, in pixels */
	const LABEL_OFFSET_MAX = 5000;

	var $name;
	var $x = 0;
	var $y = 0;
	var $width = 200;
	var $height = 120;
	var $label = '';
	var $labelfont = 3;
	var $labelpos = 'NW';
	var $fillcolour = array(235, 242, 255);
	var $outlinecolour = array(120, 140, 180);
	var $labelfontcolour = array(40, 60, 100);
	var $opacity = 100;
	var $outlinewidth = 1;
	var $outlinestyle = 'solid';
	var $shape = 'rect';
	/** Corner radius of a rounded zone */
	var $radius = 12;
	/** [dx, dy] from the place LABELPOS gives the label */
	var $labeloffset = array(0, 0);
	var $configline = 0;
	var $defined_in = '';
	/** SET lines of the block, such as the editor's grouping */
	var $hints = array();

	function __construct($name = '') {
		$this->name = $name;
	}

	function my_type() {
		return 'ZONE';
	}

	/**
	 * Read one line of the zone's block. Returns false when the line is
	 * not a zone keyword.
	 */
	function ParseLine($line) {
		$line = trim($line);

		if (preg_match('/^POSITION\s+([-+]?\d+)\s+([-+]?\d+)$/i', $line, $m)) {
			$this->x = (int) $m[1];
			$this->y = (int) $m[2];
		} elseif (preg_match('/^SIZE\s+(\d+)\s+(\d+)$/i', $line, $m)) {
			$this->width = max(1, (int) $m[1]);
			$this->height = max(1, (int) $m[2]);
		} elseif (preg_match('/^LABEL\s*$/i', $line)) {
			$this->label = '';
		} elseif (preg_match('/^LABEL\s+(.*)$/i', $line, $m)) {
			$this->label = trim($m[1]);
		} elseif (preg_match('/^LABELFONT\s+(\d+)$/i', $line, $m)) {
			$this->labelfont = (int) $m[1];
		} elseif (preg_match('/^LABELPOS\s+(NW|N|NE|SW|S|SE)$/i', $line, $m)) {
			$this->labelpos = strtoupper($m[1]);
		} elseif (preg_match('/^(FILL|OUTLINE|LABELFONT)COLOR\s+(\d+)\s+(\d+)\s+(\d+)$/i', $line, $m)) {
			$this->{$this->colourField($m[1])} = array(min(255, (int) $m[2]), min(255, (int) $m[3]), min(255, (int) $m[4]));
		} elseif (preg_match('/^(FILL|OUTLINE|LABELFONT)COLOR\s+none$/i', $line, $m)) {
			$this->{$this->colourField($m[1])} = null;
		} elseif (preg_match('/^OPACITY\s+(\d+)$/i', $line, $m)) {
			$this->opacity = min(100, (int) $m[1]);
		} elseif (preg_match('/^OUTLINEWIDTH\s+(\d+)$/i', $line, $m)) {
			$this->outlinewidth = min(20, (int) $m[1]);
		} elseif (preg_match('/^OUTLINESTYLE\s+(solid|dashed)$/i', $line, $m)) {
			$this->outlinestyle = strtolower($m[1]);
		} elseif (preg_match('/^SHAPE\s+(rect|rounded|ellipse)$/i', $line, $m)) {
			$this->shape = strtolower($m[1]);
		} elseif (preg_match('/^RADIUS\s+(\d+)$/i', $line, $m)) {
			$this->radius = min(self::RADIUS_MAX, (int) $m[1]);
		} elseif (preg_match('/^LABELOFFSET\s+([-+]?\d+)\s+([-+]?\d+)$/i', $line, $m)) {
			$this->labeloffset = array(self::clampOffset((int) $m[1]), self::clampOffset((int) $m[2]));
		} elseif (preg_match('/^SET\s+(\S+)\s+(.*)$/i', $line, $m)) {
			$this->hints[$m[1]] = trim($m[2]);
		} else {
			return false;
		}

		return true;
	}

	static function clampOffset($v) {
		return max(-self::LABEL_OFFSET_MAX, min(self::LABEL_OFFSET_MAX, (int) $v));
	}

	private function colourField($keyword) {
		return strtolower($keyword) . 'colour';
	}

	/** The zone's block, only with the values that differ from the defaults. */
	function WriteConfig() {
		$d = new WeatherMapZone();
		$out = "ZONE {$this->name}\n";
		$out .= sprintf("\tPOSITION %d %d\n", $this->x, $this->y);
		$out .= sprintf("\tSIZE %d %d\n", $this->width, $this->height);

		if ($this->label !== '') {
			$out .= "\tLABEL {$this->label}\n";
		}
		if ($this->labelfont != $d->labelfont) {
			$out .= "\tLABELFONT {$this->labelfont}\n";
		}
		if ($this->labelpos != $d->labelpos) {
			$out .= "\tLABELPOS {$this->labelpos}\n";
		}
		if ($this->labeloffset[0] || $this->labeloffset[1]) {
			$out .= sprintf("\tLABELOFFSET %d %d\n", $this->labeloffset[0], $this->labeloffset[1]);
		}

		foreach (array('fill' => 'FILL', 'outline' => 'OUTLINE', 'labelfont' => 'LABELFONT') as $field => $keyword) {
			$value = $this->{$field . 'colour'};
			if ($value !== $d->{$field . 'colour'}) {
				$out .= "\t{$keyword}COLOR " . ($value === null ? 'none' : implode(' ', $value)) . "\n";
			}
		}

		if ($this->opacity != $d->opacity) {
			$out .= "\tOPACITY {$this->opacity}\n";
		}
		if ($this->outlinewidth != $d->outlinewidth) {
			$out .= "\tOUTLINEWIDTH {$this->outlinewidth}\n";
		}
		if ($this->outlinestyle != $d->outlinestyle) {
			$out .= "\tOUTLINESTYLE {$this->outlinestyle}\n";
		}
		if ($this->shape != $d->shape) {
			$out .= "\tSHAPE {$this->shape}\n";
		}
		if ($this->radius != $d->radius) {
			$out .= "\tRADIUS {$this->radius}\n";
		}
		foreach ($this->hints as $name => $value) {
			$out .= "\tSET {$name} {$value}\n";
		}

		return $out . "\n";
	}

	/** Fill alpha for GD: 0 is opaque, 127 fully transparent. */
	function gdAlpha() {
		return 127 - (int) round($this->opacity * 1.27);
	}

	/**
	 * Draw the zone on the GD canvas and, when an SVG is being collected,
	 * record it there as well.
	 */
	function Draw($image, &$map) {
		$x1 = (int) $this->x;
		$y1 = (int) $this->y;
		$x2 = $x1 + (int) $this->width;
		$y2 = $y1 + (int) $this->height;

		$svg = WeatherMapSvg::$active;
		if ($svg && $svg->accepts($image)) {
			$svg->zone($this);
		}

		if ($this->shape != 'rect') {
			$this->drawShape($image);
		}

		// draw straight on the canvas, so the SVG gets one <rect> rather than polygons
		if ($this->shape == 'rect' && $this->fillcolour !== null && $this->opacity > 0) {
			$fill = imagecolorallocatealpha($image, $this->fillcolour[0], $this->fillcolour[1], $this->fillcolour[2], $this->gdAlpha());
			imagefilledrectangle($image, $x1, $y1, $x2, $y2, $fill);
		}

		if ($this->shape == 'rect' && $this->outlinecolour !== null && $this->outlinewidth > 0) {
			$outline = imagecolorallocate($image, $this->outlinecolour[0], $this->outlinecolour[1], $this->outlinecolour[2]);
			$w = $this->outlinewidth;
			$this->edge($image, $x1, $y1, $x2, $y1 + $w - 1, $outline);
			$this->edge($image, $x1, $y2 - $w + 1, $x2, $y2, $outline);
			$this->edge($image, $x1, $y1, $x1 + $w - 1, $y2, $outline);
			$this->edge($image, $x2 - $w + 1, $y1, $x2, $y2, $outline);
		}

		if ($this->label !== '' && $this->labelfontcolour !== null) {
			list($tx, $ty) = $this->labelPosition($map);
			$colour = myimagecolorallocate($image, $this->labelfontcolour[0], $this->labelfontcolour[1], $this->labelfontcolour[2]);
			if ($svg) {
				$svg->role = 'zone-label';
			}
			$map->myimagestring($image, $this->labelfont, $tx, $ty, $this->label, $colour);
			if ($svg) {
				$svg->role = null;
			}
		}
	}

	/** The corner radius actually drawn: 0 unless the zone is rounded, at most half a side */
	function cornerRadius() {
		if ($this->shape != 'rounded') {
			return 0;
		}
		return max(0, min((float) $this->radius, ($this->width + 1) / 2, ($this->height + 1) / 2));
	}

	/**
	 * The outline of a rounded or elliptic zone as a polygon, [x, y, ...],
	 * moved inwards by $inset pixels. GD draws the pixels of both ends, so
	 * the shape spans width + 1 by height + 1 pixels, like the rectangle.
	 */
	function polygon($inset = 0) {
		$x1 = $this->x + $inset;
		$y1 = $this->y + $inset;
		$x2 = $this->x + $this->width + 1 - $inset;
		$y2 = $this->y + $this->height + 1 - $inset;
		$pts = array();

		if ($this->shape == 'ellipse') {
			$cx = ($x1 + $x2) / 2;
			$cy = ($y1 + $y2) / 2;
			$rx = max(0.5, ($x2 - $x1) / 2);
			$ry = max(0.5, ($y2 - $y1) / 2);
			$steps = (int) max(24, min(180, ceil(M_PI * ($rx + $ry) / 6)));
			for ($i = 0; $i < $steps; $i++) {
				$a = 2 * M_PI * $i / $steps;
				$pts[] = $cx + $rx * cos($a);
				$pts[] = $cy + $ry * sin($a);
			}
			return $pts;
		}

		$r = max(0, min($this->cornerRadius() - $inset, ($x2 - $x1) / 2, ($y2 - $y1) / 2));
		$steps = (int) max(1, min(24, ceil($r / 3)));
		// corner centres, clockwise from the top left, with their start angle
		$corners = array(
			array($x1 + $r, $y1 + $r, M_PI),
			array($x2 - $r, $y1 + $r, 1.5 * M_PI),
			array($x2 - $r, $y2 - $r, 0),
			array($x1 + $r, $y2 - $r, 0.5 * M_PI),
		);
		foreach ($corners as $c) {
			for ($i = 0; $i <= $steps; $i++) {
				$a = $c[2] + 0.5 * M_PI * $i / $steps;
				$pts[] = $c[0] + $r * cos($a);
				$pts[] = $c[1] + $r * sin($a);
			}
		}
		return $pts;
	}

	/** Fill and outline of a rounded or elliptic zone, as polygons */
	private function drawShape($image) {
		if ($this->fillcolour !== null && $this->opacity > 0) {
			$fill = imagecolorallocatealpha($image, $this->fillcolour[0], $this->fillcolour[1], $this->fillcolour[2], $this->gdAlpha());
			imagefilledpolygon($image, array_map('intval', array_map('round', $this->polygon())), $fill);
		}

		if ($this->outlinecolour === null || $this->outlinewidth <= 0) {
			return;
		}
		$outline = imagecolorallocate($image, $this->outlinecolour[0], $this->outlinecolour[1], $this->outlinecolour[2]);
		// the line sits inside the shape, like the outline of a rectangle
		$pts = $this->polygon($this->outlinewidth / 2);
		$pts[] = $pts[0];
		$pts[] = $pts[1];
		imagesetthickness($image, $this->outlinewidth);
		$dash = 6 + 2 * $this->outlinewidth;
		$walked = 0;
		for ($i = 0; $i + 3 < count($pts); $i += 2) {
			$ax = $pts[$i];
			$ay = $pts[$i + 1];
			$bx = $pts[$i + 2];
			$by = $pts[$i + 3];
			$len = hypot($bx - $ax, $by - $ay);
			if ($this->outlinestyle != 'dashed') {
				imageline($image, (int) round($ax), (int) round($ay), (int) round($bx), (int) round($by), $outline);
				continue;
			}
			// dashes run on along the whole outline, across the corners
			$p = 0;
			while ($p < $len) {
				$inDash = fmod($walked + $p, 2 * $dash) < $dash;
				$next = min($len, $p + ($inDash ? $dash : 2 * $dash) - fmod($walked + $p, 2 * $dash));
				if ($inDash && $len > 0) {
					imageline($image,
						(int) round($ax + ($bx - $ax) * $p / $len), (int) round($ay + ($by - $ay) * $p / $len),
						(int) round($ax + ($bx - $ax) * $next / $len), (int) round($ay + ($by - $ay) * $next / $len), $outline);
				}
				$p = max($next, $p + 0.01);
			}
			$walked += $len;
		}
		imagesetthickness($image, 1);
	}

	/** One side of the outline, solid or in dashes. */
	private function edge($image, $x1, $y1, $x2, $y2, $colour) {
		if ($this->outlinestyle != 'dashed') {
			imagefilledrectangle($image, $x1, $y1, $x2, $y2, $colour);
			return;
		}

		$horizontal = ($x2 - $x1) >= ($y2 - $y1);
		$length = $horizontal ? $x2 - $x1 : $y2 - $y1;
		$dash = 6 + 2 * $this->outlinewidth;
		// only the dashes that can land on the canvas, so a huge zone stays cheap
		$start = $horizontal ? $x1 : $y1;
		$canvas = $horizontal ? imagesx($image) : imagesy($image);
		$length = min($length, $canvas - $start);
		$first = $start < 0 ? intdiv(-$start, 2 * $dash) * 2 * $dash : 0;
		for ($p = $first; $p <= $length; $p += 2 * $dash) {
			$end = min($length, $p + $dash - 1);
			if ($horizontal) {
				imagefilledrectangle($image, $x1 + $p, $y1, $x1 + $end, $y2, $colour);
			} else {
				imagefilledrectangle($image, $x1, $y1 + $p, $x2, $y1 + $end, $colour);
			}
		}
	}

	/** Where myimagestring() should put the label: left end of the baseline. */
	function labelPosition(&$map) {
		list($tw, $th) = $map->myimagestringsize($this->labelfont, $this->label);
		$pad = self::PADDING + $this->outlinewidth;
		// keep the label off the curve: inside the corner arcs, or inside
		// the rectangle the ellipse is drawn around
		$padx = $pad;
		$pady = $pad;
		if ($this->shape == 'rounded') {
			$padx += $this->cornerRadius() * (1 - M_SQRT1_2);
			$pady = $padx;
		} elseif ($this->shape == 'ellipse') {
			$padx += $this->width * (1 - M_SQRT1_2) / 2;
			$pady += $this->height * (1 - M_SQRT1_2) / 2;
		}

		$tx = $this->x + $padx;
		if ($this->labelpos == 'N' || $this->labelpos == 'S') {
			$tx = $this->x + ($this->width - $tw) / 2;
		} elseif ($this->labelpos == 'NE' || $this->labelpos == 'SE') {
			$tx = $this->x + $this->width - $padx - $tw;
		}

		$ty = $this->y + $pady + $th;
		if ($this->labelpos[0] == 'S') {
			$ty = $this->y + $this->height - $pady;
		}

		return array((int) round($tx) + $this->labeloffset[0], (int) round($ty) + $this->labeloffset[1]);
	}
}
