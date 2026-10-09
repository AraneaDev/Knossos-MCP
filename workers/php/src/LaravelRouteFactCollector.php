<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Records routes declared through the Route facade, including nested groups.
 *
 * Group prefixes and middleware have to be composed as the traversal descends,
 * since the registered URI exists only as the concatenation of its enclosing
 * groups.
 */
final class LaravelRouteFactCollector
{
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'view', 'redirect', 'permanentredirect', 'resource', 'apiresource'];

    /**
     * The actions a resource route registers, in Laravel's order: the verbs,
     * whether the URI names one item, and the suffix after it. `create` and
     * `edit` serve forms, which `apiResource` leaves out.
     */
    private const RESOURCE_ACTIONS = [
        'index' => [['GET'], false, ''],
        'create' => [['GET'], false, '/create'],
        'store' => [['POST'], false, ''],
        'show' => [['GET'], true, ''],
        'edit' => [['GET'], true, '/edit'],
        'update' => [['PUT', 'PATCH'], true, ''],
        'destroy' => [['DELETE'], true, ''],
    ];

    /**
     * The group attribute keys of `Route::group([...], ...)` and the modifier
     * each one is: `as` is the array spelling of `name`.
     */
    private const GROUP_KEYS = ['prefix' => 'prefix', 'middleware' => 'middleware', 'as' => 'name', 'name' => 'name', 'namespace' => 'namespace', 'controller' => 'controller'];

    /** @var list<array{prefix: string, middleware: list<string>, name: string, namespace: ?string, controller: ?string}> */
    private array $groups = [];
    /** @var array<int, true> */
    private array $groupNodes = [];
    /**
     * Facade calls already recorded through the chain around them.
     *
     * The traversal reaches `Route::resource(...)->only([...])` before the
     * calls inside it, and the bare `Route::resource(...)` on its own would
     * register every action the chain narrowed away. The outermost call sees
     * every modifier, so it alone records the route.
     *
     * @var array<int, true>
     */
    private array $chainedCalls = [];

    public function __construct(private readonly LaravelFactStore $facts) {}
    /** Track route group nesting and record any route registered at this node. */

    public function enterNode(Node $node): void
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $this->isGroupCall($node)) {
            $this->groups[] = $this->groupModifiers($node);
            $this->groupNodes[spl_object_id($node)] = true;
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
            $this->route($node);
        }
    }
    /** Pop the group stack so a prefix does not leak into a sibling group. */

    public function leaveNode(Node $node): void
    {
        if (isset($this->groupNodes[spl_object_id($node)])) {
            unset($this->groupNodes[spl_object_id($node)]);
            array_pop($this->groups);
        }
    }
    /** Record one route: its methods, composed URI, and the action it dispatches to. */

    private function route(Expr\MethodCall|Expr\StaticCall $node): void
    {
        $descriptor = $this->routeDescriptor($node);
        if ($descriptor === null) {
            return;
        }
        [$method, $args, $modifiers, $evidence] = $descriptor;
        if (isset($this->chainedCalls[spl_object_id($evidence)])) {
            return;
        }
        $this->chainedCalls[spl_object_id($evidence)] = true;
        if ($method === 'resource' || $method === 'apiresource') {
            $this->resourceRoutes($method === 'apiresource', $args, $modifiers, $evidence);
            return;
        }
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
        if ($method === 'any' || $method === 'redirect' || $method === 'permanentredirect') {
            $methods = ['ANY'];
        } elseif ($method === 'view') {
            // Laravel registers Route::view as GET|HEAD.
            $methods = ['GET', 'HEAD'];
        }
        if ($methods === []) {
            $this->facts->addDiagnostic('LARAVEL_DYNAMIC_ROUTE', 'Dynamic route declaration was skipped.', $evidence);
            return;
        }
        // The second argument of view, redirect and permanentRedirect is a view name or a
        // URL, never a controller action, so a '@' in it must not read as Class@method.
        $shortcut = in_array($method, ['view', 'redirect', 'permanentredirect'], true);
        $this->addRoute($methods, $uri, $shortcut ? [] : $this->action($args[$actionIndex]->value ?? null), $modifiers, $evidence);
    }

    /**
     * `Route::resource('photos', PhotoController::class)`: one route per
     * conventional action, narrowed by `->only()` and `->except()`.
     *
     * @param list<Node\Arg> $args
     * @param array{middleware: list<string>, name: string, only: ?list<string>, except: list<string>, dynamic: bool} $modifiers
     */
    private function resourceRoutes(bool $api, array $args, array $modifiers, Node $evidence): void
    {
        $name = LaravelFactStore::string($args[0]->value ?? null);
        $controller = LaravelFactStore::string($args[1]->value ?? null);
        $class = LaravelFactStore::classArgument($args[1]->value ?? null)
            ?? ($controller === null ? null : ltrim($this->inGroupNamespace($controller), '\\'));
        if ($modifiers['dynamic']) {
            $this->facts->addDiagnostic('LARAVEL_DYNAMIC_ROUTE', 'Dynamic resource route actions were kept unnarrowed.', $evidence);
        }
        if ($name === null || $class === null) {
            $this->facts->addDiagnostic('LARAVEL_DYNAMIC_ROUTE', 'Dynamic resource route declaration was skipped.', $evidence);
            return;
        }
        // `photos.comments` nests: /photos/{photo}/comments/{comment}.
        $segments = explode('.', $name);
        $base = '';
        $parameter = '';
        foreach ($segments as $index => $segment) {
            $parameter = '{' . str_replace('-', '_', self::singular($segment)) . '}';
            $base .= '/' . $segment . ($index < count($segments) - 1 ? '/' . $parameter : '');
        }
        foreach (self::RESOURCE_ACTIONS as $action => [$methods, $item, $suffix]) {
            if (($api && ($action === 'create' || $action === 'edit'))
                || ($modifiers['only'] !== null && !in_array($action, $modifiers['only'], true))
                || in_array($action, $modifiers['except'], true)) {
                continue;
            }
            $uri = $base . ($item ? '/' . $parameter : '') . $suffix;
            $target = ['reference' => 'php:method:' . $class . '::' . $action, 'label' => $class . '::' . $action];
            // Laravel names each action `photos.index` inside any group name.
            $this->addRoute($methods, $uri, $target, ['name' => $name . '.' . $action] + $modifiers, $evidence);
        }
    }

    /** Whether a modifier argument is a literal action name or a literal list of them. */
    private static function isLiteralActionList(Node $value): bool
    {
        if ($value instanceof Node\Scalar\String_) {
            return true;
        }
        if (!$value instanceof Expr\Array_) {
            return false;
        }
        foreach ($value->items as $item) {
            if (!$item?->value instanceof Node\Scalar\String_) {
                return false;
            }
        }

        return true;
    }

    /** Laravel's singular for a resource segment, for the common English plurals. */
    private static function singular(string $word): string
    {
        return match (true) {
            str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    /**
     * Record one route node, its action edge and its middleware.
     *
     * @param list<string> $methods
     * @param array{reference?: string, label?: string} $action
     * @param array{middleware: list<string>, name: string, only: ?list<string>, except: list<string>, dynamic: bool} $modifiers
     */
    private function addRoute(array $methods, string $uri, array $action, array $modifiers, Node $evidence): void
    {
        $group = $this->combinedGroup();
        $uri = $this->joinUri($group['prefix'], $uri);
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

    /**
     * The HTTP methods and URI a Route facade call declares.
     *
     * @return array{0: string, 1: list<Node\Arg>, 2: array{middleware: list<string>, name: string, only: ?list<string>, except: list<string>, dynamic: bool}, 3: Node}|null
     */
    private function routeDescriptor(Expr\MethodCall|Expr\StaticCall $node): ?array
    {
        $modifiers = ['middleware' => [], 'name' => '', 'only' => null, 'except' => [], 'dynamic' => false];
        $cursor = $node;
        while ($cursor instanceof Expr\MethodCall) {
            $name = $cursor->name instanceof Identifier ? strtolower($cursor->name->toString()) : '';
            if ($name === 'middleware') {
                $modifiers['middleware'] = [...LaravelFactStore::strings($cursor->args[0]->value ?? null), ...$modifiers['middleware']];
            } elseif ($name === 'name') {
                $modifiers['name'] = LaravelFactStore::string($cursor->args[0]->value ?? null) ?? $modifiers['name'];
            } elseif ($name === 'only' || $name === 'except') {
                // `->only(['index', 'show'])` or `->only('index', 'show')`. An
                // argument that is not a literal narrows nothing that can be
                // read: an `only` keeps every action and an `except` removes
                // none, and the route says so.
                $actions = [];
                $literal = true;
                foreach ($cursor->args as $argument) {
                    if (!$argument instanceof Node\Arg || !self::isLiteralActionList($argument->value)) {
                        $literal = false;
                        continue;
                    }
                    $actions = [...$actions, ...LaravelFactStore::strings($argument->value)];
                }
                $modifiers[$name] = $literal ? $actions : ($name === 'only' ? null : []);
                $modifiers['dynamic'] = $modifiers['dynamic'] || !$literal;
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
    /** Whether this call opens a route group whose prefix and middleware apply to its children. */

    private function isGroupCall(Expr\MethodCall|Expr\StaticCall $node): bool
    {
        if (!$node->name instanceof Identifier || strtolower($node->name->toString()) !== 'group') {
            return false;
        }
        $cursor = $node;
        while ($cursor instanceof Expr\MethodCall) {
            $cursor = $cursor->var;
        }
        return $cursor instanceof Expr\StaticCall && $cursor->class instanceof Name && $this->isRouteFacade($cursor->class);
    }

    /**
     * The prefix, middleware, name and namespace a group contributes to the
     * routes inside it, from its chained modifiers (`Route::prefix('x')->group(...)`)
     * and from an attribute array (`Route::group(['prefix' => 'x'], ...)`).
     *
     * @return array{prefix: string, middleware: list<string>, name: string, namespace: ?string, controller: ?string}
     */
    private function groupModifiers(Expr\MethodCall|Expr\StaticCall $node): array
    {
        $result = ['prefix' => '', 'middleware' => [], 'name' => '', 'namespace' => null, 'controller' => null];
        $attributes = $node->args[0]->value ?? null;
        if ($attributes instanceof Expr\Array_ && count($node->args) > 1) {
            foreach ($attributes->items as $item) {
                $key = LaravelFactStore::string($item?->key);
                if ($item !== null && $key !== null && isset(self::GROUP_KEYS[$key])) {
                    $result = self::withModifier($result, self::GROUP_KEYS[$key], $item->value);
                }
            }
        }
        $cursor = $node instanceof Expr\MethodCall ? $node->var : null;
        while ($cursor instanceof Expr\MethodCall || $cursor instanceof Expr\StaticCall) {
            $name = $cursor->name instanceof Identifier ? strtolower($cursor->name->toString()) : '';
            $result = self::withModifier($result, $name, $cursor->args[0]->value ?? null);
            $cursor = $cursor instanceof Expr\MethodCall ? $cursor->var : null;
        }
        return $result;
    }

    /**
     * A group's attributes with one modifier applied: middleware accumulates
     * in the order written, and any other modifier replaces the value read
     * before it.
     *
     * @param array{prefix: string, middleware: list<string>, name: string, namespace: ?string, controller: ?string} $result
     * @return array{prefix: string, middleware: list<string>, name: string, namespace: ?string, controller: ?string}
     */
    private static function withModifier(array $result, string $modifier, ?Node $value): array
    {
        if ($modifier === 'middleware') {
            $result['middleware'] = [...LaravelFactStore::strings($value), ...$result['middleware']];
        } elseif ($modifier === 'prefix' || $modifier === 'name') {
            $result[$modifier] = LaravelFactStore::string($value) ?? $result[$modifier];
        } elseif ($modifier === 'namespace') {
            $result['namespace'] = LaravelFactStore::string($value) ?? $result['namespace'];
        } elseif ($modifier === 'controller') {
            $result['controller'] = LaravelFactStore::classArgument($value) ?? $result['controller'];
        }
        return $result;
    }

    /**
     * Compose the enclosing groups, since a registered URI exists only as their concatenation.
     *
     * A group's namespace nests inside the one around it, as Laravel nests
     * it, unless it starts with a backslash.
     *
     * @return array{prefix: string, middleware: list<string>, name: string, namespace: ?string, controller: ?string}
     */
    private function combinedGroup(): array
    {
        $result = ['prefix' => '', 'middleware' => [], 'name' => '', 'namespace' => null, 'controller' => null];
        foreach ($this->groups as $group) {
            $result['prefix'] = $this->joinUri($result['prefix'], $group['prefix']);
            $result['middleware'] = [...$result['middleware'], ...$group['middleware']];
            $result['name'] .= $group['name'];
            // The innermost group naming a controller is the one its routes use.
            $result['controller'] = $group['controller'] ?? $result['controller'];
            if ($group['namespace'] !== null) {
                $result['namespace'] = $result['namespace'] !== null && !str_starts_with($group['namespace'], '\\')
                    ? trim($result['namespace'], '\\') . '\\' . trim($group['namespace'], '\\')
                    : trim($group['namespace'], '\\');
            }
        }
        return $result;
    }

    /**
     * A controller named as a string, in the namespace of the groups around
     * it, as Laravel resolves it: a name starting with a backslash is already
     * fully qualified.
     */
    private function inGroupNamespace(string $controller): string
    {
        $namespace = $this->combinedGroup()['namespace'];

        return $namespace === null || $namespace === '' || str_starts_with($controller, '\\')
            ? $controller
            : $namespace . '\\' . $controller;
    }

    /**
     * The controller or closure a route dispatches to, when it is statically known.
     *
     * A `Class@method` string names its class in the namespace of the groups
     * around the route.
     *
     * @return array{reference?: string, label?: string}
     */
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
            // A controller class alone is invokable: Laravel calls its `__invoke`.
            return ['reference' => 'php:method:' . ltrim($class, '\\') . '::__invoke', 'label' => $class];
        }
        $string = LaravelFactStore::string($node);
        if ($string === null || $string === '') {
            return [];
        }
        if (str_contains($string, '@')) {
            $string = $this->inGroupNamespace($string);
            [$class, $method] = explode('@', $string, 2);
            return ['reference' => 'php:method:' . ltrim($class, '\\') . '::' . $method, 'label' => $string];
        }
        $controller = $this->combinedGroup()['controller'];
        if ($controller !== null) {
            // Inside `Route::controller(...)`, a string names the group controller's method.
            $controller = ltrim($controller, '\\');
            return ['reference' => 'php:method:' . $controller . '::' . $string, 'label' => $controller . '::' . $string];
        }
        // Otherwise the string names an invokable controller class.
        $class = ltrim($this->inGroupNamespace($string), '\\');
        return ['reference' => 'php:method:' . $class . '::__invoke', 'label' => $class];
    }
    /** Whether a call targets the Route facade rather than an unrelated `Route` symbol. */

    private function isRouteFacade(Name $name): bool
    {
        return strtolower(basename(str_replace('\\', '/', LaravelFactStore::name($name)))) === 'route';
    }
    /** Join a group prefix to a route path without doubling or dropping separators. */

    private function joinUri(string $prefix, string $uri): string
    {
        return '/' . trim(trim($prefix, '/') . '/' . trim($uri, '/'), '/');
    }
}
