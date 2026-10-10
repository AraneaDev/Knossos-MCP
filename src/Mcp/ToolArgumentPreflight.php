<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Query\ArchitecturePolicyQueryService;
use Knossos\Query\QualityGateQueryService;

/**
 * The argument rules a tool's service would apply, checked before any work.
 *
 * ToolService runs this after a handler has parsed its arguments and before
 * refresh_if_stale may rescan, so a call that is going to be refused costs no
 * rescan. Nothing here is a second copy of a rule: enums come from the tool
 * schemas in ToolCatalog, and policies and budgets go through the same static
 * validators the services use. Messages are the services' own.
 */
final class ToolArgumentPreflight
{
    /**
     * Refuse a call whose enums, policies or budgets the service would refuse.
     *
     * @param array<string, mixed> $arguments
     */
    public static function check(string $tool, array $arguments): void
    {
        self::enums($arguments, (array) ToolCatalog::schemaFor($tool));
        $policies = $arguments['policies'] ?? null;
        // review_diff is left out on purpose: it reports invalid policies or
        // budgets as `not_evaluated` in a successful result rather than failing.
        if ($tool === 'quality_gate' && is_array($arguments['budgets'] ?? null)) {
            QualityGateQueryService::validateBudgets($arguments['budgets'], is_array($policies) ? $policies : []);
        }
        // check_architecture compiles any list it is given (an empty one is an
        // error); the gate compiles its list whenever it is non-empty.
        $compiled = match ($tool) {
            'check_architecture' => is_array($policies),
            'quality_gate' => is_array($policies) && $policies !== [],
            default => false,
        };
        if ($compiled) {
            ArchitecturePolicyQueryService::validatePolicies((array) $policies);
        }
    }

    /**
     * Every value a tool's schema restricts to an enum, checked against it now
     * rather than in the service after a rescan. Driven by ToolCatalog, so an
     * enum added to a schema is covered without touching this. A non-string is
     * left to the handler, which reports it as the wrong type.
     *
     * @param array<string, mixed> $arguments
     * @param array{enums?: array<string, list<string>>, itemEnums?: array<string, list<string>>} $schema
     */
    private static function enums(array $arguments, array $schema): void
    {
        foreach ($schema['enums'] ?? [] as $key => $allowed) {
            $value = $arguments[$key] ?? null;
            if (is_string($value) && !in_array(trim($value), $allowed, true)) {
                throw new InvalidArgumentException(self::enumMessage($key, $allowed));
            }
        }
        foreach ($schema['itemEnums'] ?? [] as $key => $allowed) {
            $values = $arguments[$key] ?? null;
            foreach (is_array($values) ? $values : [] as $value) {
                if (is_string($value) && !in_array(trim($value), $allowed, true)) {
                    throw new InvalidArgumentException(self::enumMessage($key, $allowed));
                }
            }
        }
    }

    /**
     * The message the service itself gives for a value outside the enum, so
     * refusing it earlier changes when the error comes, not what it says.
     *
     * @param list<string> $allowed
     */
    private static function enumMessage(string $key, array $allowed): string
    {
        $last = array_pop($allowed);

        return match ($key) {
            'min_confidence' => 'min_confidence must be possible, probable, or certain.',
            'mode' => 'Scan mode must be auto, full, or incremental.',
            'confidences' => 'confidence filter is invalid.',
            'severity', 'kind' => sprintf('%s must be one of: %s.', $key, implode(', ', [...$allowed, $last])),
            default => sprintf('%s must be %s%s or %s.', $key, implode(', ', $allowed), count($allowed) > 1 ? ',' : '', $last),
        };
    }
}
