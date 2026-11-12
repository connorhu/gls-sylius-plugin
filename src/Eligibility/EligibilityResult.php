<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Eligibility;

/**
 * Az `EligibilityChecker` eredménye — a FoxPost plugin azonos nevű
 * osztályának alakja, hogy az admin felület (#257) ugyanúgy mutathassa.
 */
final readonly class EligibilityResult
{
    /**
     * @param list<array{code: string, message: string}> $errors
     */
    private function __construct(
        public bool $eligible,
        public array $errors,
    ) {
    }

    public static function eligible(): self
    {
        return new self(true, []);
    }

    /** @param list<array{code: string, message: string}> $errors */
    public static function ineligible(array $errors): self
    {
        return new self(false, $errors);
    }
}
