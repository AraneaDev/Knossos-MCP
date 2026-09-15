<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfigurationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The published schema and the loader must accept the same keys.
 *
 * They drifted, silently and in the direction that hurts: the loader accepted
 * `limits.worker_memory_mb` and the schema did not declare it, so the one key
 * that raises a worker's heap was reported as invalid by any editor or CI step
 * validating a `knossos.json` against the published schema. The loader would
 * have honoured it. Nobody could tell, because nothing compared the two.
 *
 * Both directions fail here. A key in the schema the loader rejects is the same
 * defect seen from the other side: the schema would bless a file the loader
 * then refuses to load.
 */
#[Group('configuration')]
final class ProjectConfigurationSchemaAgreementTest extends TestCase
{
    /** @return array<string, array{list<string>, string}> */
    public static function sections(): array
    {
        return [
            'root' => [ProjectConfigurationLoader::ROOT_KEYS, ''],
            'limits' => [ProjectConfigurationLoader::LIMIT_KEYS, 'limits'],
            'quality_budgets' => [ProjectConfigurationLoader::BUDGET_KEYS, 'quality_budgets'],
            'boundaries' => [ProjectConfigurationLoader::BOUNDARY_KEYS, 'boundaries'],
            'policies' => [ProjectConfigurationLoader::POLICY_KEYS, 'policies'],
        ];
    }

    /**
     * @param list<string> $accepted
     */
    #[DataProvider('sections')]
    public function testSchemaDeclaresExactlyWhatTheLoaderAccepts(array $accepted, string $section): void
    {
        $declared = self::declaredProperties($section);

        sort($accepted);
        sort($declared);

        assertSame(
            $accepted,
            $declared,
            sprintf(
                'The schema and the loader disagree about "%s". Undeclared: %s. Unloadable: %s.',
                $section === '' ? 'the root object' : $section,
                implode(', ', array_diff($accepted, $declared)) ?: 'none',
                implode(', ', array_diff($declared, $accepted)) ?: 'none',
            ),
        );
    }

    /**
     * The property names the schema declares for a section.
     *
     * An array-valued section (boundaries, policies) declares its properties on
     * the item schema rather than on the array, so the lookup steps through
     * `items` where one is present.
     *
     * @return list<string>
     */
    private static function declaredProperties(string $section): array
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../schemas/project-config-v1.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $node = $schema;
        if ($section !== '') {
            $node = $schema['properties'][$section] ?? null;
            assertSame(true, is_array($node), sprintf('The schema declares no "%s" section at all.', $section));
        }
        $node = $node['items'] ?? $node;

        return array_keys($node['properties'] ?? []);
    }
}
