<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Address;

/**
 * Magyar címsor bontása a MyGLS hármasára (#257).
 *
 * A bolt egyetlen `street` mezőt tárol („Petőfi tér 5. 2. em. 4."), a MyGLS
 * `HouseNumber`-e viszont CSAK pozitív szám, a betűjel, a tartomány, az
 * emelet és az ajtó a `HouseNumberInfo`-ba megy.
 *
 * A szabály: a házszám az első olyan szám, ami egy betűvel vagy ponttal
 * záródó utcanév UTÁN áll (így az „1. utca 5"-ben az 5). Ami nem bontható
 * (nincs házszám, helyrajzi szám, nulla), az a teljes sorral, házszám nélkül
 * jön vissza — hogy a MyGLS így elfogadja-e, az a teszt-API mérése (#264); a
 * feladás előtti ellenőrzés házhoz szállításnál házszámot kér.
 */
final class StreetSplitter
{
    public function split(string $line): SplitStreet
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));

        if (preg_match('/\bhrsz\b/iu', $line) === 1) {
            return new SplitStreet($line);
        }

        if (preg_match('/^(?<street>.*?[\p{L}.])\s+(?<number>\d+)(?<rest>(?:[^\d].*)?)$/u', $line, $match) !== 1 ||
            (int) $match['number'] === 0) {
            return new SplitStreet($line);
        }

        $rest = trim($match['rest']);
        $rest = ltrim($rest, '.');
        $rest = trim($rest);

        return new SplitStreet(
            trim($match['street']),
            ltrim($match['number'], '0'),
            $rest === '' ? null : $rest,
        );
    }
}
