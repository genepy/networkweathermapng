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
 *
 * Colours may also be "none". Zones are always drawn under every link and
 * node, in the order they appear in the file.
 */
class WeatherMapZone {
	const LABEL_POSITIONS = array('NW', 'N', 'NE', 'SW', 'S', 'SE');
	const PADDING = 4;

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
	var $configline = 0;
	var $defined_in = '';

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
		} else {
			return false;
		}

		return true;
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

		// draw straight on the canvas, so the SVG gets one <rect> rather than polygons
		if ($this->fillcolour !== null && $this->opacity > 0) {
			$fill = imagecolorallocatealpha($image, $this->fillcolour[0], $this->fillcolour[1], $this->fillcolour[2], $this->gdAlpha());
			imagefilledrectangle($image, $x1, $y1, $x2, $y2, $fill);
		}

		if ($this->outlinecolour !== null && $this->outlinewidth > 0) {
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

		$tx = $this->x + $pad;
		if ($this->labelpos == 'N' || $this->labelpos == 'S') {
			$tx = $this->x + ($this->width - $tw) / 2;
		} elseif ($this->labelpos == 'NE' || $this->labelpos == 'SE') {
			$tx = $this->x + $this->width - $pad - $tw;
		}

		$ty = $this->y + $pad + $th;
		if ($this->labelpos[0] == 'S') {
			$ty = $this->y + $this->height - $pad;
		}

		return array((int) round($tx), (int) round($ty));
	}
}
