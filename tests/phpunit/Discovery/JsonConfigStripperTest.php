<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The JSONC stripper on the documents that tell its state machine apart.
 *
 * JsonConfig scored 82% under mutation testing and almost every survivor is in
 * the two hand-written character scanners that remove comments and trailing
 * commas. Both track whether they are inside a string and whether the previous
 * character was a backslash, and the existing tests feed them well-formed
 * documents where neither piece of state matters.
 *
 * What separates the readings is punctuation in awkward places: a quote that
 * opens and immediately closes, a quote that is escaped, a comment that runs to
 * the end of the input, a block comment with nothing in it, and a comma inside a
 * string. Each is something a hand-edited configuration file really contains.
 */
final class JsonConfigStripperTest extends KnossosTestCase
{
    /** An empty string value does not swallow the rest of the document. */
    #[Group('discovery')]
    public function testAnEmptyStringValueClosesItself(): void
    {
        $decoded = JsonConfig::decode('{"a": "", "b": 1}', true);

        assertSame('', $decoded['a']);
        assertSame(1, $decoded['b'], 'The quote that opens the empty string must also close it.');
    }

    /** An escaped quote inside a string does not end the string. */
    #[Group('discovery')]
    public function testAnEscapedQuoteDoesNotEndTheString(): void
    {
        $decoded = JsonConfig::decode('{"note": "he said \\"hi\\"", "b": 1}', true);

        assertSame('he said "hi"', $decoded['note']);
        assertSame(1, $decoded['b']);
    }

    /**
     * A comment marker inside a string survives even when an escaped quote
     * precedes it.
     *
     * This is what makes the escape state observable at all. Mis-tracking it
     * produces identical output for ordinary text, because every character
     * inside a string is copied either way; it only shows when the scanner is
     * fooled into leaving the string early and then meets something it would
     * act on. Here it would take the rest of the line, closing quote included.
     */
    #[Group('discovery')]
    public function testACommentMarkerAfterAnEscapedQuoteStaysInsideTheString(): void
    {
        $decoded = JsonConfig::decode('{"note": "a \\" // not a comment", "b": 1}', true);

        assertSame('a " // not a comment', $decoded['note']);
        assertSame(1, $decoded['b']);
    }

    /** A comma before a brace, inside a string, survives an escaped quote too. */
    #[Group('discovery')]
    public function testACommaBeforeABraceAfterAnEscapedQuoteStaysInsideTheString(): void
    {
        $decoded = JsonConfig::decode('{"a": "x \\" ,}", "b": 1}', true);

        assertSame('x " ,}', $decoded['a'], 'The comma is data, not a trailing comma to remove.');
        assertSame(1, $decoded['b']);
    }

    /**
     * An empty string at the very start of the document closes itself.
     *
     * The scanners carry their escape state into the first string they meet, so
     * an empty string there is the one place a stale flag can swallow the
     * closing quote and leave every later comment unstripped.
     */
    #[Group('discovery')]
    public function testAnEmptyStringAtTheStartOfTheDocumentClosesItself(): void
    {
        $decoded = JsonConfig::decode("{\"\": 1, // note\n\"b\": 2}", true);

        assertSame(1, $decoded['']);
        assertSame(2, $decoded['b']);
    }

    /** The same, for the scanner that removes trailing commas. */
    #[Group('discovery')]
    public function testAnEmptyStringAtTheStartDoesNotHideATrailingComma(): void
    {
        $decoded = JsonConfig::decode('{"": [1, ], "b": 2}', true);

        assertSame([1], $decoded['']);
        assertSame(2, $decoded['b']);
    }

    /**
     * An escape does not leave the scanner stuck inside the string.
     *
     * Clearing the escape flag one character later is invisible on its own: the
     * string simply never closes, and a document needing nothing further
     * stripped comes back byte-identical. It shows when something after that
     * string does need stripping, because the scanner believes it is still
     * reading string contents and leaves a comment and a trailing comma in
     * place for the parser to reject.
     */
    #[Group('discovery')]
    public function testAnEscapeDoesNotLeaveTheScannerStuckInsideTheString(): void
    {
        $decoded = JsonConfig::decode("{\"a\": \"x\\\\ny\", // note\n\"list\": [1, ],\n}", true);

        // An escaped backslash: the value is x, a literal backslash, n, y.
        assertSame('x\\ny', $decoded['a']);
        assertSame([1], $decoded['list'], 'The comment and the trailing comma after the escape must still be removed.');
    }

