<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Scanner\Protocol\ScanContribution;

/**
 * Turns an edge's target reference into the node it reaches, for one reconcile.
 *
 * A scanner reads one file at a time, so many of the targets it names are
 * deferred: a member of whatever a factory returns, a member a trait or parent
 * declares, a function PHP may find in the namespace or globally, every class
 * under a runtime-built namespace prefix. Each is settled here against every
 * file's declarations, return types and inheritance edges, which only the
 * reconciler holds together.
 *
 * Built once per reconcile from the node map and the contributions. The
 * reference indexes and the case-insensitive matching keys are built on the
 * first edge that needs them, so a reconcile that never asks pays nothing.
 */
final class EdgeTargetResolver
{
    /**
     * Kinds a scanner may name interchangeably when a type appears in a type
     * position (a parameter, a property, a return, a static access target).
     *
     * A scanner reads one file at a time: seeing `Payable $x`, it cannot know
     * whether `Payable` is declared as a class, an interface, a trait, or an
     * enum elsewhere, so the PHP worker emits every such reference as `class`.
     * Resolution is by exact reference string, so without this list the lookup
     * misses the real declaration and a phantom `external_class` twin is
     * fabricated beside it — leaving every interface and enum in a PHP graph
     * with an in-degree of zero however heavily it is used.
     *
     * Order is fixed so a resolution never depends on scan order.
     */
    private const TYPE_KINDS = ['class', 'interface', 'trait', 'enum'];

    /**
     * Kinds that name a member of a type, written `<type>::<member>`, and so
     * may be satisfied by a trait the type uses or a type it inherits from
     * rather than by the type itself.
     */
    private const MEMBER_KINDS = ['method', 'property'];

    /**
     * Edge kinds that put another type's members in scope on the source type,
     * in PHP's own resolution order: a trait method shadows an inherited one.
     */
    private const INHERITANCE_KINDS = ['uses_trait', 'extends', 'implements'];

    /**
     * Guard against a cyclic inheritance graph. PHP cannot express one, but a
     * partial or malformed contribution can, and resolution must terminate.
     */
    private const MAX_INHERITANCE_DEPTH = 20;

    /** Built on the first prefix or directory edge. */
    private ?NodeReferenceIndex $references = null;

    /** Built on the first edge that matches nothing exactly, or the first prefix edge of such a language. */
    private ?CaseInsensitiveReferences $caseless = null;

    /** Built on the first prefix edge of a language that reads names without regard to case. */
    private ?NodeReferenceIndex $caselessReferences = null;

    /** The same lookups over the case-insensitive matching keys, built with {@see $caseless}'s first retry. */
    private ?self $caselessTargets = null;

    /**
     * @param array<string, string> $nodeMap reference to node id
     * @param array<string, string> $returnTypes reference to the type reference it returns or holds
     * @param array<string, list<string>> $inheritanceSources type reference to the type references it inherits from
     * @param list<ScanContribution> $contributions
     */
    private function __construct(
        private readonly array $nodeMap,
        private readonly array $returnTypes,
        private readonly array $inheritanceSources,
        private readonly array $contributions,
    ) {}

    /**
     * The resolver for one reconcile's node map and contributions.
     *
     * @param array<string, string> $nodeMap reference to node id
     * @param list<ScanContribution> $contributions
     */
    public static function forContributions(array $nodeMap, array $contributions): self
    {
        return new self($nodeMap, self::returnTypes($contributions), self::inheritanceSources($contributions), $contributions);
    }

    /**
     * Every node a target that names a set of modules or classes reaches, or
     * null when the target names one symbol and {@see resolve()} applies.
     *
     * @return ?list<string>
     */
    public function expandedTargets(string $targetReference): ?array
    {
        if (str_contains($targetReference, ':module_context:')) {
            // One edge per module the loaded directory holds, found
            // here because only the graph, not a worker's request,
            // knows every module.
            $this->references ??= new NodeReferenceIndex($this->nodeMap);

            return self::contextTargets($targetReference, $this->references);
        }
        if (!str_contains($targetReference, ':class_prefix:')) {
            return null;
        }
        // A class name built from a namespace prefix at runtime:
        // one edge per class directly in that namespace.
        // A language that reads names without regard to case
        // looks the namespace up on the matching keys.
        if (CaseInsensitiveReferences::foldsLanguage($targetReference)) {
            $this->caselessReferences ??= new NodeReferenceIndex($this->caseless()->nodeMap());

            return self::classPrefixTargets(strtolower($targetReference), $this->caselessReferences);
        }
        $this->references ??= new NodeReferenceIndex($this->nodeMap);

        return self::classPrefixTargets($targetReference, $this->references);
    }

