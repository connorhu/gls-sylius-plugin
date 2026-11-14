<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Form\DataTransformer;

use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Az országonkénti alapár szövegként: soronként egy ország, a kódja után az
 * öt méretosztály ára forintban, szóközzel elválasztva —
 *
 *     DE: 7790 11890 15190 18890 23290
 *
 * — a konfigurációban `{DE: {XS: 779000, ...}}`, fillérben. Szövegmező, mert
 * a Sylius admin calculator-űrlapjában egy bővíthető gyűjtemény JS-t kívánna.
 *
 * @implements DataTransformerInterface<array<string, array<string, int>>, string>
 */
final class CountryBasePricesTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if (!\is_array($value)) {
            return '';
        }

        $lines = [];

        foreach ($value as $country => $prices) {
            $forints = array_map(
                static fn (SizeClass $size): string => (string) intdiv($prices[$size->value] ?? 0, 100),
                SizeClass::cases(),
            );

            $lines[] = \sprintf('%s: %s', $country, implode(' ', $forints));
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array<string, int>> */
    public function reverseTransform(mixed $value): array
    {
        $result = [];

        foreach (preg_split('/\R/', (string) $value) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*([A-Za-z]{2})\s*:?((?:\s+\d+){5})\s*$/', $line, $match) !== 1) {
                throw new TransformationFailedException(\sprintf('Hibás sor: „%s". Alakja: országkód, utána öt ár forintban (XS S M L XL).', trim($line)));
            }

            $prices = array_map(static fn (string $forint): int => (int) $forint * 100, preg_split('/\s+/', trim($match[2])) ?: []);
            $result[strtoupper($match[1])] = array_combine(array_map(static fn (SizeClass $size): string => $size->value, SizeClass::cases()), $prices);
        }

        return $result;
    }
}
