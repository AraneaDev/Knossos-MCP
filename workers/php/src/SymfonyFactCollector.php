<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class SymfonyFactCollector extends NodeVisitorAbstract
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];
    /** @var array<string, array<string, mixed>> */
    private array $edges = [];
    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];
    /** @var list<array{name: string, route_prefix: string, message_handler: bool}> */
    private array $classes = [];

    public function __construct(private readonly string $relativePath) {}

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->enterClass($node);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        }
        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    private function enterClass(Stmt\ClassLike $node): void
    {
        $name = isset($node->namespacedName) ? $node->namespacedName->toString() : ($node->name?->toString() ?? '{anonymous}');
        $route = $this->attribute($node->attrGroups, 'Route');
        $prefix = $route === null ? '' : ($this->stringArgument($route, 'path', 0) ?? '');
        if ($route !== null && $prefix === '' && $this->argument($route, 'path', 0) !== null) {
            $this->diagnostic('SYMFONY_DYNAMIC_ROUTE_PATH', 'Dynamic class route prefix was skipped.', $route);
        }
        $this->classes[] = [
            'name' => $name,
            'route_prefix' => $prefix,
            'message_handler' => $this->attribute($node->attrGroups, 'AsMessageHandler') !== null,
        ];
        $classId = $this->classReference($name);

        $command = $this->attribute($node->attrGroups, 'AsCommand');
        if ($command !== null) {
            $commandName = $this->stringArgument($command, 'name', 0);
            if ($commandName === null) {
                $this->diagnostic('SYMFONY_DYNAMIC_COMMAND_NAME', 'Command name could not be resolved statically.', $command);
            } else {
                $id = 'php:command:' . $commandName;
                $this->nodes[$id] = $this->node($id, 'command', $commandName, $commandName, $command, ['framework' => 'symfony']);
                $this->edge('handles', $id, $classId, $command);
            }
        }

        $alias = $this->attribute($node->attrGroups, 'AsAlias');
        if ($alias !== null) {
            $serviceId = $this->stringArgument($alias, 'id', 0);
            if ($serviceId !== null) {
                $id = 'php:service:' . $serviceId;
                $this->nodes[$id] = $this->node($id, 'service', $serviceId, $serviceId, $alias, ['framework' => 'symfony']);
                $this->edge('binds', $id, $classId, $alias, ['alias' => $serviceId]);
            }
        }

        foreach ($this->attributes($node->attrGroups, 'AsEventListener') as $listener) {
            $event = $this->classArgument($this->argument($listener, 'event', 0));
            if ($event !== null) {
                $this->edge('listens_to', $classId, $this->classReference($event), $listener);
            } else {
                $this->diagnostic('SYMFONY_DYNAMIC_EVENT', 'Event listener target could not be resolved statically.', $listener);
            }
        }
    }

    private function enterMethod(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        $methodId = 'php:method:' . $class['name'] . '::' . $node->name->toString();
        foreach ($this->attributes($node->attrGroups, 'Route') as $route) {
            $path = $this->stringArgument($route, 'path', 0);
            if ($path === null) {
                $this->diagnostic('SYMFONY_DYNAMIC_ROUTE_PATH', 'Dynamic route path was skipped.', $route);
                continue;
            }
            $methods = $this->stringListArgument($route, 'methods');
            if ($methods === []) {
                $methods = ['ANY'];
            }
            $methods = array_map('strtoupper', $methods);
            sort($methods, SORT_STRING);
            $uri = '/' . trim(trim($class['route_prefix'], '/') . '/' . trim($path, '/'), '/');
            $canonical = implode('|', $methods) . ' ' . $uri . ' => ' . $class['name'] . '::' . $node->name->toString();
            $id = 'php:route:' . $canonical;
            $this->nodes[$id] = $this->node($id, 'route', $canonical, implode('|', $methods) . ' ' . $uri, $route, [
                'framework' => 'symfony',
                'methods' => $methods,
                'uri' => $uri,
                'name' => $this->stringArgument($route, 'name'),
            ]);
            $this->edge('routes_to', $id, $methodId, $route);
        }

        $methodMessageHandler = $this->attribute($node->attrGroups, 'AsMessageHandler') !== null;
        if ($methodMessageHandler || ($class['message_handler'] && strtolower($node->name->toString()) === '__invoke')) {
            $type = $this->parameterClass($node->params[0] ?? null);
            if ($type !== null) {
                $this->edge('handles_message', $this->classReference($class['name']), $this->classReference($type), $node);
            }
        }

        foreach ($this->attributes($node->attrGroups, 'AsEventListener') as $listener) {
            $event = $this->classArgument($this->argument($listener, 'event', 0));
            if ($event !== null) {
                $this->edge('listens_to', $methodId, $this->classReference($event), $listener);
            }
        }

        if (strtolower($node->name->toString()) === '__construct') {
            foreach ($node->params as $parameter) {
                $autowire = $this->attribute($parameter->attrGroups, 'Autowire');
                $service = $autowire === null ? null : $this->stringArgument($autowire, 'service');
                if ($autowire !== null && $service !== null) {
                    $id = 'php:service:' . $service;
                    $this->nodes[$id] ??= $this->node($id, 'service', $service, $service, $autowire, ['framework' => 'symfony']);
                    $this->edge('injects', $this->classReference($class['name']), $id, $autowire, ['explicit' => true]);
                }
            }
        }

        if (strtolower($node->name->toString()) === 'getsubscribedevents') {
            foreach ($node->stmts ?? [] as $statement) {
                if (!$statement instanceof Stmt\Return_ || !$statement->expr instanceof Expr\Array_) {
                    continue;
                }
                foreach ($statement->expr->items as $item) {
                    $event = $this->eventReference($item?->key);
                    if ($event !== null) {
                        $this->edge('listens_to', $this->classReference($class['name']), $event, $item ?? $statement);
                    }
                }
            }
        }
    }

    /** @param list<Node\AttributeGroup> $groups */
    private function attribute(array $groups, string $shortName): ?Node\Attribute
    {
        return $this->attributes($groups, $shortName)[0] ?? null;
    }

    /** @param list<Node\AttributeGroup> $groups @return list<Node\Attribute> */
    private function attributes(array $groups, string $shortName): array
    {
        $result = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp($this->shortName($attribute->name), $shortName) === 0) {
                    $result[] = $attribute;
                }
            }
        }
        return $result;
    }

    private function argument(Node\Attribute $attribute, string $name, int $position = -1): ?Node
    {
        foreach ($attribute->args as $index => $argument) {
            if (($argument->name?->toString() === $name) || ($position >= 0 && $index === $position && $argument->name === null)) {
                return $argument->value;
            }
        }
        return null;
    }

    private function stringArgument(Node\Attribute $attribute, string $name, int $position = -1): ?string
    {
        $value = $this->argument($attribute, $name, $position);
        return $value instanceof Scalar\String_ ? $value->value : null;
    }

    /** @return list<string> */
    private function stringListArgument(Node\Attribute $attribute, string $name): array
    {
        $value = $this->argument($attribute, $name);
        if (!$value instanceof Expr\Array_) {
            return [];
        }
        $result = [];
        foreach ($value->items as $item) {
            if ($item?->value instanceof Scalar\String_) {
                $result[] = $item->value->value;
            }
        }
        return array_values(array_unique($result));
    }

    private function parameterClass(?Node\Param $parameter): ?string
    {
        return $parameter?->type instanceof Name ? $this->name($parameter->type) : null;
    }

    private function classArgument(?Node $node): ?string
    {
        return $node instanceof Expr\ClassConstFetch && $node->class instanceof Name
            && $node->name instanceof Identifier && strtolower($node->name->toString()) === 'class'
            ? $this->name($node->class) : null;
    }

    private function eventReference(?Node $node): ?string
    {
        $class = $this->classArgument($node);
        if ($class !== null) {
            return $this->classReference($class);
        }
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier) {
            return 'php:event:' . $this->name($node->class) . '::' . $node->name->toString();
        }
        if ($node instanceof Scalar\String_) {
            return 'php:event:' . $node->value;
        }
        return null;
    }

    /** @param array<string, mixed> $attributes */
    private function node(string $id, string $kind, string $canonical, string $display, Node $at, array $attributes): array
    {
        return ['local_id' => $id, 'kind' => $kind, 'canonical_name' => $canonical, 'display_name' => $display, 'origin' => 'framework_convention', 'confidence' => 'certain', 'evidence' => $this->evidence($at), 'attributes' => (object) $attributes];
    }

    /** @param array<string, mixed> $attributes */
    private function edge(string $kind, string $source, string $target, Node $at, array $attributes = []): void
    {
        $key = implode("\0", [$kind, $source, $target, (string) $at->getStartLine(), json_encode($attributes)]);
        $this->edges[$key] = ['kind' => $kind, 'source' => $source, 'target' => $target, 'origin' => 'framework_convention', 'confidence' => 'certain', 'evidence' => $this->evidence($at), 'attributes' => (object) $attributes];
    }

    private function diagnostic(string $code, string $message, Node $at): void
    {
        $this->diagnostics[] = ['severity' => 'warning', 'code' => $code, 'message' => $message, 'evidence' => $this->evidence($at)];
    }

    /** @return array{name: string, route_prefix: string, message_handler: bool}|null */
    private function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    private function classReference(string $name): string
    {
        return 'php:class:' . ltrim($name, '\\');
    }

    private function shortName(Name $name): string
    {
        return basename(str_replace('\\', '/', $this->name($name)));
    }

    private function name(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        return ($resolved instanceof Name ? $resolved : $name)->toString();
    }

    /** @return array{path: string, start_line: int, end_line: int} */
    private function evidence(Node $node): array
    {
        $start = max(1, $node->getStartLine());
        return ['path' => $this->relativePath, 'start_line' => $start, 'end_line' => max($start, $node->getEndLine())];
    }
}