    /**
     * The reference an edge target resolves to once its deferred form is
     * read, and the node that reference reaches, if any. A target that
     * matches nothing exactly is matched again without regard to case, for a
     * language that reads its names so; an exact match always wins.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolve(string $targetReference, bool $returned): array
    {
        [$reference, $targetId] = $this->target($targetReference, $returned);
        if ($targetId === null && CaseInsensitiveReferences::applies($targetReference)) {
            $caseless = $this->caseless();
            $this->caselessTargets ??= new self(
                $caseless->nodeMap(),
                $caseless->returnTypes(),
                $caseless->inheritanceSources(),
                $this->contributions,
            );
            $targetId = $this->caselessTargets->target(CaseInsensitiveReferences::fold($targetReference), $returned)[1];
        }

        return [$reference, $targetId];
    }

    /**
     * The spelling an external node for a reference is named by: the one the
     * case-insensitive keys chose, once any edge has needed them, else the
     * reference as written.
     */
    public function spelling(string $reference): string
    {
        return $this->caseless?->spelling($reference) ?? $reference;
    }

    /** The case-insensitive matching keys, built on first use. */
    private function caseless(): CaseInsensitiveReferences
    {
        return $this->caseless ??= new CaseInsensitiveReferences($this->nodeMap, $this->returnTypes, $this->inheritanceSources, $this->contributions);
    }

    /**
     * The reference an edge target resolves to once its deferred form is
     * read, and the node that reference reaches, if any.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function target(string $targetReference, bool $returned): array
    {
        $reference = match (true) {
            $returned => $this->returnedMemberReference($targetReference),
            str_contains($targetReference, ':namespaced_function:') => self::namespacedFunctionReference($targetReference, $this->nodeMap),
            default => $targetReference,
        };

        return [$reference, $reference === null ? null : (self::implementationTarget($reference, $this->nodeMap)
            ?? $this->nodeMap[$reference]
            ?? $this->aliasedTypeTarget($reference)
            ?? $this->inheritedMemberTarget($reference))];
    }

    /**
     * The classes directly in the namespace a runtime-built class name starts
     * with: `new ('App\\Cards\\' . $command)`, named by the scanner as
     * `<language>:class_prefix:App\\Cards`. A class in a nested namespace is
     * not one the prefix can name unless the reference ends `\\**`, and an
     * empty prefix names nothing.
     *
     * @return list<string>
     */
    private static function classPrefixTargets(string $reference, NodeReferenceIndex $references): array
    {
        [$language, , $namespace] = array_pad(explode(':', $reference, 3), 3, '');
        // A trailing `\\**` reaches the namespaces below the prefix.
        $nested = str_ends_with($namespace, '\\**');
        $namespace = trim($nested ? substr($namespace, 0, -3) : $namespace, '\\');
        if ($namespace === '') {
            return [];
        }
        $prefix = $language . ':class:' . $namespace . '\\';
        $targets = [];
        foreach ($references->withPrefix($prefix) as $candidate => $nodeId) {
            // Direct children only, or, for `\\**`, only classes in a
            // namespace below: the expression puts a segment after the prefix.
            if (str_contains(substr($candidate, strlen($prefix)), '\\') === $nested) {
                $targets[] = $nodeId;
            }
        }

        return $targets;
    }

