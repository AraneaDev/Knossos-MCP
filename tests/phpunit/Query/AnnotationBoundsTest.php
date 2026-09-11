<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Annotations on their edges: the value ceiling, what a preview reports, the
 * words a write reports, and how a listing pages.
 *
 * AnnotationService scored 53% under mutation testing, the lowest of the query
 * services. The existing tests walk the happy path end to end, so the 2000-byte
 * ceiling, the offset ceiling, the kind allow-list on listing, the look-ahead
 * row that decides truncation, the next offset, and the singular and plural
 * summaries could all change with them green.
 */
final class AnnotationBoundsTest extends KnossosTestCase
{
    /** Two thousand bytes of value are accepted; one more is refused. */
    #[Group('query')]
    public function testTheValueCeilingIsTwoThousandBytes(): void
    {
        $queries = $this->queries();

        $written = $queries->annotateComponent($this->project, 'App\\Checkout', 'note', str_repeat('x', 2000), execute: true);

        assertSame(true, $written->data['executed']);
        assertSame(2000, strlen($written->data['annotation']['value']));
        assertThrows(
            fn() => $queries->annotateComponent($this->project, 'App\\Checkout', 'note', str_repeat('x', 2001), execute: true),
            InvalidArgumentException::class,
        );
    }

    /**
     * A component of nothing but whitespace is no component at all, and is
     * refused as that rather than as something else.
     *
     * The message is what this asserts. Whitespace that gets past this guard is
     * still refused further in, by the resolver, as an empty flow endpoint, so a
     * test that only demands an exception cannot tell the guard from its
     * absence, and the caller is told the wrong argument is wrong.
     */
    #[Group('query')]
    public function testAComponentOfWhitespaceIsRefusedAsAnEmptyComponent(): void
    {
        $queries = $this->queries();

        foreach (['', '   ', "\t\n"] as $blank) {
            $error = captureThrows(
                fn() => $queries->annotateComponent($this->project, $blank, 'note', 'x', execute: true),
                InvalidArgumentException::class,
            );

            assertSame('component must not be empty.', $error->getMessage());
        }
    }

    /**
     * A preview says exactly what it would do, and does none of it.
     *
     * The removal arm carries a null annotation, because there would be nothing
     * left to report once the removal ran.
     */
    #[Group('query')]
    public function testThePreviewReportsTheWholeChangeAndWritesNothing(): void
    {
        [$pdo, $queries] = $this->connectedQueries();

        $preview = $queries->annotateComponent($this->project, 'App\\Checkout', 'note', 'core flow');

        assertSame(['component', 'kind', 'action', 'executed', 'previous', 'annotation'], array_keys($preview->data));
        assertSame('upsert', $preview->data['action']);
        assertSame(null, $preview->data['previous']);
        assertSame(['value' => 'core flow'], $preview->data['annotation']);
        assertSame('Preview: would upsert note annotation on App\\Checkout.', $preview->summary);
        assertSame('Set execute=true to apply the change.', $preview->warnings[count($preview->warnings) - 1]);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());

        $removal = $queries->annotateComponent($this->project, 'App\\Checkout', 'note', 'core flow', remove: true);

