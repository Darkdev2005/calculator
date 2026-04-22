<?php

namespace App\Exceptions;

use RuntimeException;

class CalculatorException extends RuntimeException
{
    public static function invalidOperation(string $operation): self
    {
        return new self("Unsupported operation: {$operation}.");
    }

    public static function divideByZero(): self
    {
        return new self('Division by zero is not allowed.');
    }

    public static function invalidOperand(string $operandName): self
    {
        return new self("The {$operandName} operand must be numeric.");
    }
}