    /**
     * The modules a directory import loads: `require.context(dir, recursive,
     * pattern)`, named by the scanner as `<language>:module_context:<json>`.
     *
     * Each module under the directory whose `./`-relative path the pattern
     * matches, descending only when the import is recursive. A pattern PCRE
     * cannot compile loads nothing.
     *
     * @return list<string>
     */
    private static function contextTargets(string $reference, NodeReferenceIndex $references): array
    {
        [$language, , $json] = array_pad(explode(':', $reference, 3), 3, '');
        $context = json_decode($json, true);
        if (!is_array($context) || !is_string($context['directory'] ?? null) || !is_string($context['pattern'] ?? null)) {
            return [];
        }
        $flags = preg_replace('/[^imsu]/', '', (string) ($context['flags'] ?? ''));
        $pattern = '~' . str_replace('~', '\\~', $context['pattern']) . '~' . $flags;
        $prefix = $context['directory'] === '' ? '' : $context['directory'] . '/';
        $modulePrefix = $language . ':module:' . $prefix;
        $targets = [];
        foreach ($references->withPrefix($modulePrefix) as $candidate => $nodeId) {
            $inner = substr($candidate, strlen($modulePrefix));
            if ($inner === '' || (($context['recursive'] ?? true) !== true && str_contains($inner, '/'))) {
                continue;
            }
            $matched = @preg_match($pattern, './' . $inner);
            if ($matched === false) {
                return [];
            }
            if ($matched === 1) {
                $targets[] = $nodeId;
            }
        }

        return $targets;
    }

