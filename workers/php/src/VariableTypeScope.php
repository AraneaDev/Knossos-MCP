<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * What the traversal knows about each local variable, per function-like scope.
 *
 * A variable may carry an inferred class, the call its value came from, the
 * property it was read from, the element type of the list it holds, what the closure it holds yields, and the
 * namespace a class name built into it starts with. That is what lets
 * `$x = new Foo; $x->bar()` and `new $card` name their target.
 *
 * Each function-like node has variables of its own, kept on a stack innermost
 * last: a parameter or an assignment inside a closure or an arrow function
 * does not reach the code around it, which sees only what a by-reference
 * capture may have changed. Code no callable encloses, a script's body, has
 * the file's own variables.
 */
final class VariableTypeScope
{
    /**
     * The scopes of the function-like nodes the traversal is inside, innermost
     * last: their variables, the bindings a closure captured by reference, and
     * the namespace prefixes held in their variables.
     *
     * @var list<array{variables: array<string, array{type: ?string, confidence: string, returned_by?: string, held_in?: string, element?: string, invokes?: string}>, by_reference: array<string, ?array{type: ?string, confidence: string, returned_by?: string, held_in?: string, element?: string, invokes?: string}>, prefixes: array<string, string>}>
     */
    private array $scopes = [];

    /**
     * The variables of code no callable encloses.
     *
     * @var array<string, array{type: ?string, confidence: string, returned_by?: string, held_in?: string, element?: string, invokes?: string}>
     */
    private array $fileVariables = [];

    /**
     * Variables of code no callable encloses that hold a class name built
     * from a namespace literal, by name.
     *
     * @var array<string, string>
     */
    private array $filePrefixes = [];

