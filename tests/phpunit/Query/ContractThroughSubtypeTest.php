<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A class can fulfil an interface with a method it inherits: a base class,
 * or a trait it uses, supplies the implementation, and the class declares
 * the interface. A call through the interface lands on the base's or the
 * trait's method, which then read as dead because no edge names it.
 */
#[Group('query')]
final class ContractThroughSubtypeTest extends KnossosTestCase
{
    public function testAnInheritedImplementationOfAnInterfaceIsNotDead(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-contract-subtype-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/web', 0o777, true);
        $files = [
            'composer.json' => '{"name":"app/app","autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Lookups.php' => "<?php\nnamespace App;\ninterface Lookups { public function installedBase(): array; }\n",
            'src/Unsupported.php' => "<?php\nnamespace App;\ntrait Unsupported {\n    public function installedBase(): array { return []; }\n    public function unused(): void {}\n}\n",
            // One user overrides the trait's method; the other relies on it.
            'src/Custom.php' => "<?php\nnamespace App;\nfinal class Custom implements Lookups {\n    use Unsupported;\n    public function installedBase(): array { return [1]; }\n}\n",
            'src/Adapter.php' => "<?php\nnamespace App;\nfinal class Adapter implements Lookups { use Unsupported; }\n",
            'src/Service.php' => "<?php\nnamespace App;\nfinal class Service {\n    public function __construct(private Lookups \$lookups) {}\n    public function run(): array { return \$this->lookups->installedBase(); }\n}\n",
            'package.json' => '{"name":"web","private":true}',
            'web/adapter.ts' => implode("\n", [
                'export interface HookAdapter { memoryDir(): string; }',
                'export class BaseAdapter {',
                "    memoryDir(): string { return '.'; }",
                "    stale(): string { return ''; }",
                '}',
                'export class ClaudeAdapter extends BaseAdapter implements HookAdapter {}',
                'export function detect(): HookAdapter { return new ClaudeAdapter(); }',
                'export function search(): string { return detect().memoryDir(); }',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full')->projectId;
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        self::assertNotContains('App\\Unsupported::installedBase', $names);
        self::assertNotContains('web/adapter.ts#BaseAdapter::memoryDir', $names);
        // A member no interface of a subtype declares stays reportable.
        self::assertContains('App\\Unsupported::unused', $names);
        self::assertContains('web/adapter.ts#BaseAdapter::stale', $names);
    }
}
