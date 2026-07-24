<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Scan\CancellationToken;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class EnvelopeBudgetTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testHeavyToolsStayUnderByteBudget(): void
    {
        [$svc, $projectId] = $this->toolServiceWithScannedFixture();
        $noop = new CancellationToken(static fn(): bool => false);
        $budgets = [
            'architecture_health' => 12000,
            'impact_analysis' => 8000,
            'changed_files_impact' => 8000,
        ];
        foreach ($budgets as $tool => $ceiling) {
            $args = ['project_id' => $projectId] + match ($tool) {
                'impact_analysis' => ['symbol' => 'CheckoutService'],
                // The fixture is scanned with tests/Fixtures/mixed as its root,
                // so the changed path must be project-relative or it resolves to
                // nothing and the budget passes without real payload.
                'changed_files_impact' => ['files' => ['src/CheckoutService.php']],
                default => [],
            };
            $envelope = $svc->call($tool, $args, $noop);
            if ($tool === 'changed_files_impact') {
                // Guard against a vacuous budget check: the file must resolve to
                // at least one component so the payload is actually exercised.
                self::assertNotSame([], $envelope->data['direct_components'] ?? [], 'changed_files_impact resolved no components');
            }
            $bytes = strlen((string) json_encode($envelope->jsonSerialize(), JSON_UNESCAPED_SLASHES));
            // The global assertSame() helper takes no message parameter (see
            // tests/phpunit/Support/Assertions.php), so PHPUnit's own
            // assertTrue() is used here to keep the byte-count diagnostic on
            // failure.
            self::assertTrue($bytes <= $ceiling, sprintf('%s serialized to %d bytes (budget %d)', $tool, $bytes, $ceiling));
        }
    }
}
