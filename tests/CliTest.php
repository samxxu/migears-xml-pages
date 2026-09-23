<?php

declare(strict_types=1);

namespace MiGears\XmlPages\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = dirname(__DIR__) . '/bin/xml-pages';
    }

    public function testHelp(): void
    {
        [$output, $code] = $this->runCli(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('compile', $output);
    }

    public function testCompileSingleFile(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/hello.page.xml';
        file_put_contents($source, '<page><body><text>Hello</text></body></page>');

        [$output, $code] = $this->runCli(['compile', $source]);
        $this->assertSame(0, $code, $output);

        $this->assertFileExists($dir . '/hello.tpl.php');
        $this->assertSame('Hello', file_get_contents($dir . '/hello.tpl.php'));
    }

    public function testCompileDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.page.xml', '<page><body><text>A</text></body></page>');
        file_put_contents($dir . '/b.page.xml', '<page><body><text>B</text></body></page>');
        file_put_contents($dir . '/ignore.txt', 'not a page');

        [$output, $code] = $this->runCli(['compile', $dir]);
        $this->assertSame(0, $code, $output);
        $this->assertFileExists($dir . '/a.tpl.php');
        $this->assertFileExists($dir . '/b.tpl.php');
        $this->assertFileDoesNotExist($dir . '/ignore.tpl.php');
    }

    public function testCompileToOutputDir(): void
    {
        $srcDir = $this->tempDir();
        $outDir = $this->tempDir();
        file_put_contents($srcDir . '/a.page.xml', '<page><body><text>A</text></body></page>');

        [$output, $code] = $this->runCli(['compile', $srcDir . '/a.page.xml', $outDir]);
        $this->assertSame(0, $code, $output);
        $this->assertFileExists($outDir . '/a.tpl.php');
        $this->assertFileDoesNotExist($srcDir . '/a.tpl.php');
    }

    public function testCheckDoesNotWrite(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/a.page.xml';
        file_put_contents($source, '<page><body><text>A</text></body></page>');

        [$output, $code] = $this->runCli(['compile', $source, '--check']);
        $this->assertSame(0, $code, $output);
        $this->assertFileDoesNotExist($dir . '/a.tpl.php');
    }

    public function testFailureExitCode(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/bad.page.xml';
        file_put_contents($source, '<page><body>');

        [$output, $code] = $this->runCli(['compile', $source]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('bad.page.xml', $output);
    }

    public function testInvalidXmlKeepsGoingForDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/good.page.xml', '<page><body><text>A</text></body></page>');
        file_put_contents($dir . '/bad.page.xml', '<page><body>');
        file_put_contents($dir . '/bad2.page.xml', '<page><body><nope/></body></page>');

        [$output, $code] = $this->runCli(['compile', $dir]);
        $this->assertSame(1, $code);
        $this->assertFileExists($dir . '/good.tpl.php');
    }

    public function testUnknownOptionIsRejectedInsteadOfBecomingTheOutputDir(): void
    {
        $dir = $this->tempDir();
        $source = $dir . '/a.page.xml';
        file_put_contents($source, '<page><body><text>A</text></body></page>');

        // A mistyped --check used to fall through to the output-directory
        // position: the intended dry run wrote into a directory named "--chck".
        [$output, $code] = $this->runCli(['compile', $source, '--chck']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown option: --chck', $output);
        $this->assertFileDoesNotExist($dir . '/a.tpl.php');
        // The stray directory would be relative to the working directory.
        $this->assertDirectoryDoesNotExist(getcwd() . '/--chck');
    }

    public function testUnknownOptionIsRejectedBeforeTheInputIsRead(): void
    {
        [$output, $code] = $this->runCli(['compile', '/nonexistent.page.xml', '--verbose']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown option: --verbose', $output);
    }

    /**
     * @param list<string> $args
     * @return array{string, int}
     */
    private function runCli(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return [implode("\n", $output), $code];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/xml-pages-' . uniqid();
        mkdir($dir, 0755, true);
        $this->assertDirectoryExists($dir);
        return $dir;
    }
}
