<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Address;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bolt egyetlen `street` mezőt tárol, a MyGLS `Address` viszont
 * `Street` + `HouseNumber` (CSAK szám) + `HouseNumberInfo` hármast vár; a
 * 23-as hiba: „The house number cannot be 0".
 */
final class StreetSplitterTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, ?string}> */
    public static function addresses(): iterable
    {
        yield 'utca, ponttal' => ['Kossuth Lajos utca 12.', 'Kossuth Lajos utca', '12', null];
        yield 'rövidítés, pont nélkül' => ['Kossuth Lajos u. 12', 'Kossuth Lajos u.', '12', null];
        yield 'betűjel perjellel' => ['Fő utca 3/A', 'Fő utca', '3', '/A'];
        yield 'betűjel egybeírva' => ['Fő utca 3A', 'Fő utca', '3', 'A'];
        yield 'emelet, ajtó' => ['Petőfi tér 5. 2. em. 4.', 'Petőfi tér', '5', '2. em. 4.'];
        yield 'házszám-tartomány' => ['Rákóczi út 12-14.', 'Rákóczi út', '12', '-14.'];
        yield 'számmal kezdődő utcanév' => ['1. utca 5', '1. utca', '5', null];
        yield 'körút, földszint' => ['Szent István krt. 7. fsz. 1.', 'Szent István krt.', '7', 'fsz. 1.'];
        yield 'fölös szóközök' => ['  Váci   út   18  ', 'Váci út', '18', null];
        yield 'házszám nélkül' => ['Kossuth tér', 'Kossuth tér', null, null];
        yield 'helyrajzi szám: nem bontjuk' => ['Hrsz. 1234/5', 'Hrsz. 1234/5', null, null];
        yield 'nulla házszám: nem házszám' => ['Fő utca 0', 'Fő utca 0', null, null];
    }

    #[DataProvider('addresses')]
    public function testItSplitsAHungarianStreetLine(string $line, string $street, ?string $number, ?string $info): void
    {
        $split = (new StreetSplitter())->split($line);

        self::assertSame([$street, $number, $info], [$split->street, $split->houseNumber, $split->houseNumberInfo]);
    }
}
