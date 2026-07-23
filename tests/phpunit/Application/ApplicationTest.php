<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Application;

use Knossos\Application;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('application')]
final class ApplicationTest extends TestCase
{
    // ----- shape -----

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(Application::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function testVersionConstantIsExposedAsPublicString(): void
    {
        $reflection = new \ReflectionClass(Application::class);
        $constant = $reflection->getConstant('VERSION');

        $this->assertIsString($constant);
        // version.txt and the x-release-please-version sentinel in
        // src/Application.php are bumped together by release-please; pinning a
        // literal here failed every release build, so assert consistency with
        // the managed file instead.
        $this->assertSame(trim((string) file_get_contents(dirname(__DIR__, 3) . '/version.txt')), $constant);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $constant);
    }

    public function testRunIsAPublicInstanceMethodReturningInteger(): void
    {
        $reflection = new \ReflectionMethod(Application::class, 'run');

        $this->assertTrue($reflection->isPublic());
        $this->assertFalse($reflection->isStatic());

        $app = new Application();
        $return = $app->run(['help']);

        $this->assertIsInt($return);
    }

    // ----- meta-command: help -----
    //
    // The actual help-banner rendering is tested separately by the
    // CliHelpRenderer and MetaCommand unit tests. Here we verify only
    // that Application.run() delegates the 5 meta-command entrypoints
    // without throwing and returns the agreed exit code 0.

    public function testRunWithNoArgumentsDefaultsToHelpCommandAndReturnsZero(): void
    {
        // Covers the `array_shift($arguments) ?? 'help'` branch.
        assertSame(0, (new Application())->run([]));
    }

    public function testRunWithHelpCommandReturnsZero(): void
    {
        assertSame(0, (new Application())->run(['help']));
    }

    public function testRunWithDoubleDashHelpReturnsZero(): void
    {
        assertSame(0, (new Application())->run(['--help']));
    }

    public function testRunWithShortDashHReturnsZero(): void
    {
        assertSame(0, (new Application())->run(['-h']));
    }

    // ----- meta-command: version -----

    public function testRunWithVersionCommandReturnsZero(): void
    {
        assertSame(0, (new Application())->run(['version']));
    }

    public function testRunWithDoubleDashVersionReturnsZero(): void
    {
        assertSame(0, (new Application())->run(['--version']));
    }

    public function testRunWithVersionAndJsonFlagReturnsZero(): void
    {
        // The --json flag switches CliCommandContext::output to JSON;
        // exercised here only as a return-code contract because PHPUnit
        // 12's expectOutput* does not capture fwrite(STDOUT, …).
        assertSame(0, (new Application())->run(['version', '--json']));
    }

    // ----- error paths -----
    //
    // CliErrorRenderer.render() writes the diagnostic code to STDERR via
    // fwrite(STDERR, …) — which is not captured by PHPUnit's output
    // expectations and not redirectable on PHP's predefined STDERR
    // constant. The renderer's own tests cover the STDERR contract; here
    // we verify only that Application's try/catch returns the agreed
    // exit code 2 on any throwable path.

    public function testRunWithUnknownCommandReturnsTwo(): void
    {
        // CliCommandRouter::route() throws InvalidArgumentException when
        // no registered command supports() the name; Application catches
        // it and hands it to CliErrorRenderer::render() which returns 2.
        assertSame(2, (new Application())->run(['this-command-does-not-exist']));
    }

    public function testRunCatchesInvalidEmptyOptionFromParserAndReturnsTwo(): void
    {
        // '--=value' has an empty option name, so CliOptionParser::parse()
        // raises InvalidArgumentException('Invalid empty option.') — caught by
        // Application, rendered by CliErrorRenderer, returns 2. (A lone '--' is
        // now the end-of-options marker and no longer throws.)
        assertSame(2, (new Application())->run(['help', '--=value']));
    }
}