<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ResultEnvelope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('result-envelope')]
final class ResultEnvelopeTest extends TestCase
{
    // ----- class shape -----

    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(ResultEnvelope::class))->isFinal());
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new ReflectionClass(ResultEnvelope::class))->isReadOnly());
    }

    public function testImplementsJsonSerializable(): void
    {
        $this->assertInstanceOf(\JsonSerializable::class, new ResultEnvelope('p', 's', 'sum', []));
    }

    // ----- constructor -----

    public function testConstructorStoresAllPropertiesFromArguments(): void
    {
        $envelope = new ResultEnvelope(
            projectId: 'project-1',
            snapshotId: 'snapshot-1',
            summary: 'the summary',
            data: ['key' => 'value'],
            evidence: [['origin' => 'a']],
            warnings: ['w1'],
            truncated: true,
            staleness: ['stale_at' => '2026-01-01'],
            nextSteps: [['step' => 'do x']],
            meta: ['meta_k' => 'm'],
        );

        assertSame('project-1', $envelope->projectId);
        assertSame('snapshot-1', $envelope->snapshotId);
        assertSame('the summary', $envelope->summary);
        assertSame(['key' => 'value'], $envelope->data);
        assertSame([['origin' => 'a']], $envelope->evidence);
        assertSame(['w1'], $envelope->warnings);
        assertSame(true, $envelope->truncated);
        assertSame(['stale_at' => '2026-01-01'], $envelope->staleness);
        assertSame([['step' => 'do x']], $envelope->nextSteps);
        assertSame(['meta_k' => 'm'], $envelope->meta);
    }

    public function testConstructorDefaultValuesForOptionalArguments(): void
    {
        $envelope = new ResultEnvelope('p', 's', 'sum', ['k' => 'v']);

        assertSame([], $envelope->evidence);
        assertSame([], $envelope->warnings);
        assertSame(false, $envelope->truncated);
        assertSame(null, $envelope->staleness);
        assertSame([], $envelope->nextSteps);
        assertSame(null, $envelope->meta);
    }

    public function testConstructorAcceptsEmptyDataArray(): void
    {
        $envelope = new ResultEnvelope('p', 's', 'sum', []);
        assertSame([], $envelope->data);
    }

    // ----- with() staleness -----

    public function testWithReplacesStalenessWhenNonNullValueSupplied(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], staleness: ['old' => true]);
        $updated = $original->with(staleness: ['new' => true]);

        assertSame(['new' => true], $updated->staleness);
        assertSame(['old' => true], $original->staleness);
    }

    public function testWithPreservesStalenessWhenNullPassed(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], staleness: ['orig' => 'v']);
        $updated = $original->with(staleness: null);

        assertSame(['orig' => 'v'], $updated->staleness);
    }

    public function testWithReplacingStalenessWithEmptyArrayClearsItNotKeepsOriginal(): void
    {
        // CRITICAL: this kills the `??` → `?:` mutation. Under `??`: `[] ?? ['orig']`
        // returns `[]` (since LHS is non-null even if empty). Under `?:`: empty array
        // is falsy, would return `['orig']` — wrong result.
        $original = new ResultEnvelope('p', 's', 'sum', [], staleness: ['orig' => 'v']);
        $updated = $original->with(staleness: []);

        assertSame([], $updated->staleness);
    }

    public function testWithPreservesExistingStalenessWhenOtherArgsPassed(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], staleness: ['s' => 1]);
        $updated = $original->with(nextSteps: [['x']]);

        assertSame(['s' => 1], $updated->staleness);
    }

    // ----- with() nextSteps -----

    public function testWithReplacesNextStepsWhenNonEmptyArraySupplied(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], nextSteps: [['old' => 1]]);
        $updated = $original->with(nextSteps: [['new' => 1]]);

        assertSame([['new' => 1]], $updated->nextSteps);
    }

    public function testWithPreservesNextStepsWhenNullPassed(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], nextSteps: [['x']]);
        $updated = $original->with(nextSteps: null);

        assertSame([['x']], $updated->nextSteps);
    }

    public function testWithReplacingNextStepsWithEmptyArrayClearsItNotKeepsOriginal(): void
    {
        // Same `??` vs `?:` mutation kill for the nextSteps field.
        $original = new ResultEnvelope('p', 's', 'sum', [], nextSteps: [['orig']]);
        $updated = $original->with(nextSteps: []);

        assertSame([], $updated->nextSteps);
    }

    // ----- with() meta -----

    public function testWithReplacesMetaWhenNonNullValueSupplied(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], meta: ['k1' => 1]);
        $updated = $original->with(meta: ['k2' => 2]);

        assertSame(['k2' => 2], $updated->meta);
    }

    public function testWithPreservesMetaWhenNullPassed(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], meta: ['kept' => true]);
        $updated = $original->with(meta: null);

        assertSame(['kept' => true], $updated->meta);
    }

    public function testWithReplacingMetaWithEmptyArrayClearsItNotKeepsOriginal(): void
    {
        $original = new ResultEnvelope('p', 's', 'sum', [], meta: ['orig' => 1]);
        $updated = $original->with(meta: []);

        assertSame([], $updated->meta);
    }

    // ----- with() preserves other fields -----

    public function testWithPreservesAllImmutableFieldsWhenCalled(): void
    {
        $envelope = new ResultEnvelope(
            projectId: 'pid',
            snapshotId: 'sid',
            summary: 'sum',
            data: ['d' => 1],
            evidence: [['e' => 1]],
            warnings: ['w'],
            truncated: true,
            staleness: null,
            nextSteps: [],
            meta: null,
        );

        $updated = $envelope->with(
            staleness: ['fresh' => 2],
            nextSteps: [['a']],
            meta: ['m' => 3],
        );

        assertSame('pid', $updated->projectId);
        assertSame('sid', $updated->snapshotId);
        assertSame('sum', $updated->summary);
        assertSame(['d' => 1], $updated->data);
        assertSame([['e' => 1]], $updated->evidence);
        assertSame(['w'], $updated->warnings);
        assertSame(true, $updated->truncated);
    }

    public function testWithReturnsNewInstanceEachCall(): void
    {
        $envelope = new ResultEnvelope('p', 's', 'sum', []);
        $first = $envelope->with(staleness: ['a']);
        $second = $first->with(staleness: ['b']);

        assertSame(['a'], $first->staleness);
        assertSame(['b'], $second->staleness);
        assertSame(null, $envelope->staleness);
    }

    // ----- jsonSerialize() base fields -----

    public function testJsonSerializeIncludesAllRequiredBaseFields(): void
    {
        $envelope = new ResultEnvelope(
            projectId: 'pid',
            snapshotId: 'sid',
            summary: 'sum',
            data: ['d' => 1],
            evidence: [['e' => 1]],
            warnings: ['w'],
            truncated: true,
        );
        $array = $envelope->jsonSerialize();

        assertSame(
            ['project_id', 'snapshot_id', 'summary', 'data', 'evidence', 'warnings', 'truncated'],
            array_keys($array),
        );
        assertSame('pid', $array['project_id']);
        assertSame('sid', $array['snapshot_id']);
        assertSame('sum', $array['summary']);
        assertSame(['d' => 1], $array['data']);
        assertSame([['e' => 1]], $array['evidence']);
        assertSame(['w'], $array['warnings']);
        assertSame(true, $array['truncated']);
    }

    public function testJsonSerializeAcceptsEmptyBaseValues(): void
    {
        // The ctor's first three args are non-nullable strings — passing '' exercises
        // the empty-string path. Evidence/warnings default to [], truncated to false.
        $array = (new ResultEnvelope('', '', '', []))->jsonSerialize();
        assertSame('', $array['project_id']);
        assertSame('', $array['snapshot_id']);
        assertSame('', $array['summary']);
        assertSame([], $array['data']);
        assertSame([], $array['evidence']);
        assertSame([], $array['warnings']);
        assertSame(false, $array['truncated']);
    }

    // ----- jsonSerialize() staleness conditional -----

    public function testJsonSerializeOmitsStalenessWhenNull(): void
    {
        $array = (new ResultEnvelope('p', 's', 'sum', [], staleness: null))->jsonSerialize();
        $this->assertArrayNotHasKey('staleness', $array);
    }

    public function testJsonSerializeIncludesStalenessWhenSetToNonEmptyArray(): void
    {
        $array = (new ResultEnvelope('p', 's', 'sum', [], staleness: ['k' => 'v']))->jsonSerialize();
        $this->assertArrayHasKey('staleness', $array);
        assertSame(['k' => 'v'], $array['staleness']);
    }

    public function testJsonSerializeIncludesStalenessWhenEmptyArray(): void
    {
        // Kills `!== null` mutation to `!== []`. Under original: `[] !== null` is
        // true, key included. Under mutated: `[] !== []` is false, key omitted.
        $array = (new ResultEnvelope('p', 's', 'sum', [], staleness: []))->jsonSerialize();
        $this->assertArrayHasKey('staleness', $array);
        assertSame([], $array['staleness']);
    }

    // ----- jsonSerialize() nextSteps conditional -----

    public function testJsonSerializeOmitsNextStepsWhenEmpty(): void
    {
        $array = (new ResultEnvelope('p', 's', 'sum', [], nextSteps: []))->jsonSerialize();
        $this->assertArrayNotHasKey('next_steps', $array);
    }

    public function testJsonSerializeIncludesNextStepsWhenSet(): void
    {
        $array = (new ResultEnvelope('p', 's', 'sum', [], nextSteps: [['step' => 'a']]))->jsonSerialize();
        $this->assertArrayHasKey('next_steps', $array);
        assertSame([['step' => 'a']], $array['next_steps']);
    }

    // ----- jsonSerialize() meta conditional -----

    public function testJsonSerializeOmitsMetaWhenNull(): void
    {
        $array = (new ResultEnvelope('p', 's', 'sum', [], meta: null))->jsonSerialize();
        $this->assertArrayNotHasKey('meta', $array);
    }

    public function testJsonSerializeIncludesMetaWhenSetToNonEmptyArray(): void
    {
        $array = (new ResultEnvelope('p', 's', 'sum', [], meta: ['k' => 1]))->jsonSerialize();
        $this->assertArrayHasKey('meta', $array);
        assertSame(['k' => 1], $array['meta']);
    }

    public function testJsonSerializeIncludesMetaWhenEmptyArray(): void
    {
        // Same `!== null` vs `!== []` kill for meta.
        $array = (new ResultEnvelope('p', 's', 'sum', [], meta: []))->jsonSerialize();
        $this->assertArrayHasKey('meta', $array);
        assertSame([], $array['meta']);
    }

    // ----- jsonSerialize() shape integration -----

    public function testJsonSerializeReturnsAllOptionalFieldsWhenAllSet(): void
    {
        $envelope = new ResultEnvelope(
            projectId: 'pid',
            snapshotId: 'sid',
            summary: 'sum',
            data: ['d' => 1],
            evidence: [['e' => 1]],
            warnings: ['w'],
            truncated: true,
            staleness: ['s' => 1],
            nextSteps: [['x' => 1]],
            meta: ['m' => 1],
        );

        $array = $envelope->jsonSerialize();

        assertSame(
            ['project_id', 'snapshot_id', 'summary', 'data', 'evidence', 'warnings', 'truncated', 'staleness', 'next_steps', 'meta'],
            array_keys($array),
        );
    }

    public function testJsonSerializeVerifiesSnakeCaseKeys(): void
    {
        $array = (new ResultEnvelope('pid', 'sid', 'sum', [], nextSteps: [['x']]))->jsonSerialize();
        $this->assertArrayHasKey('project_id', $array);
        $this->assertArrayHasKey('snapshot_id', $array);
        $this->assertArrayHasKey('next_steps', $array);
    }

    public function testJsonEncodeProducesValidJsonString(): void
    {
        $envelope = new ResultEnvelope(
            projectId: 'pid',
            snapshotId: 'sid',
            summary: 'sum',
            data: ['count' => 5],
            evidence: [],
            warnings: ['w1', 'w2'],
            truncated: false,
        );

        $decoded = json_decode(json_encode($envelope), true, 512, JSON_THROW_ON_ERROR);

        assertSame('pid', $decoded['project_id']);
        assertSame(['count' => 5], $decoded['data']);
        assertSame(['w1', 'w2'], $decoded['warnings']);
        assertSame(false, $decoded['truncated']);
    }

    public function testWithDoesNotMutateOriginalEnvelope(): void
    {
        $original = new ResultEnvelope(
            projectId: 'pid',
            snapshotId: 'sid',
            summary: 'sum',
            data: ['d' => 1],
            evidence: [],
            warnings: [],
            truncated: false,
            staleness: ['orig'],
            nextSteps: [],
            meta: null,
        );

        $copy = $original->with(staleness: ['new'], nextSteps: [['n']], meta: ['m' => 1]);

        // The original must remain unchanged.
        assertSame(['orig'], $original->staleness);
        assertSame([], $original->nextSteps);
        assertSame(null, $original->meta);

        // The copy has the new values.
        assertSame(['new'], $copy->staleness);
        assertSame([['n']], $copy->nextSteps);
        assertSame(['m' => 1], $copy->meta);
    }
}