        assertSame('remove', $removal->data['action']);
        assertSame(null, $removal->data['annotation'], 'A removal previews no surviving annotation.');
    }

    /** A write says which of the two things it did. */
    #[Group('query')]
    public function testTheSummarySaysWhetherItRecordedOrRemoved(): void
    {
        $queries = $this->queries();

        $recorded = $queries->annotateComponent($this->project, 'App\\Checkout', 'note', 'core flow', execute: true);
        assertSame('Recorded note annotation on App\\Checkout.', $recorded->summary);

        $removed = $queries->annotateComponent($this->project, 'App\\Checkout', 'note', remove: true, execute: true);
        assertSame('Removed note annotation on App\\Checkout.', $removed->summary);
        assertSame(null, $removed->data['annotation']);
        assertSame('core flow', $removed->data['previous']['value'], 'What was there is reported, because it is gone now.');
    }

    /**
     * A listing fetches one row past the page to learn whether another page
     * exists, returns only the page, and points at where the next one starts.
     */
    #[Group('query')]
    public function testAListingPagesOnALookAheadRow(): void
    {
        $queries = $this->queries();
        foreach (['App\\Alpha', 'App\\Beta', 'App\\Gamma'] as $name) {
            $queries->annotateComponent($this->project, $name, 'note', 'x', execute: true);
        }

        $page = $queries->listAnnotations($this->project, limit: 2);

        assertSame(2, count($page->data['annotations']), 'The look-ahead row is fetched, never returned.');
        assertSame(true, $page->truncated);
        assertSame(2, $page->data['pagination']['next_offset']);
        assertSame(0, $page->data['pagination']['offset']);
        assertSame('Found 2 annotations.', $page->summary);
        assertSame(['App\\Alpha', 'App\\Beta'], array_column($page->data['annotations'], 'canonical_name'));

        $whole = $queries->listAnnotations($this->project, limit: 3);

        assertSame(3, count($whole->data['annotations']));
        assertSame(false, $whole->truncated, 'Exactly a page full is not a truncation.');
        assertSame(null, $whole->data['pagination']['next_offset']);

        $single = $queries->listAnnotations($this->project, limit: 1);

        assertSame('Found 1 annotation.', $single->summary);
        assertSame(1, $single->data['pagination']['next_offset']);
    }

    /** The second page starts where the first one said it would. */
    #[Group('query')]
    public function testTheNextOffsetNamesTheStartOfTheNextPage(): void
    {
        $queries = $this->queries();
        foreach (['App\\Alpha', 'App\\Beta', 'App\\Gamma'] as $name) {
            $queries->annotateComponent($this->project, $name, 'note', 'x', execute: true);
        }

        $next = $queries->listAnnotations($this->project, limit: 2, offset: 2);

        assertSame(['App\\Gamma'], array_column($next->data['annotations'], 'canonical_name'));
        assertSame(2, $next->data['pagination']['offset']);
        assertSame(null, $next->data['pagination']['next_offset']);
    }

    /** The advertised offset ceiling is accepted, and one past it is refused. */
    #[Group('query')]
    public function testTheOffsetCeilingIsAHundredThousand(): void
    {
        $queries = $this->queries();

        assertSame([], $queries->listAnnotations($this->project, offset: 100_000)->data['annotations']);
        assertThrows(fn() => $queries->listAnnotations($this->project, offset: 100_001), InvalidArgumentException::class);
        assertThrows(fn() => $queries->listAnnotations($this->project, offset: -1), InvalidArgumentException::class);
        // The limit is checked too, by the shared guard rather than here.
        assertThrows(fn() => $queries->listAnnotations($this->project, limit: 0), InvalidArgumentException::class);
    }

    /** A listing filtered by a kind that is not a kind is refused, not answered empty. */
    #[Group('query')]
    public function testAListingRefusesAKindThatIsNotOne(): void
    {
        $queries = $this->queries();

        assertThrows(fn() => $queries->listAnnotations($this->project, kind: 'bogus_kind'), InvalidArgumentException::class);
        assertSame([], $queries->listAnnotations($this->project, kind: 'note')->data['annotations']);
    }

    /**
     * An ambiguous component names five candidates, whichever number matched.
     *
     * Six components share the prefix, so the exact-match query finds nothing
     * and the prefix fallback returns all six in canonical-name order. The
     * message names the first five of them: enough to disambiguate by hand,
     * short enough to read.
     */
    #[Group('query')]
    public function testTheAmbiguityMessageNamesTheFirstFiveCandidates(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        foreach (range(1, 6) as $index) {
            $name = 'App\\Amb' . $index;
            $repository->saveNode(
                \Knossos\Store\StableId::symbol($ids['project'], 'php', 'class', $name),
                $ids['project'],
                'php',
                'class',
                $name,
                'Amb' . $index,
                null,
                $ids['file'],
                10 + $index,
                20 + $index,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $error = captureThrows(
            fn() => $queries->annotateComponent($ids['project'], 'App\\Amb', 'note', 'x', execute: true),
            InvalidArgumentException::class,
        );

        assertSame(
            'Component is ambiguous; use a canonical name. Candidates: App\\Amb1, App\\Amb2, App\\Amb3, App\\Amb4, App\\Amb5.',
            $error->getMessage(),
        );
    }

    private string $project = '';

    private function queries(): ArchitectureQueryService
    {
        return $this->connectedQueries()[1];
    }

    /** @return array{0: \PDO, 1: ArchitectureQueryService} */
    private function connectedQueries(): array
    {
        if ($this->project !== '' && $this->connected !== null) {
            return $this->connected;
        }
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $this->project = $ids['project'];
        $this->connected = [$pdo, new ArchitectureQueryService($pdo)];

        return $this->connected;
    }

    /** @var array{0: \PDO, 1: ArchitectureQueryService}|null */
    private ?array $connected = null;
}
