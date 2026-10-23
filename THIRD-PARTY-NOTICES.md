# Third-party notices and credits

NetworkWeathermapNG is released under the MIT License (see [LICENSE](LICENSE)).
It builds on other people's work. This file lists every piece of code, image,
font and package that comes from elsewhere, who made it, and under which
license. When you add something from outside the project, add it here too.

## Projects this one is based on

| Project | Authors | License | Link |
|---|---|---|---|
| PHP Network Weathermap 0.98 | Howard Jones and contributors, 2005-2019 | MIT | https://github.com/howardjones/network-weathermap |
| Weathermap plugin for Cacti | The Cacti Group, Inc., 2022-2026, after Howard Jones | MIT (see note) | https://github.com/Cacti/plugin_weathermap |
| NetworkWeathermapNG (original Zabbix edition) | Artur Zdolinski (nchekwa), 2025-2026 | MIT | https://github.com/nchekwa/networkweathermapng |

Note on the Cacti plugin: its repository holds an MIT `LICENSE` file and a GPL-2
`COPYING` file, and some of its files carry a GPL-2 header. Only files that
carry the MIT header, or that come unchanged from Howard Jones' MIT repository,
are used here. None of its GPL-2 files is included.

## Code taken from those projects

Every file below keeps (or was given) the MIT header of its authors.

