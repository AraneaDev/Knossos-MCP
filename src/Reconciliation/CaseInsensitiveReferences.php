<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Scanner\Protocol\ScanContribution;

/**
 * Matching keys for the names a language reads without regard to case.
 *
 * PHP names its namespaces, classes, interfaces, traits, enums, functions and
 * methods case-insensitively, so `new foo()` builds the `Foo` another file
 * declares and `$x->DOTHING()` calls its `doThing`. A scanner reads one file
 * and writes each name as that file spells it; only the reconciler sees the
 * declaration in another file.
 *
 * Ids keep the spelling the declaration uses: an exact match is tried first
 * and always wins, so code that spells its names as declared keeps every id
 * it had. Only a reference that matches nothing exactly is matched again on
 * these keys, which lower-case every segment of the name except a property
 * (`$name`), which PHP reads case-sensitively. A reference that matches
 * nothing either way becomes one external, named by one spelling of it:
 * the declared type's, else the first of the spellings in use in byte order.
 */
final readonly class CaseInsensitiveReferences
{
    /** The languages whose symbol names are case-insensitive. */
    private const LANGUAGES = ['php' => true];

    /** The reference kinds whose names are case-insensitive. */
    private const KINDS = [
        'class' => true,
        'interface' => true,
        'trait' => true,
        'enum' => true,
        'function' => true,
        'namespaced_function' => true,
        'method' => true,
        'method_of_return' => true,
        'method_of_property' => true,
        'property' => true,
    ];

    /** @var array<string, string> folded reference to node id */
    private array $nodeMap;

    /** @var array<string, string> */
    private array $returnTypes;

    /** @var array<string, list<string>> */
    private array $inheritanceSources;

    /** @var array<string, string> folded type or function name to the spelling a declaration uses */
    private array $declared;

    /** @var array<string, string> folded name to the spelling references use */
    private array $referenced;

    /**
     * @param array<string, string> $nodeMap reference to node id
     * @param array<string, string> $returnTypes reference to the type reference it returns or holds
     * @param array<string, list<string>> $inheritanceSources type reference to the type references it inherits from
     * @param list<ScanContribution> $contributions
     */
    public function __construct(array $nodeMap, array $returnTypes, array $inheritanceSources, array $contributions)
    {
        $folded = [];
        $declared = [];
        foreach ($nodeMap as $reference => $id) {
            $reference = (string) $reference;
            if (!self::applies($reference)) {
                continue;
            }
            $key = self::fold($reference);
            // Two declarations spelled apart are one symbol to the language;
            // the first id in byte order is kept, whatever order they arrive in.
            $folded[$key] = isset($folded[$key]) && strcmp($folded[$key], $id) <= 0 ? $folded[$key] : $id;
            [, $kind, $canonical] = explode(':', $reference, 3);
            self::keepFirst($declared, self::typeKey($kind, $canonical), explode('::', $canonical)[0]);
        }
        $this->nodeMap = $folded;
        $this->declared = $declared;

        $types = [];
        foreach ($returnTypes as $reference => $type) {
            $types[self::fold((string) $reference)] = self::fold($type);
        }
        $this->returnTypes = $types;
        $sources = [];
        foreach ($inheritanceSources as $reference => $inherited) {
            $key = self::fold((string) $reference);
            $sources[$key] = [...($sources[$key] ?? []), ...array_map(self::fold(...), $inherited)];
        }
        $this->inheritanceSources = $sources;
        $this->referenced = self::referencedSpellings($contributions);
    }

    /** Whether a reference is in a language that reads its symbol names without regard to case. */
    public static function foldsLanguage(string $reference): bool
    {
        return isset(self::LANGUAGES[explode(':', $reference, 2)[0]]);
    }

    /** Whether a reference names a symbol its language matches without regard to case. */
    public static function applies(string $reference): bool
    {
        $parts = explode(':', $reference, 3);

        return count($parts) === 3 && isset(self::LANGUAGES[$parts[0]], self::KINDS[$parts[1]]) && $parts[2] !== '';
    }

    /**
     * The matching key of a reference: every segment of its name lower-cased
     * except a property's. A reference this does not apply to is its own key.
     */
    public static function fold(string $reference): string
    {
        if (!self::applies($reference)) {
            return $reference;
        }
        [$language, $kind, $canonical] = explode(':', $reference, 3);

        return $language . ':' . $kind . ':' . self::foldName($canonical);
    }

    /** A name with every segment lower-cased except a property's (`$name`). */
    private static function foldName(string $canonical): string
    {
        return implode('::', array_map(
            static fn(string $segment): string => str_starts_with($segment, '$') ? $segment : strtolower($segment),
            explode('::', $canonical),
        ));
    }

    /**
     * The node map keyed by matching keys.
     *
     * @return array<string, string>
     */
    public function nodeMap(): array
    {
        return $this->nodeMap;
    }

    /**
     * The declared return and property types keyed and valued by matching keys.
     *
     * @return array<string, string>
     */
    public function returnTypes(): array
    {
        return $this->returnTypes;
    }

    /**
     * The inheritance index keyed and valued by matching keys.
     *
     * @return array<string, list<string>>
     */
    public function inheritanceSources(): array
    {
        return $this->inheritanceSources;
    }

    /**
     * The one spelling an external symbol is named by, so the ways a project
     * writes one undeclared name give one node: its type as a declaration
     * spells it, else the first spelling in use, and its member likewise.
     */
    public function spelling(string $reference): string
    {
        if (!self::applies($reference)) {
            return $reference;
        }
        [$language, $kind, $canonical] = explode(':', $reference, 3);
        $member = $this->referenced['member ' . self::foldName($canonical)] ?? $canonical;
        $segments = explode('::', $member);
        $typeKey = self::typeKey($kind, $canonical);
        $segments[0] = $this->declared[$typeKey] ?? $this->referenced[$typeKey] ?? $segments[0];

        return $language . ':' . $kind . ':' . implode('::', $segments);
    }

    /**
     * The first spelling in byte order of each type, function and member name
     * the edges of a scan target.
     *
     * @param list<ScanContribution> $contributions
     * @return array<string, string>
     */
    private static function referencedSpellings(array $contributions): array
    {
        $spellings = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (!self::applies($edge->targetReference)) {
                    continue;
                }
                [, $kind, $canonical] = explode(':', $edge->targetReference, 3);
                $type = explode('::', $canonical)[0];
                self::keepFirst($spellings, self::typeKey($kind, $canonical), $type);
                if ($kind === 'namespaced_function') {
                    // Named here as written in its namespace, it may be the
                    // global function of its last segment.
                    $global = substr($type, (int) strrpos('\\' . $type, '\\'));
                    self::keepFirst($spellings, self::typeKey('function', $global), $global);
                }
                if (str_contains($canonical, '::')) {
                    self::keepFirst($spellings, 'member ' . self::foldName($canonical), $canonical);
                }
            }
        }

        return $spellings;
    }

    /**
     * The key of the type or function a reference names first: functions and
     * types are apart, since a class and a function may share a name.
     */
    private static function typeKey(string $kind, string $canonical): string
    {
        $family = ($kind === 'function' || $kind === 'namespaced_function') ? 'function ' : 'type ';

        return $family . strtolower(explode('::', $canonical)[0]);
    }

    /**
     * Keep the first spelling of a key in byte order, so the choice does not
     * depend on the order the files were read in.
     *
     * @param array<string, string> $spellings
     */
    private static function keepFirst(array &$spellings, string $key, string $spelling): void
    {
        if (!isset($spellings[$key]) || strcmp($spelling, $spellings[$key]) < 0) {
            $spellings[$key] = $spelling;
        }
    }
}
