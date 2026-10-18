<?php

namespace App\Enums;

use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

enum ProductUnit: string
{
    case Piece = 'piece';
    case Pair = 'pair';
    case Set = 'set';
    case Pack = 'pack';
    case Box = 'box';
    case Litre = 'litre';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether a quantity in this unit may be fractional. Litres are measured, to the three decimal
     * places every quantity column holds; everything else is counted in whole units.
     */
    public function allowsFraction(): bool
    {
        return $this === self::Litre;
    }

    /** The smallest step a quantity in this unit moves by, as the form should offer it. */
    public function step(): string
    {
        return $this->allowsFraction() ? '0.001' : '1';
    }

    /** Whether $quantity is expressible in this unit. Fixed-precision; never a float comparison. */
    public function accepts(string $quantity): bool
    {
        return $this->allowsFraction() || Quantity::isWhole($quantity);
    }

    /**
     * Refuses a quantity this unit cannot hold, naming the item so the operator knows which line
     * to fix. The message is the same wherever a quantity is entered.
     *
     * @throws ValidationException
     */
    public function assertAccepts(string $quantity, string $field, string $item): void
    {
        if (! $this->accepts($quantity)) {
            throw ValidationException::withMessages([
                $field => "{$item} is counted in whole units ({$this->value}). Enter a whole number.",
            ]);
        }
    }
}