    /**
     * Open a function-like node's scope with what it captures from the one
     * around it: everything for an arrow function, which captures by value,
     * the `use` list for a closure, and nothing for any other function.
     *
     * An arrow function's parameters shadow a captured prefix outright; its
     * captured types are left for the caller to rebind from the signature.
     */
    public function enter(Node\FunctionLike $node): void
    {
        $outer = $this->variables();
        $outerPrefixes = $this->prefixes();
        $variables = $node instanceof Expr\ArrowFunction ? $outer : [];
        $byReference = [];
        $prefixes = [];
        if ($node instanceof Expr\ArrowFunction) {
            $parameters = [];
            foreach ($node->params as $parameter) {
                if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                    $parameters[$parameter->var->name] = true;
                }
            }
            $prefixes = array_diff_key($outerPrefixes, $parameters);
        }
        foreach ($node instanceof Expr\Closure ? $node->uses : [] as $use) {
            // The grammar allows only a plain `$name` in a `use` list.
            $name = (string) $use->var->name;
            $binding = $outer[$name] ?? null;
            if ($binding !== null) {
                $variables[$name] = $binding;
            }
            if ($use->byRef) {
                $byReference[$name] = $binding;
            }
            if (isset($outerPrefixes[$name])) {
                $prefixes[$name] = $outerPrefixes[$name];
            }
        }
        $this->scopes[] = ['variables' => $variables, 'by_reference' => $byReference, 'prefixes' => $prefixes];
    }

    /**
     * Close the innermost scope. A variable a closure took by reference and
     * rebound may hold either value once the closure might have run, so the
     * code around it no longer knows its type.
     */
    public function leave(): void
    {
        $scope = array_pop($this->scopes);
        foreach ($scope['by_reference'] ?? [] as $variable => $captured) {
            if (($scope['variables'][$variable] ?? null) !== $captured) {
                $this->clear($variable);
            }
        }
    }

    /** Remember a variable's inferred class so later calls on it can be resolved. */
    public function set(string $variable, string $type, string $confidence = 'certain'): void
    {
        $variables = &$this->variables();
        $variables[$variable] = ['type' => $type, 'confidence' => $confidence];
    }

    /**
     * Remember that a variable holds whatever a named call returned.
     *
     * Kept instead of a type because the declaration that names the type is in
     * another file; the reference this records is enough for the reconciler,
     * which sees every file, to name the receiver.
     */
    public function setReturnSource(string $variable, string $callee): void
    {
        $variables = &$this->variables();
        $variables[$variable] = ['type' => null, 'confidence' => 'probable', 'returned_by' => $callee];
    }

    /**
     * Remember that a variable holds what a property of a typed receiver held,
     * as the path the reconciler resolves (`Type::$a::$b`).
     *
     * Kept instead of a type for the same reason as a return source: the
     * property is declared in whatever file declares that type.
     */
    public function setPropertySource(string $variable, string $path): void
    {
        $variables = &$this->variables();
        $variables[$variable] = ['type' => null, 'confidence' => 'probable', 'held_in' => $path];
    }

    /**
     * Remember the class a variable's list holds, as its docblock gives it,
     * keeping whatever else is known about the variable and an element type
     * it already has.
     */
    public function setElement(string $variable, string $element): void
    {
        $variables = &$this->variables();
        $variables[$variable] = ($variables[$variable] ?? ['type' => null, 'confidence' => 'probable']) + ['element' => $element];
    }

    /** Remember what calling the closure a variable holds yields. */
    public function setInvokes(string $variable, string $yields): void
    {
        $variables = &$this->variables();
        $variables[$variable]['invokes'] = $yields;
    }

    /** Forget a variable's type when it is reassigned to something unknown. */
    public function clear(string $variable): void
    {
        $variables = &$this->variables();
        unset($variables[$variable]);
    }

    /** The tracked class for a variable, or null when it was never inferred. */
    public function type(string $variable): ?string
    {
        return $this->variables()[$variable]['type'] ?? null;
    }

    /** How far a tracked variable's type is inferred, so a guess is never recorded as proven. */
    public function confidence(string $variable): string
    {
        return $this->variables()[$variable]['confidence'] ?? 'certain';
    }

    /** The call a variable's value came from, when its type was not resolvable here. */
    public function returnSource(string $variable): ?string
    {
        return $this->variables()[$variable]['returned_by'] ?? null;
    }

    /** The property path a variable's value was read from, when its type was not resolvable here. */
    public function propertySource(string $variable): ?string
    {
        return $this->variables()[$variable]['held_in'] ?? null;
    }

    /** The class each element of the list a variable holds is, when its docblock says. */
    public function element(string $variable): ?string
    {
        return $this->variables()[$variable]['element'] ?? null;
    }

    /** What calling the closure a variable holds yields, when that is declared. */
    public function invokes(string $variable): ?string
    {
        return $this->variables()[$variable]['invokes'] ?? null;
    }

    /** The namespace the class name a variable holds was built in, if it was built from one. */
    public function prefix(string $variable): ?string
    {
        return $this->prefixes()[$variable] ?? null;
    }

    /** Remember that a variable holds a class name built in a namespace. */
    public function setPrefix(string $variable, string $prefix): void
    {
        $prefixes = &$this->prefixes();
        $prefixes[$variable] = $prefix;
    }

    /** Forget a variable's namespace prefix when it is rebound. */
    public function clearPrefix(string $variable): void
    {
        $prefixes = &$this->prefixes();
        unset($prefixes[$variable]);
    }

    /**
     * The variables of the scope being read: the innermost function-like
     * node's, closures included, or the file's own when none encloses the code.
     *
     * A script makes its calls from file scope (`$endpoint = new Endpoint();
     * $endpoint->handle();`), and leaving those variables untracked typed
     * nothing there, so whatever an entry script alone reaches read as dead.
     * A function does not see the file's variables, which is why each
     * callable keeps its own.
     *
     * @return array<string, array{type: ?string, confidence: string, returned_by?: string, held_in?: string, element?: string, invokes?: string}>
     */
    private function &variables(): array
    {
        if ($this->scopes === []) {
            return $this->fileVariables;
        }

        return $this->scopes[array_key_last($this->scopes)]['variables'];
    }

    /**
     * The namespace prefixes of the scope being read, kept per scope like
     * its variables: a prefix recorded in a method does not reach a closure
     * parameter of the same name.
     *
     * @return array<string, string>
     */
    private function &prefixes(): array
    {
        if ($this->scopes === []) {
            return $this->filePrefixes;
        }

        return $this->scopes[array_key_last($this->scopes)]['prefixes'];
    }
}
