<?php

declare(strict_types=1);

/**
 * Per-function complexity and length, the two budgets this report gates on.
 *
 * @return list<array{symbol: string, file: string, line: int, lines: int, complexity: int}>
 */
function phpFunctionMetrics(string $source, string $relative): array
{
    $tokens = token_get_all($source);
    $metrics = [];
    $stack = [];
    $pending = null;
    $braceDepth = 0;
    $line = 1;
    $decisions = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH, T_BOOLEAN_AND, T_BOOLEAN_OR, T_COALESCE, T_MATCH];
    foreach ($tokens as $index => $token) {
        $tokenLine = is_array($token) ? $token[2] : $line;
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $name = '{closure}@' . $tokenLine;
            for ($offset = $index + 1; isset($tokens[$offset]); ++$offset) {
                if (is_array($tokens[$offset]) && $tokens[$offset][0] === T_STRING) {
                    $name = $tokens[$offset][1];
                    break;
                }
                if ($tokens[$offset] === '(') {
                    break;
                }
            }
            $pending = ['symbol' => $name, 'file' => $relative, 'line' => $tokenLine, 'complexity' => 1];
        }
        if (is_array($token) && in_array($token[0], $decisions, true) && $stack !== []) {
            ++$stack[array_key_last($stack)]['complexity'];
        }
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            // Interpolation openers are closed by a plain '}', so they must raise the depth too.
            ++$braceDepth;
            if ($pending !== null) {
                $pending['depth'] = $braceDepth;
                $stack[] = $pending;
                $pending = null;
            }
        } elseif ($token === ';' && $pending !== null) {
            // A declaration without a body (abstract or interface method) never opens a brace.
            $pending = null;
        } elseif ($token === '}') {
            if ($stack !== [] && $stack[array_key_last($stack)]['depth'] === $braceDepth) {
                $function = array_pop($stack);
                unset($function['depth']);
                $function['lines'] = max(1, $tokenLine - $function['line'] + 1);
                $metrics[] = $function;
            }
            --$braceDepth;
        }
        $line = $tokenLine + (is_string($token) ? substr_count($token, "\n") : substr_count($token[1], "\n"));
    }
    return $metrics;
}
