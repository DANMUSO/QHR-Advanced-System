<?php

namespace App\Modules\Payroll\Services\Calculation;

use InvalidArgumentException;

/**
 * Safe arithmetic evaluator for pay component formulas (no eval()).
 *
 * Grammar: numbers, variables (basic, gross, days_worked, working_days, days_in_period, basic_pay, overtime_hours),
 * + - * / %, parentheses, unary minus, comparisons (> < >= <= == !=) and the functions
 * min(a, b, ...), max(a, b, ...), round(x[, decimals]), floor(x), ceil(x), abs(x), if(condition, then, else).
 * Example: "min(basic * 0.15, 20000)" or "if(days_worked >= 20, 3000, 0)".
 */
final class FormulaEvaluator
{
    public const VARIABLES = ['basic', 'gross', 'days_worked', 'working_days', 'days_in_period', 'basic_pay', 'overtime_hours'];

    private const FUNCTIONS = ['min', 'max', 'round', 'floor', 'ceil', 'abs', 'if'];

    private array $tokens = [];

    private int $pos = 0;

    private array $vars = [];

    public function evaluate(string $formula, array $variables = []): float
    {
        $this->tokens = $this->tokenize($formula);
        $this->pos = 0;
        $this->vars = array_change_key_case($variables, CASE_LOWER);
        if (! $this->tokens) {
            throw new InvalidArgumentException('Formula is empty.');
        }
        $value = $this->comparison();
        if ($this->pos < count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected "'.$this->tokens[$this->pos][1].'" in formula.');
        }

        return is_finite($value) ? $value : throw new InvalidArgumentException('Formula result is not a finite number.');
    }

    /** Returns null when valid, otherwise an error message. Evaluates with sample values. */
    public function validate(string $formula): ?string
    {
        try {
            $this->evaluate($formula, array_fill_keys(self::VARIABLES, 1000));

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (\DivisionByZeroError) {
            return null; // valid syntax; division by zero only for specific values
        }
    }

    private function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($s[$i + 1]))) {
                $start = $i;
                while ($i < $len && (ctype_digit($s[$i]) || $s[$i] === '.')) {
                    $i++;
                }
                $num = substr($s, $start, $i - $start);
                if (substr_count($num, '.') > 1) {
                    throw new InvalidArgumentException("Invalid number \"$num\".");
                }
                $tokens[] = ['num', (float) $num];
            } elseif (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['id', strtolower(substr($s, $start, $i - $start))];
            } else {
                $two = substr($s, $i, 2);
                if (in_array($two, ['>=', '<=', '==', '!='], true)) {
                    $tokens[] = ['op', $two];
                    $i += 2;
                } elseif (str_contains('+-*/%()<>,', $c)) {
                    $tokens[] = ['op', $c];
                    $i++;
                } else {
                    throw new InvalidArgumentException("Invalid character \"$c\" in formula.");
                }
            }
        }

        return $tokens;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function accept(string $op): bool
    {
        $t = $this->peek();
        if ($t && $t[0] === 'op' && $t[1] === $op) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function expect(string $op): void
    {
        if (! $this->accept($op)) {
            throw new InvalidArgumentException("Expected \"$op\" in formula.");
        }
    }

    private function comparison(): float
    {
        $left = $this->additive();
        foreach (['>=', '<=', '==', '!=', '>', '<'] as $op) {
            if ($this->accept($op)) {
                $right = $this->additive();

                return (float) match ($op) {
                    '>=' => $left >= $right, '<=' => $left <= $right,
                    '==' => abs($left - $right) < 1e-9, '!=' => abs($left - $right) >= 1e-9,
                    '>' => $left > $right, '<' => $left < $right,
                };
            }
        }

        return $left;
    }

    private function additive(): float
    {
        $value = $this->term();
        while (true) {
            if ($this->accept('+')) {
                $value += $this->term();
            } elseif ($this->accept('-')) {
                $value -= $this->term();
            } else {
                return $value;
            }
        }
    }

    private function term(): float
    {
        $value = $this->unary();
        while (true) {
            if ($this->accept('*')) {
                $value *= $this->unary();
            } elseif ($this->accept('/')) {
                $d = $this->unary();
                if ($d == 0.0) {
                    throw new \DivisionByZeroError('Division by zero in formula.');
                }
                $value /= $d;
            } elseif ($this->accept('%')) {
                $d = $this->unary();
                if ($d == 0.0) {
                    throw new \DivisionByZeroError('Division by zero in formula.');
                }
                $value = fmod($value, $d);
            } else {
                return $value;
            }
        }
    }

    private function unary(): float
    {
        if ($this->accept('-')) {
            return -$this->unary();
        }
        if ($this->accept('+')) {
            return $this->unary();
        }

        return $this->primary();
    }

    private function primary(): float
    {
        $t = $this->peek();
        if (! $t) {
            throw new InvalidArgumentException('Formula ended unexpectedly.');
        }
        if ($t[0] === 'num') {
            $this->pos++;

            return $t[1];
        }
        if ($t[0] === 'op' && $t[1] === '(') {
            $this->pos++;
            $v = $this->comparison();
            $this->expect(')');

            return $v;
        }
        if ($t[0] === 'id') {
            $this->pos++;
            $name = $t[1];
            if (in_array($name, self::FUNCTIONS, true)) {
                return $this->call($name);
            }
            if (! in_array($name, self::VARIABLES, true) && ! array_key_exists($name, $this->vars)) {
                throw new InvalidArgumentException("Unknown variable \"$name\". Allowed: ".implode(', ', self::VARIABLES).'.');
            }

            return (float) ($this->vars[$name] ?? 0);
        }

        throw new InvalidArgumentException('Unexpected "'.$t[1].'" in formula.');
    }

    private function call(string $fn): float
    {
        $this->expect('(');
        $args = [];
        if (! $this->accept(')')) {
            do {
                $args[] = $this->comparison();
            } while ($this->accept(','));
            $this->expect(')');
        }
        $n = count($args);
        $need = fn (int $min, int $max) => $n >= $min && $n <= $max
            ? null : throw new InvalidArgumentException("Function $fn() takes ".($min === $max ? $min : "$min–$max").' argument(s).');

        switch ($fn) {
            case 'min': $need(1, 50);

                return min($args);
            case 'max': $need(1, 50);

                return max($args);
            case 'round': $need(1, 2);

                return round($args[0], (int) ($args[1] ?? 0));
            case 'floor': $need(1, 1);

                return floor($args[0]);
            case 'ceil': $need(1, 1);

                return ceil($args[0]);
            case 'abs': $need(1, 1);

                return abs($args[0]);
            default: $need(3, 3); // if

                return $args[0] != 0.0 ? $args[1] : $args[2];
        }
    }
}