    /** An escaped backslash at the end of a string still lets the string close. */
    #[Group('discovery')]
    public function testAnEscapedBackslashStillLetsTheStringClose(): void
    {
        $decoded = JsonConfig::decode('{"path": "C:\\\\", "b": 1}', true);

        assertSame('C:\\', $decoded['path']);
        assertSame(1, $decoded['b']);
    }

    /** A line comment ends at its newline and takes nothing after it. */
    #[Group('discovery')]
    public function testALineCommentEndsAtItsNewlineAndTakesNothingAfterIt(): void
    {
        $decoded = JsonConfig::decode("{\n\"a\": 1,\n//\n\"b\": 2\n}", true);

        assertSame(1, $decoded['a']);
        assertSame(2, $decoded['b'], 'An empty comment must not consume the line after it.');
    }

    /** A line comment that runs to the end of the input is still a comment. */
    #[Group('discovery')]
    public function testALineCommentAtTheVeryEndIsStillAComment(): void
    {
        $decoded = JsonConfig::decode("{\"a\": 1}\n//", true);

        assertSame(1, $decoded['a']);
    }

    /** A block comment with nothing in it is removed whole. */
    #[Group('discovery')]
    public function testABlockCommentWithNothingInItIsRemovedWhole(): void
    {
        $decoded = JsonConfig::decode('{"a": 1, /**/ "b": 2}', true);

        assertSame(1, $decoded['a']);
        assertSame(2, $decoded['b'], 'No fragment of the delimiters may survive into the JSON.');
    }

    /** A block comment spanning lines is removed, and what follows is kept. */
    #[Group('discovery')]
    public function testABlockCommentSpanningLinesIsRemoved(): void
    {
        $decoded = JsonConfig::decode("{\n\"a\": 1,\n/* first\n   second */\n\"b\": 2\n}", true);

        assertSame(1, $decoded['a']);
        assertSame(2, $decoded['b']);
    }

    /** A block comment at the very end of the input is removed. */
    #[Group('discovery')]
    public function testABlockCommentAtTheVeryEndIsRemoved(): void
    {
        $decoded = JsonConfig::decode('{"a": 1}/* trailing */', true);

        assertSame(1, $decoded['a']);
    }

    /** Comment markers inside a string are part of the string. */
    #[Group('discovery')]
    public function testCommentMarkersInsideAStringArePartOfTheString(): void
    {
        $decoded = JsonConfig::decode('{"url": "https://example.test/*x*/y"}', true);

        assertSame('https://example.test/*x*/y', $decoded['url']);
    }

    /** A comma inside a string is part of the string, not a trailing comma. */
    #[Group('discovery')]
    public function testACommaInsideAStringIsNotATrailingComma(): void
    {
        $decoded = JsonConfig::decode('{"a": "x,}", "b": "y,]"}', true);

        assertSame('x,}', $decoded['a'], 'A comma before a brace inside a string is data.');
        assertSame('y,]', $decoded['b']);
    }

    /** A trailing comma is removed before either kind of closing bracket. */
    #[Group('discovery')]
    public function testATrailingCommaIsRemovedBeforeEitherBracket(): void
    {
        $decoded = JsonConfig::decode("{\"list\": [1, 2, ],\n\"a\": 1,\n}", true);

        assertSame([1, 2], $decoded['list']);
        assertSame(1, $decoded['a']);
    }

    /** A document that stops after a comma is refused, not read past its end. */
    #[Group('discovery')]
    public function testADocumentThatStopsAfterACommaIsRefused(): void
    {
        assertThrows(static fn() => JsonConfig::decode("{\"a\": 1,   ", true), DiscoveryException::class);
        assertThrows(static fn() => JsonConfig::decode('{"a": 1', true), DiscoveryException::class);
    }

    /** Without the flag, a comment is a syntax error rather than something to strip. */
    #[Group('discovery')]
    public function testWithoutTheFlagACommentIsASyntaxError(): void
    {
        $error = captureThrows(
            static fn() => JsonConfig::decode("{\n// note\n\"a\": 1}"),
            DiscoveryException::class,
        );

        assertSame(true, str_starts_with($error->getMessage(), 'Invalid JSON configuration: '), $error->getMessage());
        assertSame(true, strlen($error->getMessage()) > strlen('Invalid JSON configuration: '), 'The decoder says what was wrong, not merely that something was.');
    }
}
