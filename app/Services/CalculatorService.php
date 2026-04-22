<?php

namespace App\Services;

use App\Exceptions\CalculatorException;

class CalculatorService
{
    /**
     * @return array{result: float, symbol: string}
     */
    public function calculate(string $operation, mixed $leftOperand, mixed $rightOperand): array
    {
        if (! is_numeric($leftOperand)) {
            throw CalculatorException::invalidOperand('left');
        }

        if (! is_numeric($rightOperand)) {
            throw CalculatorException::invalidOperand('right');
        }

        $left = (float) $leftOperand;
        $right = (float) $rightOperand;

        return match ($operation) {
            'add' => ['result' => $left + $right, 'symbol' => '+'],
            'subtract' => ['result' => $left - $right, 'symbol' => '-'],
            'multiply' => ['result' => $left * $right, 'symbol' => '*'],
            'divide' => $this->divide($left, $right),
            default => throw CalculatorException::invalidOperation($operation),
        };
    }

    /**
     * @return array{result: float, symbol: string}
     */
    private function divide(float $left, float $right): array
    {
        if (abs($right) < PHP_FLOAT_EPSILON) {
            throw CalculatorException::divideByZero();
        }

        return ['result' => $left / $right, 'symbol' => '/'];
    }
}
