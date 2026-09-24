<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Tags modules a build or quality tool loads by filename rather than by import.
 *
 * ESLint reads `eslint.config.js`, Vitest reads `vitest.config.ts`, pytest reads
 * `conftest.py` — no project code ever names them, so every one of them has an
 * in-degree of zero by construction. Without the role each is reported as an
 * unreferenced-code candidate: a self-scan of a 111-file TypeScript project
 * returned eight candidates, and all eight were configuration of this shape.
 *
 * Recognition is by filename convention only, and deliberately narrow — the
 * role suppresses dead-code candidacy, so a false match hides real dead code.
 * A module that merely reads configuration (`src/utils/config-loader.ts`) is
 * ordinary source and is left alone.
 */
final readonly class ToolConfigModuleRule implements ClassificationRule
{
    public const ROLE = 'tooling.config';

    /**
     * Extensions a config can be written in. JSON and YAML configs are data,
     * not modules, and never carry declarations to classify.
     */
    private const MODULE_EXTENSIONS = ['js', 'cjs', 'mjs', 'jsx', 'ts', 'mts', 'cts', 'tsx', 'py'];

    /**
     * Stem suffixes shared by the whole ecosystem: `<tool>.config.<ext>`
     * (vite, vitest, stryker, jest, tailwind, playwright, next, drizzle, …)
     * and the older `<tool>.conf.<ext>` (karma, protractor).
     */
    private const STEM_SUFFIXES = ['.config', '.conf'];

    /** Whole filenames that name a tool's entry file outright. */
    private const EXACT_STEMS = ['gulpfile', 'gruntfile', 'conftest', 'webpack.mix'];

    /** {@inheritDoc} */
    public function id(): string
    {
        return 'core.tooling.config.v1';
    }

    /** {@inheritDoc} */
    public function classify(NodeFact $node): array
    {
        // Declarations inside a config file are reached exactly as the file is,
        // so the role is keyed on the file rather than on the module node.
        // Evidence rejects a non-normalized path at construction, so the
        // separator here is always `/`.
        $path = $node->evidence->relativePath;
        $extended = self::toolInterface($node);
        if ($extended !== null) {
            return [
                new ClassificationFact(
                    $node->localId,
                    self::ROLE,
                    $this->id(),
                    Origin::Derived,
                    Confidence::Probable,
                    $node->evidence,
                    ['implements' => $extended],
                ),
            ];
        }
        if (!self::isToolConfigPath($path)) {
            return [];
        }

        return [
            new ClassificationFact(
                $node->localId,
                self::ROLE,
                $this->id(),
                Origin::Derived,
                Confidence::Probable,
                $node->evidence,
                ['matched_path' => $path],
            ),
        ];
    }
    /**
     * The tool interface a class implements, when a tool loads it from config.
     *
     * PHPStan reads its rules and extensions from `phpstan.neon` by class
     * name, which no PHP code does.
     */
    private static function toolInterface(NodeFact $node): ?string
    {
        if ($node->kind !== 'class' || !is_array($node->attributes['implements'] ?? null)) {
            return null;
        }
        foreach ($node->attributes['implements'] as $interface) {
            if (is_string($interface) && str_starts_with(ltrim($interface, '\\'), 'PHPStan\\')) {
                return $interface;
            }
        }

        return null;
    }

    /**
     * Whether a path is tooling configuration rather than application code.
     *
     * Public because discovery reads the same files for the paths they name as
     * setup or entry files, and two copies of a filename convention drift the
     * moment one of them gains a case. The rule owns the convention; discovery
     * asks it.
     */
    public static function isToolConfigPath(string $path): bool
    {
        $file = basename($path);
        $dot = strrpos($file, '.');
        if ($dot === false) {
            // No extension at all: `Makefile`, `LICENSE`.
            return false;
        }
        // A bare dotfile needs no special case. `strrpos` finds the LAST dot, so
        // `$dot === 0` already means the name has exactly one — and whatever
        // follows it (`gitignore`, `env`) is not a module extension, so the
        // check below rejects it. An earlier guard spelled that out and four
        // mutants survived on it, which is what unreachable logic looks like.
        $extension = strtolower(substr($file, $dot + 1));
        if (!in_array($extension, self::MODULE_EXTENSIONS, true)) {
            return false;
        }

        $stem = strtolower(substr($file, 0, $dot));
        if (in_array($stem, self::EXACT_STEMS, true)) {
            return true;
        }
        // VitePress loads `.vitepress/config.*` and `.vitepress/theme/index.*`
        // by position rather than by name, so neither carries a tool prefix.
        $lower = strtolower($path);
        if (($stem === 'config' && preg_match('#(?:^|/)\.vitepress/config\.[^/]+$#', $lower) === 1)
            || ($stem === 'index' && preg_match('#(?:^|/)\.vitepress/theme/index\.[^/]+$#', $lower) === 1)) {
            return true;
        }
        // `.eslintrc.js`, `.prettierrc.cjs`, `.babelrc.mjs`: the rc dotfile
        // convention, which predates the `.config.` one and is still common.
        // Other tools name theirs the same way without the `rc`
        // (`.markdownlint-cli2.mjs`, `.pnpmfile.cjs`), and a module whose name
        // starts with a dot is not how application code is named.
        // A stem that is exactly a suffix (`.config`) names no tool at all.
        if (str_starts_with($stem, '.') && strlen($stem) > 1 && !in_array($stem, self::STEM_SUFFIXES, true)) {
            return true;
        }
        foreach (self::STEM_SUFFIXES as $suffix) {
            // The suffix must follow a tool name — a file called exactly
            // `config.ts` is ordinary source, not a tool's own config.
            if (str_ends_with($stem, $suffix) && strlen($stem) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }
}
