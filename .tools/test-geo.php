<?php

/**
 * Eigenstaendiger Pruefstand fuer WHUB_Geo (Punkt-in-Polygon, Abstand zu
 * Polygon/Kreis) mit bekannten Koordinaten-Fixtures -- ohne IPS-Abhaengigkeit.
 *
 *   php .tools/test-geo.php    # 0 = alle Pruefungen bestanden
 */

// Leichtgewichtiger IPSModule-Stub (Muster aus MeterHub/.tools/test-powerinvert.php) --
// dieser Prüfstand instanziiert WarnHub nicht, ruft nur die IPS-unabhängige
// WHUB_Geo-Klasse auf. Der Stub genügt, damit "class WarnHub extends IPSModule"
// beim Einlesen der Datei außerhalb der Symcon-Laufzeit auflösbar bleibt.
class IPSModule
{
}

const VARIABLETYPE_STRING = 3;
const VARIABLETYPE_INTEGER = 1;
function IPS_VariableProfileExists(string $name): bool
{
    return true;
}

require __DIR__ . '/../WarnHub/module.php';

$failures = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok  - $label\n";
    } else {
        echo "FEHLT - $label\n";
        $failures++;
    }
}

echo "== Block 1: Haversine ==\n";
// Offenburg -> Straßburg, Luftlinie ca. 25-26 km (grobe Referenz)
$d = WHUB_Geo::haversineKm(48.4700, 7.9400, 48.5734, 7.7521);
check('Offenburg-Straßburg ca. 18-20 km (' . round($d, 1) . ' km)', $d > 15 && $d < 22);

echo "== Block 2: Punkt-in-Polygon (einfaches Quadrat) ==\n";
// Quadrat um (48.0,7.0)-(49.0,8.0)
$square = [[48.0, 7.0], [49.0, 7.0], [49.0, 8.0], [48.0, 8.0]];
check('Punkt (48.5,7.5) liegt im Quadrat', WHUB_Geo::pointInPolygon(48.5, 7.5, $square) === true);
check('Punkt (50.0,7.5) liegt außerhalb', WHUB_Geo::pointInPolygon(50.0, 7.5, $square) === false);
check('Punkt (48.5,9.0) liegt außerhalb', WHUB_Geo::pointInPolygon(48.5, 9.0, $square) === false);

echo "== Block 3: Abstand zu Polygonkante ==\n";
$d2 = WHUB_Geo::distanceToPolygonKm(48.5, 9.0, $square); // ca. 1 Grad Lon oestlich der Kante bei Lon=8
check('Abstand (48.5,9.0) zur Kante liegt zwischen 60 und 80 km (' . round($d2, 1) . ' km)', $d2 > 60 && $d2 < 80);

echo "== Block 4: distanceToAny kombiniert Polygon+Kreis, innen=0 ==\n";
$r = WHUB_Geo::distanceToAny(48.5, 7.5, [$square], []);
check('Punkt innerhalb Polygon liefert 0.0', $r === 0.0);

$circles = [['lat' => 52.5, 'lon' => 13.4, 'radiusKm' => 20.0]]; // Berlin, 20 km Radius
$r2 = WHUB_Geo::distanceToAny(52.5, 13.4, [], $circles);
check('Punkt im Kreiszentrum liefert 0.0', $r2 === 0.0);
$r3 = WHUB_Geo::distanceToAny(52.6, 13.4, [], $circles); // ca. 11 km nördlich vom Zentrum, Radius 20 km
check('Punkt 11 km vom Zentrum bei 20 km Radius liefert 0.0 (innerhalb)', $r3 === 0.0);
$r4 = WHUB_Geo::distanceToAny(53.0, 13.4, [], $circles); // ca. 55 km entfernt
check('Punkt 55 km vom Zentrum liefert > 30 km Restabstand (' . round($r4, 1) . ' km)', $r4 > 30);

echo "== Block 5: keine Geometrie -> null (Aufrufer muss das gesondert behandeln) ==\n";
$r5 = WHUB_Geo::distanceToAny(48.5, 7.5, [], []);
check('Ohne Ringe/Kreise liefert distanceToAny null', $r5 === null);

echo "== Block 6: reale DWD-CAP-Polygon-Fixture (Insel-Warnung, weit entfernter Punkt) ==\n";
$realRing = [];
foreach (explode(' ', '53.789841,7.934358 53.782001,7.973737 53.77784,7.978262 53.7753,7.96936 53.782094,7.946882 53.780047,7.912813 53.787845,7.874899 53.773693,7.867558 53.786994,7.846123 53.795274,7.868256 53.789841,7.934358') as $pair) {
    [$lat, $lon] = explode(',', $pair);
    $realRing[] = [(float) $lat, (float) $lon];
}
$farAway = WHUB_Geo::distanceToAny(48.4700, 7.9400, [$realRing], []); // Offenburg, weit von der Nordsee-Insel
check('Offenburg liegt weit außerhalb der Nordsee-Insel-Warnung (' . round($farAway, 0) . ' km)', $farAway > 400);
check('Punkt auf der Insel selbst liegt innerhalb (0.0)', WHUB_Geo::distanceToAny(53.786, 7.90, [$realRing], []) === 0.0);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
