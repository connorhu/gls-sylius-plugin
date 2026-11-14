<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Packaging;

/**
 * Egy tétel vagy egy csomag mérete: a három oldal NÖVEKVŐ sorrendben (mm),
 * és a súly (g). A sorrend miatt a forgatás nem számít: két doboz akkor fér
 * egymásba, ha oldalanként sorban kisebb vagy egyenlő.
 */
final readonly class ParcelDimensions
{
    private function __construct(
        public float $shortest,
        public float $middle,
        public float $longest,
        public float $weight,
    ) {
    }

    public static function of(float $a, float $b, float $c, float $weight): self
    {
        $sides = [$a, $b, $c];
        sort($sides);

        return new self($sides[0], $sides[1], $sides[2], $weight);
    }

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    /**
     * A két tárgy egymásra rakva: a legvékonyabb oldaluk összeadódik, a
     * másik kettőből a nagyobb számít (a kotta és a könyv lapos tárgy).
     */
    public function stackedWith(self $other): self
    {
        return self::of(
            $this->shortest + $other->shortest,
            max($this->middle, $other->middle),
            max($this->longest, $other->longest),
            $this->weight + $other->weight,
        );
    }

    /** A csomagolás ráhagyása: minden oldalhoz `$mm`, a súlyhoz `$g`. */
    public function grownBy(float $mm, float $g): self
    {
        return new self($this->shortest + $mm, $this->middle + $mm, $this->longest + $mm, $this->weight + $g);
    }

    public function volume(): float
    {
        return $this->shortest * $this->middle * $this->longest;
    }

    /** @return array{float, float, float} */
    public function sorted(): array
    {
        return [$this->shortest, $this->middle, $this->longest];
    }

    /** Súly kilogrammban — a MyGLS `ParcelProperty.Weight` mértékegysége. */
    public function weightInKilograms(): float
    {
        return round($this->weight / 1000, 3);
    }
}
