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
    private const EXACT_STEMS = ['gulpfile', 'gruntfile', 'conftest'];

    public function id(): string
    {
        return 'core.tooling.config.v1';
    }

    public function classify(NodeFact $node): array
    {
        // Declarations inside a config file are reached exactly as the file is,
        // so the role is keyed on the file rather than on the module node.
        // Evidence rejects a non-normalized path at construction, so the
        // separator here is always `/`.
        $path = $node->evidence->relativePath;
        if (!$this->isToolConfigPath($path)) {
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

    private function isToolConfigPath(string $path): bool
    {
        $file = basename($path);
        $dot = strrpos($file, '.');
        if ($dot === false || $dot === 0 && substr_count($file, '.') === 1) {
            // No extension at all, or a bare dotfile such as `.gitignore`.
            return false;
        }
        $extension = strtolower(substr($file, $dot + 1));
        if (!in_array($extension, self::MODULE_EXTENSIONS, true)) {
            return false;
        }

        $stem = strtolower(substr($file, 0, $dot));
        if (in_array($stem, self::EXACT_STEMS, true)) {
            return true;
        }
        // `.eslintrc.js`, `.prettierrc.cjs`, `.babelrc.mjs`: the rc dotfile
        // convention, which predates the `.config.` one and is still common.
        if (str_starts_with($stem, '.') && str_ends_with($stem, 'rc')) {
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
