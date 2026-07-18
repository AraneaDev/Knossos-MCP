<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final class LaravelRouteFactCollector
{
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'view', 'redirect'];

    /** @var list<array{prefix: string, middleware: list<string>, name: string}> */
    private array $groups = [];
    /** @var array<int, true> */
    private array $groupNodes = [];

    public function __construct(private readonly LaravelFactStore $facts) {}

    public function enterNode(Node $node): void
    {
        if ($node instanceof Expr\MethodCall && $this->isGroupCall($node)) {
            $this->groups[] = $this->groupModifiers($node);
            $this->groupNodes[spl_object_id($node)] = true;
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
            $this->route($node);
        }
    }

    public function leaveNode(Node $node): void
    {
        if (isset($this->groupNodes[spl_object_id($node)])) {
            unset($this->groupNodes[spl_object_id($node)]);
            array_pop($this->groups);
        }
    }

    private function route(Expr\MethodCall|Expr\StaticCall $node): void
    {
        $descriptor = $this->routeDescriptor($node);
        if ($descriptor === null) {
            return;
        }
        [$method, $args, $modifiers, $evidence] = $descriptor;
        $uriIndex = $method === 'match' ? 1 : 0;
        $actionIndex = $method === 'match' ? 2 : 1;
        $uri = LaravelFactStore::string($args[$uriIndex]->value ?? null);
        if ($uri === null) {
            $this->facts->addDiagnostic('LARAVEL_DYNAMIC_ROUTE_URI', 'Dynamic route URI was skipped.', $evidence);
            return;
        }
        $methods = $method === 'match'
            ? LaravelFactStore::strings($args[0]->value ?? null)
            : [strtoupper($method)];
        if ($method === 'any') {
            $methods = ['ANY'];
        }
        if ($methods === []) {
            $this->facts->addDiagnostic('LARAVEL_DYNAMIC_ROUTE', 'Dynamic route declaration was skipped.', $evidence);
            return;
        }
        $group = $this->combinedGroup();
        $uri = $this->joinUri($group['prefix'], $uri);
        $action = $this->action($args[$actionIndex]->value ?? null);
        $canonical = implode('|', $methods) . ' ' . $uri . ' => ' . ($action['label'] ?? 'closure');
        $id = 'php:route:' . $canonical;
        $middleware = array_values(array_unique([...$group['middleware'], ...$modifiers['middleware']]));
        $this->facts->addNode($id, 'route', $canonical, implode('|', $methods) . ' ' . $uri, $evidence, [
            'methods' => $methods,
            'uri' => $uri,
            'name' => $group['name'] . $modifiers['name'],
            'middleware' => $middleware,
            'action' => $action['label'] ?? null,
        ]);
        if (isset($action['reference'])) {
            $this->facts->addEdge('routes_to', $id, $action['reference'], $evidence);
        }
        foreach ($middleware as $alias) {
            $middlewareId = 'php:middleware:laravel.middleware:' . $alias;
            $this->facts->addNode(
                $middlewareId,
                'middleware',
                'laravel.middleware:' . $alias,
                $alias,
                $evidence,
                ['alias' => $alias],
                'framework_convention',
                'probable',
            );
            $this->facts->addEdge('uses_middleware', $id, $middlewareId, $evidence, ['alias' => $alias]);
        }
    }

    /** @return array{0: string, 1: list<Node\Arg>, 2: array{middleware: list<string>, name: string}, 3: Node}|null */
    private function routeDescriptor(Expr\MethodCall|Expr\StaticCall $node): ?array
    {
        $modifiers = ['middleware' => [], 'name' => ''];
        $cursor = $node;
        while ($cursor instanceof Expr\MethodCall) {
            $name = $cursor->name instanceof Identifier ? strtolower($cursor->name->toString()) : '';
            if ($name === 'middleware') {
                $modifiers['middleware'] = [...LaravelFactStore::strings($cursor->args[0]->value ?? null), ...$modifiers['middleware']];
            } elseif ($name === 'name') {
                $modifiers['name'] = LaravelFactStore::string($cursor->args[0]->value ?? null) ?? $modifiers['name'];
            }
            $cursor = $cursor->var;
        }
        if (!$cursor instanceof Expr\StaticCall || !$cursor->class instanceof Name || !$cursor->name instanceof Identifier) {
            return null;
        }
        $method = strtolower($cursor->name->toString());
        if (!$this->isRouteFacade($cursor->class) || !in_array($method, self::ROUTE_METHODS, true)) {
            return null;
        }
        return [$method, $cursor->args, $modifiers, $cursor];
    }

    private function isGroupCall(Expr\MethodCall $node): bool
    {
        if (!$node->name instanceof Identifier || strtolower($node->name->toString()) !== 'group') {
            return false;
        }
        $cursor = $node->var;
        while ($cursor instanceof Expr\MethodCall) {
            $cursor = $cursor->var;
        }
        return $cursor instanceof Expr\StaticCall && $cursor->class instanceof Name && $this->isRouteFacade($cursor->class);
    }

    /** @return array{prefix: string, middleware: list<string>, name: string} */
    private function groupModifiers(Expr\MethodCall $node): array
    {
        $result = ['prefix' => '', 'middleware' => [], 'name' => ''];
        $cursor = $node->var;
        while ($cursor instanceof Expr\MethodCall) {
            $name = $cursor->name instanceof Identifier ? strtolower($cursor->name->toString()) : '';
            if ($name === 'prefix') {
                $result['prefix'] = LaravelFactStore::string($cursor->args[0]->value ?? null) ?? $result['prefix'];
            }
            if ($name === 'middleware') {
                $result['middleware'] = [...LaravelFactStore::strings($cursor->args[0]->value ?? null), ...$result['middleware']];
            }
            if ($name === 'name') {
                $result['name'] = LaravelFactStore::string($cursor->args[0]->value ?? null) ?? $result['name'];
            }
            $cursor = $cursor->var;
        }
        if ($cursor instanceof Expr\StaticCall && $cursor->name instanceof Identifier) {
            $name = strtolower($cursor->name->toString());
            if ($name === 'prefix') {
                $result['prefix'] = LaravelFactStore::string($cursor->args[0]->value ?? null) ?? $result['prefix'];
            }
            if ($name === 'middleware') {
                $result['middleware'] = [...LaravelFactStore::strings($cursor->args[0]->value ?? null), ...$result['middleware']];
            }
            if ($name === 'name') {
                $result['name'] = LaravelFactStore::string($cursor->args[0]->value ?? null) ?? $result['name'];
            }
        }
        return $result;
    }

    /** @return array{prefix: string, middleware: list<string>, name: string} */
    private function combinedGroup(): array
    {
        $result = ['prefix' => '', 'middleware' => [], 'name' => ''];
        foreach ($this->groups as $group) {
            $result['prefix'] = $this->joinUri($result['prefix'], $group['prefix']);
            $result['middleware'] = [...$result['middleware'], ...$group['middleware']];
            $result['name'] .= $group['name'];
        }
        return $result;
    }

    /** @return array{reference?: string, label?: string} */
    private function action(?Node $node): array
    {
        if ($node instanceof Expr\Array_ && count($node->items) >= 2) {
            $class = LaravelFactStore::classArgument($node->items[0]?->value);
            $method = LaravelFactStore::string($node->items[1]?->value);
            if ($class !== null && $method !== null) {
                return ['reference' => 'php:method:' . $class . '::' . $method, 'label' => $class . '::' . $method];
            }
        }
        $class = LaravelFactStore::classArgument($node);
        if ($class !== null) {
            return ['reference' => LaravelFactStore::classReference($class), 'label' => $class];
        }
        $string = LaravelFactStore::string($node);
        if ($string !== null && str_contains($string, '@')) {
            [$class, $method] = explode('@', $string, 2);
            return ['reference' => 'php:method:' . ltrim($class, '\\') . '::' . $method, 'label' => $string];
        }
        return [];
    }

    private function isRouteFacade(Name $name): bool
    {
        return strtolower(basename(str_replace('\\', '/', LaravelFactStore::name($name)))) === 'route';
    }

    private function joinUri(string $prefix, string $uri): string
    {
        return '/' . trim(trim($prefix, '/') . '/' . trim($uri, '/'), '/');
    }
}
