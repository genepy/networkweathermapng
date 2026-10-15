#!/usr/bin/env python3
"""Writes the snmpsim record file of one simulated device.

    make-snmprec.py devices.json <device> > public.snmprec

The device answers the SNMPv2-MIB system group and the IF-MIB interface
tables. Traffic counters use the "traffic" variation module; "in" and "out"
in devices.json are average rates in Mbit/s, "phase" (radians) shifts the
daily-like curve: give both ends of a link the same phase.
"""
import json
import sys

SYSTEM = "1.3.6.1.2.1.1"
IF_TABLE = "1.3.6.1.2.1.2.2.1"
IFX_TABLE = "1.3.6.1.2.1.31.1.1.1"
NET_SNMP_LINUX = "1.3.6.1.4.1.8072.3.2.10"


def records(name, device):
    rows = [
        (f"{SYSTEM}.1.0", "4", device["descr"]),
        (f"{SYSTEM}.2.0", "6", NET_SNMP_LINUX),
        (f"{SYSTEM}.3.0", "67:traffic", "rate=100,swing=0"),
        (f"{SYSTEM}.4.0", "4", "noc@example.net"),
        (f"{SYSTEM}.5.0", "4", name),
        (f"{SYSTEM}.6.0", "4", device.get("location", "")),
        (f"{SYSTEM}.7.0", "2", "6"),
        ("1.3.6.1.2.1.2.1.0", "2", str(len(device["ports"]))),
    ]

    for index, port in enumerate(device["ports"], start=1):
        speed_bps = port["speed"] * 1_000_000
        # Ports peak at different times; both ends of a link share a phase
        phase = f"{port.get('phase', (index * 1.7) % 6.28):.2f}"
        octets = {
            direction: f"rate={port[direction] * 125_000},swing=0.35,period=3600,phase={phase}"
            for direction in ("in", "out")
        }
        packets = {
            direction: f"rate={max(1, port[direction] * 125_000 // 800)},swing=0.35,period=3600,phase={phase}"
            for direction in ("in", "out")
        }
        mac = f"02005e{len(name):02x}{index:04x}"

        rows += [
            (f"{IF_TABLE}.1.{index}", "2", str(index)),
            (f"{IF_TABLE}.2.{index}", "4", port["name"]),
            (f"{IF_TABLE}.3.{index}", "2", "6"),
            (f"{IF_TABLE}.4.{index}", "2", "1500"),
            (f"{IF_TABLE}.5.{index}", "66", str(min(speed_bps, 4294967295))),
            (f"{IF_TABLE}.6.{index}", "4x", mac),
            (f"{IF_TABLE}.7.{index}", "2", "1"),
            (f"{IF_TABLE}.8.{index}", "2", "1"),
            (f"{IF_TABLE}.9.{index}", "67", "0"),
            (f"{IF_TABLE}.10.{index}", "65:traffic", octets["in"]),
            (f"{IF_TABLE}.11.{index}", "65:traffic", packets["in"]),
            (f"{IF_TABLE}.13.{index}", "65", "0"),
            (f"{IF_TABLE}.14.{index}", "65", "0"),
            (f"{IF_TABLE}.16.{index}", "65:traffic", octets["out"]),
            (f"{IF_TABLE}.17.{index}", "65:traffic", packets["out"]),
            (f"{IF_TABLE}.19.{index}", "65", "0"),
            (f"{IF_TABLE}.20.{index}", "65", "0"),
            (f"{IFX_TABLE}.1.{index}", "4", port["name"]),
            (f"{IFX_TABLE}.6.{index}", "70:traffic", octets["in"]),
            (f"{IFX_TABLE}.7.{index}", "70:traffic", packets["in"]),
            (f"{IFX_TABLE}.10.{index}", "70:traffic", octets["out"]),
            (f"{IFX_TABLE}.11.{index}", "70:traffic", packets["out"]),
            (f"{IFX_TABLE}.15.{index}", "66", str(port["speed"])),
            (f"{IFX_TABLE}.18.{index}", "4", port.get("alias", "")),
        ]

    # snmprec files must be sorted by OID
    rows.sort(key=lambda row: tuple(int(part) for part in row[0].split(".")))
    return rows


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)

    with open(sys.argv[1], encoding="utf-8") as handle:
        devices = json.load(handle)

    name = sys.argv[2]
    if name not in devices:
        sys.exit(f"Unknown device {name!r}; known: {', '.join(devices)}")

    for oid, tag, value in records(name, devices[name]):
        print(f"{oid}|{tag}|{value}")


if __name__ == "__main__":
    main()
