<?php

namespace App\Services;

use App\Exceptions\CalculatorException;

class CalculatorService
{
    /**
     * @return array{result: float, symbol: string}
     */
    public function calculate(string $operation, mixed $leftOperand, mixed $rightOperand, mixed $precision = null): array
    {
        if (! is_numeric($leftOperand)) {
            throw CalculatorException::invalidOperand('left');
        }

        if (! is_numeric($rightOperand)) {
            throw CalculatorException::invalidOperand('right');
        }

        $left = (float) $leftOperand;
        $right = (float) $rightOperand;
        $resolvedPrecision = $this->resolvePrecision($precision);

        $calculated = match ($operation) {
            'add' => ['result' => $left + $right, 'symbol' => '+'],
            'subtract' => ['result' => $left - $right, 'symbol' => '-'],
            'multiply' => ['result' => $left * $right, 'symbol' => '*'],
            'divide' => $this->divide($left, $right),
            'power' => ['result' => $left ** $right, 'symbol' => '^'],
            'modulo' => $this->modulo($left, $right),
            default => throw CalculatorException::invalidOperation($operation),
        };

        if ($resolvedPrecision !== null) {
            $calculated['result'] = round($calculated['result'], $resolvedPrecision);
        }

        return $calculated;
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

    /**
     * @return array{result: float, symbol: string}
     */
    private function modulo(float $left, float $right): array
    {
        if (abs($right) < PHP_FLOAT_EPSILON) {
            throw CalculatorException::moduloByZero();
        }

        return ['result' => fmod($left, $right), 'symbol' => '%'];
    }

    private function resolvePrecision(mixed $precision): ?int
    {
        if ($precision === null || $precision === '') {
            return null;
        }

        $validatedPrecision = filter_var($precision, FILTER_VALIDATE_INT);

        if ($validatedPrecision === false || $validatedPrecision < 0 || $validatedPrecision > 10) {
            throw CalculatorException::invalidPrecision();
        }

        return $validatedPrecision;
    }
}