    /**
     * Every method's declared return type, from the `returns` edges the
     * scanners report, and every property's declared type, from the
     * `references` edge a property's declaration carries.
     *
     * @param list<ScanContribution> $contributions @return array<string, string>
     */
    private static function returnTypes(array $contributions): array
    {
        $types = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'returns'
                    || ($edge->kind === 'references' && str_contains($edge->sourceReference, ':property:'))) {
                    // First declaration wins, matching how a duplicate node is
                    // resolved; a method has one declared return type anyway.
                    $types[$edge->sourceReference] ??= $edge->targetReference;
                }
            }
        }

        return $types;
    }

    /**
     * Index the `uses_trait`, `extends`, and `implements` edges of this scan as
     * type reference => the type references whose members it inherits, so a
     * member lookup can walk the composition.
     *
     * Each kind is collected separately and merged in INHERITANCE_KINDS order,
     * so the breadth-first search visits a type's traits before its parents —
     * PHP's own precedence — however the scanner happened to order its edges.
     *
     * @param list<ScanContribution> $contributions
     * @return array<string, list<string>>
     */
    private static function inheritanceSources(array $contributions): array
    {
        $byKind = array_fill_keys(self::INHERITANCE_KINDS, []);
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (isset($byKind[$edge->kind])) {
                    $byKind[$edge->kind][$edge->sourceReference][] = $edge->targetReference;
                }
            }
        }

        $sources = [];
        foreach (self::INHERITANCE_KINDS as $kind) {
            foreach ($byKind[$kind] as $source => $targets) {
                $sources[$source] = [...($sources[$source] ?? []), ...$targets];
            }
        }

        return $sources;
    }

    /**
     * The implementation behind a reference into a hand-written declaration file.
     *
     * `tokens.mjs` with `tokens.d.mts` beside it is imported through the
     * declaration, so every call named `tokens.d.mts#lees` while the code that
     * runs is `tokens.mjs#lees`. When the graph holds the implementation under
     * the same name and kind, the edge goes there; otherwise null, and the
     * declaration keeps it.
     *
     * @param array<string, string> $nodeMap
     */
    private static function implementationTarget(string $reference, array $nodeMap): ?string
    {
        $implementation = preg_replace('~\.d\.(m|c)?ts(?=#|$)~', '.$1js', $reference, 1, $count);
        if ($count !== 1 || !is_string($implementation) || $implementation === $reference) {
            return null;
        }

        return $nodeMap[$implementation] ?? null;
    }

    /**
     * Turn "the member `m` of whatever `Type::factory()` returns" into a plain member reference.
     *
     * A scanner reads one file at a time, so it cannot see the return type of a
     * factory declared elsewhere and cannot name the receiver of a call on its
     * result. It names the call instead, and this resolves it here, where every
     * file's declared return types are known. Returns null when the call is not
     * one the graph knows, which is the caller's cue to drop the edge.
     */
    private function returnedMemberReference(string $reference): ?string
    {
        $parts = explode(':', $reference, 3);
        if (count($parts) === 3 && $parts[1] === 'method_of_property') {
            return $this->propertyMemberReference($parts[0], $parts[2]);
        }
        if (count($parts) !== 3 || $parts[1] !== 'method_of_return') {
            return null;
        }
        [$language, , $canonical] = $parts;
        $separator = strrpos($canonical, '::');
        if ($separator === false) {
            return null;
        }
        $callee = substr($canonical, 0, $separator);
        $member = substr($canonical, $separator + 2);
        if ($callee === '' || $member === '') {
            return null;
        }
        $returned = $this->returnTypes[$language . ':method:' . $callee]
            ?? $this->inheritedReturnType($language, $callee);
        if ($returned === null) {
            return null;
        }
        // The returns edge names a type; its members are addressed by the type's
        // canonical name, whatever kind the declaration turns out to be.
        $returnedParts = explode(':', $returned, 3);

        return count($returnedParts) === 3 && $returnedParts[2] !== ''
            ? $language . ':method:' . $returnedParts[2] . '::' . $member
            : null;
    }

    /**
     * Resolve an unqualified PHP function call to the namespace's function, or the global one.
     *
     * PHP tries the current namespace first and falls back to the global
     * function, a choice that depends on what other files declare — so the
     * scanner defers it and this decides, being the only place every
     * declaration is known. Unlike a deferred receiver this never drops the
     * edge: the call definitely happens, and an undeclared global name is a
     * genuine external symbol.
     *
     * @param array<string, string> $nodeMap
     */
    private static function namespacedFunctionReference(string $reference, array $nodeMap): string
    {
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3 || $parts[2] === '') {
            return $reference;
        }
        [$language, , $canonical] = $parts;
        $namespaced = $language . ':function:' . $canonical;
        if (isset($nodeMap[$namespaced])) {
            return $namespaced;
        }
        $separator = strrpos($canonical, '\\');

        return $language . ':function:' . ($separator === false ? $canonical : substr($canonical, $separator + 1));
    }

    /**
     * The declared return type of a factory the named type reaches through a trait or an ancestor.
     *
     * A scanner names `$this->make()` against the class the call is written in,
     * so a factory a trait or a parent provides is looked up under a name it
     * was never declared with. Without this, every call on that factory's
     * result is dropped — trait-heavy suites and framework base classes lose
     * whole call chains that way.
     */
    private function inheritedReturnType(string $language, string $callee): ?string
    {
        $separator = strrpos($callee, '::');
        if ($separator === false) {
            return null;
        }
        $type = substr($callee, 0, $separator);
        $member = substr($callee, $separator + 2);
        if ($type === '' || $member === '') {
            return null;
        }

        return $this->throughComposition(
            $language,
            $type,
            fn(string $declaringType): ?string => $this->returnTypes[$language . ':method:' . $declaringType . '::' . $member] ?? null,
        );
    }

    /**
     * Turn "the member `m` of what `Type`'s property `$a`, then that value's
     * property `$b`, holds" into a plain member reference.
     *
     * `$context->options->flag()` reaches its receiver through a property
     * declared in another file, often as a promoted constructor parameter, so
     * a scanner reading one file cannot name it. It names the path instead
     * (`Type::$a::$b::m`), and each step is looked up here among every file's
     * declared property types, through the type's traits and parents. Returns
     * null when a step names a property no declaration types, which drops the
     * edge.
     */
    private function propertyMemberReference(string $language, string $path): ?string
    {
        $declaredTypes = $this->returnTypes;
        $segments = explode('::', $path);
        $member = array_pop($segments);
        $type = array_shift($segments);
        if ($type === null || $type === '' || $member === '' || $segments === []) {
            return null;
        }
        foreach ($segments as $property) {
            if (!str_starts_with($property, '$') || $property === '$') {
                return null;
            }
            $declared = $declaredTypes[$language . ':property:' . $type . '::' . $property]
                ?? $this->throughComposition(
                    $language,
                    $type,
                    static fn(string $declaringType): ?string => $declaredTypes[$language . ':property:' . $declaringType . '::' . $property] ?? null,
                );
            $declaredParts = $declared === null ? [] : explode(':', $declared, 3);
            if (count($declaredParts) !== 3 || $declaredParts[2] === '') {
                return null;
            }
            $type = $declaredParts[2];
        }

        return $language . ':method:' . $type . '::' . $member;
    }

    /**
     * Resolve a type reference whose kind segment disagrees with the kind the
     * declaration was emitted under, by retrying the lookup against the other
     * {@see self::TYPE_KINDS}. The language segment is never varied: a PHP
     * `Error` and a TypeScript `Error` are different symbols.
     *
     * Returns null when the reference is not in a type position, or names
     * nothing declared in this graph — both of which stay external.
     */
    private function aliasedTypeTarget(string $reference): ?string
    {
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$language, $kind, $canonical] = $parts;
        if (!in_array($kind, self::TYPE_KINDS, true)) {
            return null;
        }

        foreach (self::TYPE_KINDS as $alias) {
            if ($alias === $kind) {
                continue;
            }
            $candidate = $this->nodeMap[$language . ':' . $alias . ':' . $canonical] ?? null;
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve a member reference the named type does not itself declare to the
     * trait, parent class, or interface that does.
     *
     * A scanner reads one file at a time: seeing `$this->log()` inside
     * `App\Invoice` it can only emit `php:method:App\Invoice::log`, even when
     * `log` is declared by a trait `App\Invoice` uses or by the class it
     * extends. Resolution is by exact reference string, so without this the
     * lookup misses the declaration and a phantom `external_method` twin is
     * fabricated beside it — leaving every trait method, every inherited
     * helper, and every interface method with an in-degree of zero however
     * heavily it is called.
     *
     * The search follows nested `use` statements and the full inheritance
     * chain, since both compose, and is depth-bounded so a cyclic contribution
     * cannot hang the reconcile. Returns null when the reference is not a
     * member, names no known type, or names a member nothing in scope declares
     * — all of which stay external.
     */
    private function inheritedMemberTarget(string $reference): ?string
    {
        if ($this->inheritanceSources === []) {
            return null;
        }
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$language, $kind, $canonical] = $parts;
        if (!in_array($kind, self::MEMBER_KINDS, true)) {
            return null;
        }
        $separator = strrpos($canonical, '::');
        if ($separator === false) {
            return null;
        }
        $type = substr($canonical, 0, $separator);
        $member = substr($canonical, $separator + 2);
        if ($type === '' || $member === '') {
            return null;
        }

        return $this->throughComposition(
            $language,
            $type,
            fn(string $declaringType): ?string => $this->nodeMap[$language . ':' . $kind . ':' . $declaringType . '::' . $member] ?? null,
        );
    }

    /**
     * Walk a type's traits, parents, and interfaces, returning the first
     * declaring type for which `$probe` yields a result.
     *
     * Breadth-first from the type's own composition outwards, so the nearest
     * declaration wins, and depth-bounded so a cyclic contribution cannot hang
     * the reconcile. `$probe` receives each candidate declaring type's
     * canonical name and returns null when it declares nothing of interest.
     *
     * @param callable(string): ?string $probe
     */
    private function throughComposition(string $language, string $type, callable $probe): ?string
    {
        // A reference carries the member's kind, not the declaring type's, so
        // every type kind that could contribute members is tried.
        $frontier = [];
        foreach (self::TYPE_KINDS as $typeKind) {
            foreach ($this->inheritanceSources[$language . ':' . $typeKind . ':' . $type] ?? [] as $source) {
                $frontier[] = $source;
            }
        }
        $seen = [];
        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $sourceReference) {
                if (isset($seen[$sourceReference])) {
                    continue;
                }
                $seen[$sourceReference] = true;
                $sourceParts = explode(':', $sourceReference, 3);
                if (count($sourceParts) !== 3 || $sourceParts[0] !== $language) {
                    continue;
                }
                $candidate = $probe($sourceParts[2]);
                if ($candidate !== null) {
                    return $candidate;
                }
                foreach ($this->inheritanceSources[$sourceReference] ?? [] as $nested) {
                    $next[] = $nested;
                }
            }
            $frontier = $next;
        }

        return null;
    }
}