| File | Origin | Changes made here |
|---|---|---|
| `src/lib/WeatherMap/WeatherMap.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/WeatherMap.functions.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/WeatherMapLink.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/WeatherMapNode.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/HTML_ImageMap.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/WMLine.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/WMPoint.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/WMVector.class.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/geometry.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/ds-common.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/editor.inc.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_static.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/DataSources/WeatherMapDataSource_static.php` | Cacti plugin, after Howard Jones | See the git history |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_rrd.php` | Cacti plugin, after Howard Jones | rrdtool path from `RRDTOOL_PATH`, Cacti poller table only inside Cacti |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_snmp.php` | Cacti plugin, after Howard Jones | None |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_snmp2c.php` | Howard Jones (`SNMP2c.php`), through the Cacti plugin | MIT header added, PHP 8 fixes (undeclared property, counter) |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_snmp3.php` | Howard Jones (`SNMP3.php`), through the Cacti plugin | MIT header added |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_mrtg.php` | Cacti plugin, after Howard Jones | None |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_tabfile.php` | Cacti plugin, after Howard Jones | None |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_fping.php` | Cacti plugin, after Howard Jones | fping path from `FPING_PATH` or the usual places |
| `src/lib/WeatherMap/lib/datasources/WeatherMapDataSource_time.php` | Cacti plugin, after Howard Jones | PHP 8 fix (match counter) |

`src/lib/WeatherMap/compat.php` is written for this project: it stands in for
the Cacti functions these files call.

The rest of the code (application, Zabbix and Observium data sources, SVG
renderer, scaler, zones, migration, editor and viewer scripts and styles) was
written for NetworkWeathermapNG and falls under its MIT license.

## Images

| Files | Authors | License | Link |
|---|---|---|---|
| `src/public/objects/`: `Cloud-Filled`, `Cloud-line`, `Firewall`, `HPMini`, `Host`, `PAD`, `Router`, `WorkgroupSwitch`, `filecabinet`, `updown_0` to `updown_2`, and the `*-ball-64` and `hollow32` icons | Howard Jones and contributors, shipped with Network Weathermap and the Cacti plugin | MIT | https://github.com/howardjones/network-weathermap |
| `src/public/objects/`: `arista-*`, `cisco-*`, `cloud-v1`, `cloud-v2`, `cumulus-*`, `f5-*`, `generic-*`, `juniper-*`, `viptela-*`, `vmware-compute` | Network-Icons-SVG by aci686, first shared by BobTheButcher; converted to PNG by the Cacti plugin | No license file; the authors say the icons "can be used freely" | https://github.com/aci686/Network-Icons-SVG |
| `src/public/objects/net/` | Drawn for NetworkWeathermapNG | MIT | |
| `src/public/backgrounds/`: `Gradient_BK_800.png`, `background1_950x625_197.jpg`, `blueboard1024.png`, `bw_gradient_1024.png`, `uk1024.png` | Howard Jones and contributors | MIT | https://github.com/howardjones/network-weathermap |
| `src/public/backgrounds/uk1280_720.png` | The Cacti Group, Inc. (Cacti plugin) | MIT | https://github.com/Cacti/plugin_weathermap |
| `src/public/assets/icons/favicon.svg` | Drawn for NetworkWeathermapNG | MIT | |

## Fonts

| Files | Authors | License | Link |
|---|---|---|---|
| `src/public/fonts/DejaVuSansMono.ttf`, `DejaVuSansMono-Bold.ttf` | DejaVu fonts team, based on Bitstream Vera (Bitstream, Inc.) | Bitstream Vera license, DejaVu changes in the public domain; full text in `src/public/fonts/DejaVu-LICENSE.txt` | https://dejavu-fonts.github.io/ |

## PHP packages (Composer)

Installed by Composer, not stored in this repository. Each package ships its
license file under `vendor/<package>/`.

Used by the application:

| Package | License |
|---|---|
| vlucas/phpdotenv | BSD-3-Clause |
| graham-campbell/result-type | MIT |
| phpoption/phpoption | Apache-2.0 |
| symfony/polyfill-ctype | MIT |
| symfony/polyfill-mbstring | MIT |
| symfony/polyfill-php80 | MIT |

Used only for development and tests (not in the Docker image):

| Package | License |
|---|---|
| phpunit/phpunit and its sebastian/*, phpunit/*, phar-io/* and theseer/tokenizer components | BSD-3-Clause |
| nikic/php-parser | BSD-3-Clause |
| myclabs/deep-copy | MIT |
| staabm/side-effects-detector | MIT |
| phpstan/phpstan | MIT |

## Docker image

The image is built from the official `php:8.5-fpm-alpine` image (PHP License
3.01 for PHP, Alpine Linux packages under their own licenses) and adds Composer
(MIT) and these Alpine packages:

| Package | Used for | License |
|---|---|---|
| nginx | Web server | BSD-2-Clause |
| supervisor | Process manager | Repoze (BSD-style) |
| curl | Health check | curl (MIT-style) |
| git, zip, unzip | Composer install | GPL-2.0, Info-ZIP |
| libpng, libjpeg-turbo, freetype | Images (GD) | libpng, IJG / BSD-3-Clause, FreeType License |
| libzip, icu, oniguruma, sqlite | PHP extensions | BSD-3-Clause, Unicode, BSD-2-Clause, public domain |
| ttf-dejavu, fontconfig | Fonts | Bitstream Vera, MIT-style |
| rrdtool | RRD data source | GPL-2.0-or-later |
| rsvg-convert (librsvg) | SVG to PNG | LGPL-2.1-or-later |
| net-snmp | SNMP data sources | Net-SNMP (BSD-style) |
| fping | fping data source | fping (BSD-style) |

rrdtool, rsvg-convert, fping and git run as separate programs; the application
calls them and does not include their code.

## Test environment (`deploy/testbed`)

Not part of the application. It downloads and runs, without redistributing
them: Observium Community Edition (QPL-1.0, https://www.observium.org),
MariaDB 11.4 (GPL-2.0), snmpsim 1.2.2 and pysmi 2.0.0 (BSD-2-Clause), on the
official Alpine and Python images.

## Data sources this application talks to

Observium (Community under QPL-1.0, Subscription under its commercial terms)
and Zabbix (AGPL-3.0 since 7.0, GPL-2.0 before) are separate products. The
application only reads them through their API, database or RRD files and
contains none of their code.
