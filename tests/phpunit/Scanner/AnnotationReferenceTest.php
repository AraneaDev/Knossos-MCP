<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Doctrine-style annotations name classes in strings:
 * `@Gedmo\SlugHandler(class="App\Behavior\EnsureSlugUpdateHandler")`,
 * `@ORM\Entity(repositoryClass="App\Repository\AddonRepository")`. The
 * library instantiates what the string names, and nothing else refers to it,
 * so the handler or repository read as dead.
 */
final class AnnotationReferenceTest extends KnossosTestCase
{
    #[Group('php-scanner')]
    public function testAClassNamedInAnAnnotationStringIsReferenced(): void
    {
        $root = sys_get_temp_dir() . '/knossos-incremental-annotation-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o755, true)) {
            throw new \RuntimeException('Unable to create fixture tree.');
        }
        try {
            file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/annotation'], JSON_THROW_ON_ERROR));
            file_put_contents($root . '/src/Handlers.php', <<<'PHP'
                <?php

                namespace App\Behavior;

                final class EnsureSlugUpdateHandler
                {
                }

                final class AddonRepository
                {
                }
                PHP);
            file_put_contents($root . '/src/Country.php', <<<'PHP'
                <?php

                namespace App\Entity;

                /**
                 * @ORM\Entity(repositoryClass="App\Behavior\AddonRepository")
                 */
                final class Country
                {
                    /**
                     * @Gedmo\Slug(handlers={
                     *      @Gedmo\SlugHandler(class="App\Behavior\EnsureSlugUpdateHandler")
                     * })
                     */
                    private string $slug = '';
                }
                PHP);
            $pdo = SqliteConnection::open($root . '/graph.sqlite');
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full');

            $targets = $pdo->query(
                "SELECT t.canonical_name FROM edges e JOIN nodes t ON t.id = e.target_id " .
                "WHERE e.kind = 'references' AND t.canonical_name LIKE 'App\\Behavior\\%' ORDER BY 1",
            )->fetchAll(\PDO::FETCH_COLUMN);

            assertSame(['App\\Behavior\\AddonRepository', 'App\\Behavior\\EnsureSlugUpdateHandler'], array_values(array_unique($targets)));
        } finally {
            unset($pdo);
            $this->removeFixtureTree($root);
        }
    }
}
