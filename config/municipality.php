<?php
declare(strict_types=1);

function getEscobedoBoundaryFeature(): array
{
    static $feature = null;
    if ($feature !== null) {
        return $feature;
    }

    $path = __DIR__ . '/../data/escobedo-boundary.geojson';
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('No se pudo leer el límite municipal de General Escobedo.');
    }

    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($decoded)
        || !isset($decoded['geometry']['type'], $decoded['geometry']['coordinates'])
        || !in_array($decoded['geometry']['type'], ['Polygon', 'MultiPolygon'], true)
    ) {
        throw new RuntimeException('El límite municipal de General Escobedo no tiene un formato válido.');
    }

    $feature = $decoded;
    return $feature;
}

function isInsideEscobedo(float $latitude, float $longitude): bool
{
    $geometry = getEscobedoBoundaryFeature()['geometry'];
    $polygons = $geometry['type'] === 'Polygon'
        ? [$geometry['coordinates']]
        : $geometry['coordinates'];

    foreach ($polygons as $rings) {
        if (!isset($rings[0]) || !isPointInsideBoundaryRing($latitude, $longitude, $rings[0])) {
            continue;
        }

        $insideHole = false;
        foreach (array_slice($rings, 1) as $hole) {
            if (isPointInsideBoundaryRing($latitude, $longitude, $hole)) {
                $insideHole = true;
                break;
            }
        }

        if (!$insideHole) {
            return true;
        }
    }

    return false;
}

function isPointInsideBoundaryRing(float $latitude, float $longitude, array $ring): bool
{
    $inside = false;
    $count = count($ring);
    if ($count < 3) {
        return false;
    }

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        if (!isset($ring[$i][0], $ring[$i][1], $ring[$j][0], $ring[$j][1])) {
            continue;
        }

        $x1 = (float) $ring[$j][0];
        $y1 = (float) $ring[$j][1];
        $x2 = (float) $ring[$i][0];
        $y2 = (float) $ring[$i][1];
        $cross = ($longitude - $x1) * ($y2 - $y1) - ($latitude - $y1) * ($x2 - $x1);

        if (
            abs($cross) < 1e-10
            && $longitude >= min($x1, $x2) - 1e-10
            && $longitude <= max($x1, $x2) + 1e-10
            && $latitude >= min($y1, $y2) - 1e-10
            && $latitude <= max($y1, $y2) + 1e-10
        ) {
            return true;
        }

        if (($y1 > $latitude) !== ($y2 > $latitude)) {
            $crossingLongitude = ($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1) + $x1;
            if ($longitude < $crossingLongitude) {
                $inside = !$inside;
            }
        }
    }

    return $inside;
}
