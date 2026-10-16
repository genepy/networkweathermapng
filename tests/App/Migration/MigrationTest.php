<?php

declare(strict_types=1);

namespace Tests\App\Migration;

use App\Services\Migration\InterfaceDirectory;
use App\Services\Migration\MapMigrator;
use App\Services\Migration\TargetMatcher;
use App\Services\Migration\TargetParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    public static function targets(): array
    {
        return [
            'observium rrd' => ['/opt/observium/rrd/core-rtr1/port-3.rrd', 'rrd', 'core-rtr1', 3, true],
            'rrd with ds' => ['rrd:/data/rrd/sw1/port-12.rrd:INOCTETS:OUTOCTETS', 'rrd', 'sw1', 12, true],
            'cacti rrd' => ['/var/lib/cacti/rra/core_rtr1_traffic_in_42.rrd', 'rrd', 'core_rtr1', null, true],
            'snmp negated' => ['-snmp:public:rtr.example.net:.1.3.6.1.2.1.2.2.1.10.7:.1.3.6.1.2.1.2.2.1.16.7', 'snmp', 'rtr.example.net', 7, true],
            'snmp hc scaled' => ['8*snmp2c:public:rtr:1.3.6.1.2.1.31.1.1.1.6.5:1.3.6.1.2.1.31.1.1.1.10.5', 'snmp', 'rtr', 5, true],
            'mrtg' => ['/mrtg/rtr1_4.log', 'mrtg', 'rtr1', 4, true],
            'external' => ['!/usr/bin/get.sh', 'external', '', null, true],
            'static' => ['static:100M:200M', 'static', '', null, false],
            'observium' => ['observium:port:12', 'observium', '', null, false],
            'cacti' => ['cactihost:12', 'cacti', '', null, true],
        ];
    }

    #[DataProvider('targets')]
    public function testParser(string $raw, string $kind, string $host, ?int $ifIndex, bool $convert): void
    {
        $p = TargetParser::parse($raw);
        $this->assertSame($kind, $p['kind']);
        $this->assertSame($host, $p['host']);
        $this->assertSame($ifIndex, $p['ifIndex']);
        $this->assertSame($convert, $p['convert']);
    }

    public function testMatcherByIfIndexAndHostSpelling(): void
    {
        $m = new TargetMatcher($this->directory());

        $r = $m->match(TargetParser::parse('/opt/observium/rrd/core-rtr1/port-3.rrd'));
        $this->assertSame('matched', $r['status']);
        $this->assertSame('observium:port:103', $r['choice']['target']);

        // FQDN in the old target, short name in the directory
        $r = $m->match(TargetParser::parse('snmp:public:core-rtr1.example.net:.1.3.6.1.2.1.2.2.1.10.7:.1.3.6.1.2.1.2.2.1.16.7'));
        $this->assertSame('matched', $r['status']);
        $this->assertSame('observium:port:107', $r['choice']['target']);

        // Cacti file name: underscores for dashes, no interface
        $r = $m->match(TargetParser::parse('/rra/core_rtr1_traffic_in_42.rrd'));
        $this->assertSame('ambiguous', $r['status']);
        $this->assertCount(2, $r['candidates']);

        $r = $m->match(TargetParser::parse('/opt/observium/rrd/core-rtr1/port-99.rrd'));
        $this->assertSame('unmatched', $r['status']);

        $r = $m->match(TargetParser::parse('/opt/observium/rrd/nowhere/port-1.rrd'));
        $this->assertSame('unmatched', $r['status']);
        $this->assertNull($r['host']);
    }

    public function testAnalyze(): void
    {
        $report = $this->migrator()->analyze($this->conf());

        $this->assertSame('Core network', $report['title']);
        $this->assertSame(['nodes' => 3, 'links' => 3], $report['counts']);
        $this->assertSame(['INCLUDE /etc/weathermap/common.conf'], $report['removed']);
        $this->assertCount(2, $report['outputs']);
        $this->assertSame([2, 3, 4], $report['fonts']);

        $images = array_column($report['images'], 'status', 'item');
        $this->assertSame('relinked', $images['NODE rtr1']);
        $this->assertSame('missing', $images['NODE sw1']);

        $targets = array_column($report['targets'], null, 'key');
        $this->assertSame('matched', $targets['LINK:rtr1-sw1:0']['match']['status']);
        $this->assertSame('matched', $targets['LINK:rtr1-sw2:0']['match']['status']);
        $this->assertSame('external', $targets['LINK:sw1-sw2:0']['parsed']['kind']);
    }

    public function testConvert(): void
    {
        $migrator = $this->migrator();
        $conf = $this->conf();
        $report = $migrator->analyze($conf);
        $out = $migrator->convert($conf, $report, [], ['fonts' => true]);

        $this->assertStringNotContainsString('OUTPUTFILE', $out);
        $this->assertStringNotContainsString('INCLUDE', $out);
        $this->assertStringContainsString('ICON objects/Router.png', $out);
        $this->assertStringContainsString('ICON images/not-there.png', $out);
        $this->assertStringContainsString('TARGET observium:port:103', $out);
        $this->assertStringContainsString('TARGET -observium:port:107', $out);
        $this->assertStringContainsString('TARGET "!/usr/local/bin/get-traffic.sh sw1"', $out);
        $this->assertStringContainsString('VIA 250 60', $out);
        $this->assertMatchesRegularExpression('/FONTDEFINE 102 fonts\/DejaVuSansMono.ttf 7/', $out);
        $this->assertMatchesRegularExpression('/TITLEFONT 103/', $out);
        $this->assertDoesNotMatchRegularExpression('/^\s*(BW|LABEL|KEY|TITLE)FONT [1-5]\s*$/m', $out);

        // the result reads back and renders
        $again = $migrator->analyze($out);
        $this->assertSame([], $again['fonts']);
        $this->assertSame([], $again['removed']);
        $this->assertStringStartsWith("\x89PNG", $migrator->renderPng($out));
        $this->assertStringContainsString('<svg', $migrator->renderSvg($out));
    }

    public function testManualChoiceAndKeep(): void
    {
        $migrator = $this->migrator();
        $conf = $this->conf();
        $report = $migrator->analyze($conf);
        $out = $migrator->convert($conf, $report, [
            'LINK:rtr1-sw1:0' => 'keep',
            'LINK:rtr1-sw2:0' => ['target' => 'zabbix:1:2', 'datasource' => ['sourceId' => 4]],
        ]);

        $this->assertStringContainsString('TARGET /opt/observium/rrd/core-rtr1/port-3.rrd', $out);
        $this->assertStringContainsString('TARGET -zabbix:1:2', $out);
        $this->assertStringContainsString('SET datasource {"sourceId":4}', $out);
        $this->assertStringContainsString('TITLEFONT 3', $out);
    }

    public function testUnrecognisedLinesAreKept(): void
    {
        $migrator = $this->migrator();
        $conf = "WIDTH 400\nHEIGHT 300\nFUTUREGLOBAL on\n\n"
            . "NODE a\n\tPOSITION 50 50\n\tNODEMAGIC 3\n\n"
            . "NODE b\n\tPOSITION 300 200\n\n"
            . "LINK a-b\n\tNODES a b\n\tLINKMAGIC blue\n\tTARGET static:1M:2M\n";

        $report = $migrator->analyze($conf);
        $this->assertCount(3, array_filter($report['warnings'], fn ($w) => str_starts_with($w, 'Unrecognised config')));

        $out = $migrator->convert($conf, $report);

        $this->assertMatchesRegularExpression('/^FUTUREGLOBAL on$/m', $out);
        $this->assertMatchesRegularExpression('/^NODE a\n\tNODEMAGIC 3$/m', $out);
        $this->assertMatchesRegularExpression('/^LINK a-b\n\tLINKMAGIC blue$/m', $out);
        $this->assertLessThan(strpos($out, 'NODE a'), strpos($out, 'FUTUREGLOBAL'));

        // the converted map still reads, with the same lines kept
        $again = $migrator->analyze($out);
        $this->assertSame(['nodes' => 2, 'links' => 1], $again['counts']);
        $this->assertSame($migrator->unrecognised($conf, $report['warnings']), [
            '' => ['FUTUREGLOBAL on'],
            'NODE a' => ["\tNODEMAGIC 3"],
            'LINK a-b' => ["\tLINKMAGIC blue"],
        ]);
    }

    private function conf(): string
    {
        return (string) file_get_contents(TESTS_ROOT . '/fixtures/migration/legacy.conf');
    }

    private function migrator(): MapMigrator
    {
        return new MapMigrator(dirname(TESTS_ROOT) . '/src/public', new TargetMatcher($this->directory()));
    }

    private function directory(): InterfaceDirectory
    {
        return new class implements InterfaceDirectory {
            public function hosts(string $search): array
            {
                $all = [['id' => '1', 'name' => 'core-rtr1', 'alias' => ''], ['id' => '2', 'name' => 'edge-sw9', 'alias' => '']];
                return array_values(array_filter($all, fn ($h) => stripos($h['name'], $search) !== false));
            }

            public function interfaces(string $hostId): array
            {
                if ($hostId !== '1') {
                    return [];
                }
                return [
                    ['id' => '103', 'label' => 'Gi0/3', 'target' => 'observium:port:103', 'bandwidth' => '1G', 'ifIndex' => 3, 'names' => ['Gi0/3']],
                    ['id' => '107', 'label' => 'Gi0/7', 'target' => 'observium:port:107', 'bandwidth' => '1G', 'ifIndex' => 7, 'names' => ['Gi0/7']],
                ];
            }
        };
    }
}
