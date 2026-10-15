#
# snmpsim variation module: an interface counter (octets or packets) that
# follows a smooth traffic curve, so graphs and map links move like real ones.
#
# Record format:
#   <oid>|65:traffic|rate=<units/s>,swing=<0..1>,period=<seconds>,phase=<radians>
#
# The rate oscillates between rate*(1-swing) and rate*(1+swing) over one
# period. The counter is the exact integral of that rate over wall-clock time,
# so it never goes backwards, even across a restart of the simulator.
# With swing=0 and rate=100 it also serves as sysUpTime (TimeTicks).
#
import math
import time

MODULUS = {"65": 2**32, "67": 2**32, "70": 2**64}


def init(**context):
    pass


def variate(oid, tag, value, **context):
    if not context["nextFlag"] and not context["exactMatch"]:
        return context["origOid"], tag, context["errorStatus"]

    if context["setFlag"]:
        return context["origOid"], tag, context["errorStatus"]

    params = dict(item.split("=", 1) for item in value.split(","))
    rate = float(params["rate"])
    swing = float(params.get("swing", 0.3))
    period = float(params.get("period", 3600))
    phase = float(params.get("phase", 0))

    omega = 2 * math.pi / period
    now = time.time()
    total = rate * now - rate * swing / omega * math.cos(omega * now + phase)

    return oid, tag, int(total) % MODULUS.get(tag, 2**64)


def shutdown(**context):
    pass
