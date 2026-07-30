<?php

declare(strict_types=1);

/**
 * Shared docblock inspection for the documentation gates.
 *
 * Both tools/api-documentation-check.php and tools/docstring-report.php need the
 * same answer to "is this symbol documented", and they disagreed: the API gate
 * matched a regex with `/s` and a lazy `.*?`, which could start at an *earlier*
 * docblock — the interface's class-level one — swallow everything between, and end
 * at the closing delimiter before the method. A method with only `@param` annotations therefore
 * borrowed the class summary and passed, so the gate's contract count was partly
 * hollow.
 *
 * Tokenising instead of matching is the whole point: `function`, `/**`, and `*\/`
 * all appear inside strings and heredocs in this codebase, and a gate that
 * miscounts is worse than no gate because it reports confidence it has not earned.
 */

/**
 * Whether a docblock says anything beyond annotations.
 *
 * The shared definition of "documented": `@return int` restates a signature, so
 * counting it would let either gate's number rise while a reader learned nothing.
 */
function docblockHasSummary(string $documentation): bool
{
    foreach (preg_split('/\R/', $documentation) ?: [] as $line) {
        $line = trim($line, " \t*/");
        if ($line !== '' && !str_starts_with($line, '@')) {
            return true;
        }
    }

    return false;
}

/**
 * The docblock immediately preceding a declaration, or null when there is none.
 *
 * Scans back over whitespace, comments, visibility and other modifiers, and any
 * attribute groups, because all of those legitimately sit between a docblock and
 * the declaration it documents. Anything else ends the search, so a docblock
 * belonging to a different symbol is never borrowed.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function precedingDocBlock(array $tokens, int $declarationIndex): ?string
{
    $skippable = [T_WHITESPACE, T_COMMENT, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_ABSTRACT, T_FINAL];
    if (defined('T_READONLY')) {
        $skippable[] = T_READONLY;
    }
    for ($offset = $declarationIndex - 1; $offset >= 0; --$offset) {
        $token = $tokens[$offset];
        if ($token === ']') {
            $depth = 1;
            while (--$offset >= 0 && $depth > 0) {
                $inner = $tokens[$offset];
                if ($inner === ']') {
                    ++$depth;
                } elseif ($inner === '[' || (is_array($inner) && $inner[0] === T_ATTRIBUTE)) {
                    --$depth;
                }
            }
            continue;
        }
        if (!is_array($token)) {
            return null;
        }
        if ($token[0] === T_DOC_COMMENT) {
            return $token[1];
        }
        if (!in_array($token[0], $skippable, true)) {
            return null;
        }
    }

    return null;
}

/**
 * Every named function and method in a file, with its documentation state.
 *
 * Closures and arrow functions are skipped: they have no name to document.
 * Constructors are reported like any other method; callers decide whether to
 * count them.
 *
 * @return list<array{name: string, line: int, documented: bool, visibility: string}>
 */
function declaredFunctions(string $source): array
{
    $tokens = token_get_all($source);
    $functions = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $name = null;
        for ($offset = $index + 1; isset($tokens[$offset]); ++$offset) {
            $next = $tokens[$offset];
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($next) && $next[0] === T_STRING) {
                $name = $next[1];
            }
            break;
        }
        if ($name === null) {
            continue;
        }
        $documentation = precedingDocBlock($tokens, $index);
        $functions[] = [
            'name' => $name,
            'line' => $token[2],
            'documented' => $documentation !== null && docblockHasSummary($documentation),
            'visibility' => functionVisibility($tokens, $index),
        ];
    }

    return $functions;
}

/**
 * The declared visibility of a function, defaulting to public.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function functionVisibility(array $tokens, int $functionIndex): string
{
    for ($offset = $functionIndex - 1; $offset >= 0; --$offset) {
        $token = $tokens[$offset];
        if (!is_array($token)) {
            break;
        }
        if ($token[0] === T_PRIVATE) {
            return 'private';
        }
        if ($token[0] === T_PROTECTED) {
            return 'protected';
        }
        if (!in_array($token[0], [T_WHITESPACE, T_PUBLIC, T_STATIC, T_FINAL, T_ABSTRACT, T_COMMENT, T_DOC_COMMENT], true)) {
            break;
        }
    }

    return 'public';
}

/**
 * Every class, interface, trait, and enum in a file, with its documentation state.
 *
 * A type without a docblock never states why it exists, which is the one thing a
 * reader cannot recover from the code itself.
 *
 * @return list<array{name: string, line: int, documented: bool, kind: string}>
 */
function declaredTypes(string $source): array
{
    $tokens = token_get_all($source);
    $kinds = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait'];
    if (defined('T_ENUM')) {
        $kinds[T_ENUM] = 'enum';
    }
    $types = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !isset($kinds[$token[0]])) {
            continue;
        }
        // `Foo::class` and anonymous classes produce T_CLASS with no name after it.
        $name = null;
        for ($offset = $index + 1; isset($tokens[$offset]); ++$offset) {
            $next = $tokens[$offset];
            if (is_array($next) && $next[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($next) && $next[0] === T_STRING) {
                $name = $next[1];
            }
            break;
        }
        if ($name === null) {
            continue;
        }
        $documentation = precedingDocBlock($tokens, $index);
        $types[] = [
            'name' => $name,
            'line' => $token[2],
            'documented' => $documentation !== null && docblockHasSummary($documentation),
            'kind' => $kinds[$token[0]],
        ];
    }

    return $types;
}

/**
 * The first sentence of a symbol's docblock, for generated reference tables.
 *
 * Returns null rather than a placeholder so a caller can decide how to render an
 * undocumented symbol.
 */
function docblockSummaryLine(?string $documentation): ?string
{
    if ($documentation === null) {
        return null;
    }
    foreach (preg_split('/\R/', $documentation) ?: [] as $line) {
        $line = trim($line, " \t/*");
        if ($line !== '' && !str_starts_with($line, '@')) {
            return rtrim($line, '.');
        }
    }

    return null;
}
