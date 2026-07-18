<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final readonly class PhpScanner
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** @return array<string, mixed> */
    public function scan(string $root, string $absolutePath, string $relativePath, bool $laravel = false, bool $symfony = false): array
    {
        $source = file_get_contents($absolutePath);
        if ($source === false) {
            throw new WorkerInputException(sprintf('Unable to read PHP file: %s', $relativePath));
        }

        $errors = new Collecting();
        $ast = $this->parser->parse($source, $errors) ?? [];
        $collector = new FactCollector($relativePath);
        $laravelCollector = $laravel ? new LaravelFactCollector($relativePath) : null;
        $symfonyCollector = $symfony ? new SymfonyFactCollector($relativePath) : null;

        if ($ast !== []) {
            $resolver = new NodeTraverser();
            $resolver->addVisitor(new NameResolver($errors));
            $ast = $resolver->traverse($ast);

            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            if ($laravelCollector !== null) {
                $traverser->addVisitor($laravelCollector);
            }
            if ($symfonyCollector !== null) {
                $traverser->addVisitor($symfonyCollector);
            }
            $traverser->traverse($ast);
        }

        $diagnostics = $collector->diagnostics();
        foreach ($errors->getErrors() as $error) {
            $start = max(1, $error->getStartLine());
            $end = max($start, $error->getEndLine());
            $diagnostics[] = [
                'severity' => 'error',
                'code' => 'PHP_PARSE_ERROR',
                'message' => $error->getRawMessage(),
                'evidence' => [
                    'path' => $relativePath,
                    'start_line' => $start,
                    'end_line' => $end,
                ],
            ];
        }

        return [
            'owner_key' => 'knossos.php:file:' . $relativePath,
            'nodes' => [...$collector->nodes(), ...($laravelCollector?->nodes() ?? []), ...($symfonyCollector?->nodes() ?? [])],
            'edges' => [...$collector->edges(), ...($laravelCollector?->edges() ?? []), ...($symfonyCollector?->edges() ?? [])],
            'diagnostics' => [...$diagnostics, ...($laravelCollector?->diagnostics() ?? []), ...($symfonyCollector?->diagnostics() ?? [])],
        ];
    }
}
