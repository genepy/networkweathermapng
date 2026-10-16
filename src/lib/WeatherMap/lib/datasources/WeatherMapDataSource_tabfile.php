<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2022-2026 The Cacti Group, Inc.                           |
 |                                                                         |
 | Based on the Original Plugin developed by Howard Jones                  |
 |                                                                         |
 | Copyright (C) 2005-2022 Howard Jones and contributors                   |
 |                                                                         |
 | Permission is hereby granted, free of charge, to any person obtaining   |
 | a copy of this software and associated documentation files              |
 | (the "Software"), to deal in the Software without restriction,          |
 | including without limitation the rights to use, copy, modify, merge,    |
 | publish, distribute, sublicense, and/or sell copies of the Software,    |
 | and to permit persons to whom the Software is furnished to do so,       |
 | subject to the following conditions:                                    |
 |                                                                         |
 | The above copyright notice and this permission notice shall be          |
 | included in all copies or substantial portions of the Software.         |
 |                                                                         |
 | THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,         |
 | EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES         |
 | OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND                |
 | NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS     |
 | BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN      |
 | ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN       |
 | CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE        |
 | SOFTWARE.                                                               |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | Extensions to Howard Jones' original work are designed, written, and    |
 | maintained by the Cacti Group.                                          |
 |                                                                         |
 | Howard Jones was the original author of Weathermap.  You can reach      |
 | him at: howie@thingy.com                                                |
 +-------------------------------------------------------------------------+
 | http://www.network-weathermap.com/                                      |
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

// Sample Pluggable datasource for PHP Weathermap 0.9
// - read a pair of values from a database, and return it

// TARGET dbplug:databasename:username:pass:hostkey

class WeatherMapDataSource_tabfile extends WeatherMapDataSource {
	/**
	 * @param string $targetstring
	 * @return bool
	 */
	function Recognise($targetstring) {
		if (preg_match("/\.(tsv|txt)$/",$targetstring,$matches)) {
			return true;
		} else {
			return false;
		}
	}

	// function ReadData($targetstring, $configline, $itemtype, $itemname, $map)
	/**
	 * @param string $targetstring
	 * @param WeatherMap $map
	 * @param WeatherMapItem $item
	 * @return mixed
	 */
	function ReadData($targetstring, &$map, &$item) {
		$data[IN]  = null;
		$data[OUT] = null;
		$data_time = 0;
		$itemname  = $item->name;

		$matches = 0;

		// a local file, or a page fetched over http(s); other stream wrappers
		// (php://, phar://, data:, ftp://...) have no business in a TARGET
		if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $targetstring, $scheme) && !in_array(strtolower($scheme[1]), ['http', 'https'], true)) {
			wm_warn("TabText ReadData: unsupported address $targetstring, use a file path or an http(s) URL [WMTABDATA10]");

			return [null, null, 0];
		}

		$fd = fopen($targetstring, 'r');

		if ($fd) {
			while (!feof($fd)) {
				$buffer = (string) fgets($fd, 4096);

				// strip out any Windows line-endings that have gotten in here
				$buffer = str_replace("\r", '', $buffer);

				if (preg_match("/^" . preg_quote($itemname, '/') . "\t(\d+\.?\d*[KMGT]*)\t(\d+\.?\d*[KMGT]*)/", $buffer, $matches)) {
					$data[IN]  = unformat_number($matches[1]);
					$data[OUT] = unformat_number($matches[2]);
				}
			}

			// a URL has no modification time
			if (!preg_match('/^[a-z]+:\/\//i', $targetstring)) {
				$data_time = (int) filemtime($targetstring);
			}
		} else {
			// some error code to go in here
			wm_debug("TabText ReadData: Couldn't open ($targetstring)");
		}

		wm_debug('TabText ReadData: Returning (' . ($data[IN] === null ? 'NULL' : $data[IN]) . ',' . ($data[OUT] === null ? 'NULL' : $data[OUT]) . ",$data_time)");

		return ([$data[IN], $data[OUT], $data_time]);
	}
}
