<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every string length a tool advertises is the length it enforces, counted in
 * characters.
 *
 * JSON Schema's maxLength counts characters (code points). The handlers counted
 * bytes, so a note of 700 CJK characters was refused as over 2,000, or did not
 * count at all: path_contains, language and base_ref were advertised and never
 * checked. The limits are read from the schema, never restated here.
 */
final class ToolLengthAgreementTest extends KnossosTestCase
{
    use ToolArgumentProbes;

    #[Group('mcp')]
    public function testEveryAdvertisedMaxLengthIsEnforcedInCharacters(): void
    {
        [$tools, $project] = $this->tools();
        $checked = 0;

        foreach (ToolCatalog::definitions(false) as $definition) {
            $name = $definition['name'];
            foreach ((array) ($definition['inputSchema']['properties'] ?? []) as $key => $spec) {
                if (!is_array($spec) || ($spec['type'] ?? null) !== 'string' || !isset($spec['maxLength'])) {
                    continue;
                }
                $max = (int) $spec['maxLength'];
                $expected = sprintf('%s must be a string of at most %d characters.', $key, $max);
                $base = self::requiredArguments($definition, $project);

                assertSame(
                    $expected,
                    self::errorFrom($tools, $name, [...$base, $key => str_repeat('界', $max + 1)]),
                    sprintf('%s.%s: %d characters is over the advertised limit.', $name, $key, $max + 1),
                );
                assertNotSame(
                    $expected,
                    self::errorFrom($tools, $name, [...$base, $key => str_repeat('界', $max)]),
                    sprintf('%s.%s: %d characters (%d bytes) is within it.', $name, $key, $max, 3 * $max),
                );
                ++$checked;
            }
        }

        // A guard against the test silently checking nothing if the schema's shape changes.
        assertSame(true, $checked >= 8, sprintf('Expected to check at least 8 bounded strings, checked %d.', $checked));
    }

    /** A note that was not a string was stored as an empty note. */
    #[Group('mcp')]
    public function testANonStringNoteIsRefusedNotStoredEmpty(): void
    {
        [$tools, $project] = $this->tools();

        $error = self::errorFrom($tools, 'annotate_component', [
            'project_id' => $project, 'component' => 'App\\Checkout', 'kind' => 'note', 'value' => 42, 'execute' => true,
        ]);

        assertSame('value must be a string of at most 2000 characters.', $error);
        assertSame([], $tools->call('list_annotations', ['project_id' => $project])->data['annotations'], 'Nothing was written.');
    }

    /** A note's surrounding whitespace is content and is stored as given; other strings are trimmed. */
    #[Group('mcp')]
    public function testANoteIsStoredAsGivenAndOtherStringsAreTrimmed(): void
    {
        [$tools, $project] = $this->tools();

        $tools->call('annotate_component', ['project_id' => $project, 'component' => 'App\\Checkout', 'kind' => 'note', 'value' => "  padded\n", 'execute' => true]);

        assertSame("  padded\n", $tools->call('list_annotations', ['project_id' => $project])->data['annotations'][0]['value']);
        assertSame('language must be a non-empty string.', self::errorFrom($tools, 'file_metrics', ['project_id' => $project, 'language' => '   ']));
    }

    /** A note's value is optional: removing an annotation needs none, and an empty one is allowed. */
    #[Group('mcp')]
    public function testANoteValueMayBeOmittedOrEmpty(): void
    {
        [$tools, $project] = $this->tools();
        $arguments = ['project_id' => $project, 'component' => 'App\\Checkout', 'kind' => 'note'];

        assertSame(null, self::errorFrom($tools, 'annotate_component', $arguments));
        assertSame(null, self::errorFrom($tools, 'annotate_component', [...$arguments, 'value' => '']));
    }

    /** The bounded filters reach the query: a path_contains and a language within their limits select the fixture's file. */
    #[Group('mcp')]
    public function testFileMetricsFiltersReachTheQuery(): void
    {
        [$tools, $project] = $this->tools();

        $files = $tools->call('file_metrics', ['project_id' => $project, 'path_contains' => 'Checkout', 'language' => 'php'])->data['files'];

        assertSame(['src/Checkout.php'], array_column($files, 'path'));
    }
}
