<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A package published for others to install is called by code outside the
 * repository. A Composer library and a buildable Python package with no
 * command to install report their public API as used; what they keep
 * private is still reported.
 */
#[Group('query')]
final class ManifestLibraryApiTest extends KnossosTestCase
{
    public function testAPublishedLibrarysPublicApiIsNotDeadCode(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-library-api-' . bin2hex(random_bytes(6));
        $files = [
            'sdk/composer.json' => '{"name":"acme/sdk","type":"library","autoload":{"psr-4":{"Sdk\\\\":"src/"}}}',
            'sdk/src/Client.php' => "<?php\nnamespace Sdk;\n\nclass Client\n{\n    public function send(): void {}\n\n    protected function hook(): void {}\n\n    private function unusedPrivate(): void {}\n}\n",
            'py/pyproject.toml' => "[build-system]\nrequires = [\"hatchling\"]\n\n[project]\nname = \"pylib\"\n",
            'py/pylib/__init__.py' => '',
            'py/pylib/core.py' => "class Api:\n    def call(self):\n        return 1\n\n    def _internal(self):\n        return 2\n\n\ndef public_fn():\n    return 3\n\n\ndef _helper():\n    return 4\n",
        ];
        foreach ($files as $relative => $contents) {
            if (!is_dir(dirname($root . '/' . $relative))) {
                mkdir(dirname($root . '/' . $relative), 0o777, true);
            }
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
        $reported = static fn(string $suffix): bool => array_filter($names, static fn(string $name): bool => str_ends_with($name, $suffix)) !== [];
        foreach (['Sdk\\Client', 'Sdk\\Client::send', 'Sdk\\Client::hook', '.Api', '.Api::call', '.public_fn'] as $published) {
            self::assertFalse($reported($published), $published);
        }
        foreach (['Sdk\\Client::unusedPrivate', '.Api::_internal', '._helper'] as $private) {
            self::assertTrue($reported($private), $private);
        }
    }
}
