<?php

declare(strict_types=1);

namespace MiGears\XmlPages\Tests;

use MiGears\Pages\Compiler as PagesCompiler;
use MiGears\Pages\Exception\CompileException as PagesCompileException;
use MiGears\XmlPages\Compiler;
use MiGears\YamlPages\Compiler as SiblingCompiler;
use PHPUnit\Framework\TestCase;

/**
 * The two front ends are peers over one shared compiler and one contract, and
 * nothing compared them: each package's own tests assert its own syntax, so a
 * rule that fires in one spelling and not the other had no detector at all.
 * The third-round report called that out as the root cause of a divergence it
 * filed against migears/yaml-pages — the XML side refused a duplicate element
 * while the YAML side said nothing — and it is the class of bug this file
 * exists to catch.
 *
 * The corpus is the contract: every `*.page.xml` under tests/fixtures/pages has
 * a `*.page.yaml` at the same path in migears/yaml-pages, and the two must
 * compile to byte-identical artefacts; every pair under tests/fixtures/errors
 * must be refused with the same message. Both halves are read as fixtures, so a
 * subject a front end can express differently — XML's `<attr>` children against
 * YAML's quoted keys, `__click` against `"@click"`, a section name that needs
 * trimming — is compared rather than assumed.
 *
 * The error corpus holds rules of the shared layer only. A source the *parser*
 * rejects is each front end's own wording, so those stay in each package's own
 * tests.
 *
 * Two differences between the two syntaxes are deliberate, and no pair can
 * express them — they are written down here so the corpus is not read as a claim
 * that the two accept exactly the same sources:
 *
 * 1. How a value's type is decided. An XML attribute is a string, so the shared
 *    boolean parser reads it with HTML semantics: `required="1"`, `"yes"`,
 *    `"on"`, `""` and `"required"` are all true. YAML hands the compiler native
 *    scalars, so `required: 1` is an integer where a boolean is due and
 *    `rows: '4'` a string where a positive integer is due, and both are refused.
 *    Each side is right for its own syntax, and each package's own suite pins
 *    its own reading; making them agree would mean breaking one of the two.
 * 2. Duplicate keys. A repeated element is refused here — the duplicate section,
 *    option and data errors are in this package's own tests — while a repeated
 *    mapping key is invisible to the other half, because libyaml merges it
 *    before PHP reads the document. That package documents the merge and pins
 *    it; a pair would have to assert a *difference*, which is what this note is
 *    for instead.
 *
 * The sibling package is not a composer requirement of this one and cannot be
 * (the two are peers), so it is only usable when it is checked out beside this
 * package. That is the case in the monorepo and in CI; on a standalone install
 * this file skips, and CI's --fail-on-skipped turns that skip into a failure.
 */
final class FrontEndParityTest extends TestCase
{
    /** Relative to the directory holding this package, where the sibling lives. */
    private const SIBLING = '/migears-yaml-pages';

    private const OWN_EXT = '.page.xml';

    private const SIBLING_EXT = '.page.yaml';

    private static bool $siblingAutoloaderRegistered = false;

    protected function setUp(): void
    {
        if (! is_dir(self::siblingDir())) {
            self::markTestSkipped(
                'migears/yaml-pages is not checked out beside this package; skipping the two-front-end comparison'
            );
        }

        self::registerSiblingAutoloader();
    }

    public function testEveryPairedPageCompilesToTheSameArtefact(): void
    {
        $names = self::pairNames('/pages');

        self::assertNotEmpty($names, 'tests/fixtures/pages holds no pair, so this check would compare nothing');

        foreach ($names as $name) {
            $xml = $this->outcome('own', '/pages', $name);
            $yaml = $this->outcome('sibling', '/pages', $name);

            self::assertTrue($xml['ok'], self::label('own', '/pages', $name) . ' should compile, but: ' . $xml['text']);
            self::assertTrue($yaml['ok'], self::label('sibling', '/pages', $name) . ' should compile, but: ' . $yaml['text']);
            self::assertSame(
                $xml['text'],
                $yaml['text'],
                'the same page compiled to different artefacts in the two syntaxes: '
                . self::label('own', '/pages', $name) . ' and ' . self::label('sibling', '/pages', $name)
            );
        }
    }

    public function testEveryPairedMistakeIsRefusedWithTheSameMessage(): void
    {
        $names = self::pairNames('/errors');

        self::assertNotEmpty($names, 'tests/fixtures/errors holds no pair, so this check would compare nothing');

        foreach ($names as $name) {
            $xml = $this->outcome('own', '/errors', $name);
            $yaml = $this->outcome('sibling', '/errors', $name);

            self::assertFalse($xml['ok'], self::label('own', '/errors', $name) . ' should be refused, but it compiled');
            self::assertFalse($yaml['ok'], self::label('sibling', '/errors', $name) . ' should be refused, but it compiled');
            self::assertSame(
                $xml['text'],
                $yaml['text'],
                'the same mistake was refused with different messages: '
                . self::label('own', '/errors', $name) . ' against ' . self::label('sibling', '/errors', $name)
            );
        }
    }

    /**
     * Compile one half of a pair and report what came back — the artefact, or
     * the message it was refused with. Both halves go through here, so the
     * own/sibling difference is written once.
     *
     * @param 'own'|'sibling' $side
     * @param '/pages'|'/errors' $dir
     *
     * @return array{ok: bool, text: string}
     */
    private function outcome(string $side, string $dir, string $name): array
    {
        $ext = $side === 'own' ? self::OWN_EXT : self::SIBLING_EXT;

        try {
            return ['ok' => true, 'text' => self::compilerFor($side)->compileSource(self::fixture($side, $dir, $name, $ext))];
        } catch (PagesCompileException $e) {
            return ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    /**
     * @param 'own'|'sibling' $side
     */
    private static function compilerFor(string $side): PagesCompiler
    {
        // Both front ends extend the shared base, which is the only relationship
        // between them: neither is a kind of the other.
        return $side === 'own' ? new Compiler() : new SiblingCompiler();
    }

    /**
     * Every pair name in one corpus directory. Read from this package's half:
     * the sibling's half is checked when it is read, so a pair that exists on
     * one side only fails here rather than going unnoticed.
     *
     * @param '/pages'|'/errors' $dir
     *
     * @return list<string>
     */
    private static function pairNames(string $dir): array
    {
        $files = glob(__DIR__ . '/fixtures' . $dir . '/*' . self::OWN_EXT);
        if ($files === false) {
            return [];
        }
        sort($files);

        return array_map(static fn (string $file): string => basename($file, self::OWN_EXT), $files);
    }

    /**
     * @param 'own'|'sibling' $side
     * @param '/pages'|'/errors' $dir
     */
    private static function fixture(string $side, string $dir, string $name, string $ext): string
    {
        $root = $side === 'own' ? __DIR__ . '/fixtures' : self::siblingDir() . '/tests/fixtures';
        $path = $root . $dir . '/' . $name . $ext;

        // A pair is added to both packages at once; a missing half means the
        // corpus is broken, not that the comparison does not apply.
        self::assertFileExists($path, 'a pair is written into both packages together; this half is missing');

        return (string) file_get_contents($path);
    }

    /**
     * @param 'own'|'sibling' $side
     * @param '/pages'|'/errors' $dir
     */
    private static function label(string $side, string $dir, string $name): string
    {
        $package = $side === 'own' ? 'this package' : 'migears/yaml-pages';
        $ext = $side === 'own' ? self::OWN_EXT : self::SIBLING_EXT;

        return "{$package} tests/fixtures{$dir}/{$name}{$ext}";
    }

    private static function siblingDir(): string
    {
        return dirname(__DIR__, 2) . self::SIBLING;
    }

    /**
     * The neighbour's classes are not in this package's autoloader: migears/pages
     * is a composer requirement, this package is not — it is a peer, and a peer
     * cannot be one. So its PSR-4 root is registered here, once.
     */
    private static function registerSiblingAutoloader(): void
    {
        if (self::$siblingAutoloaderRegistered) {
            return;
        }
        self::$siblingAutoloaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            $prefix = 'MiGears\\YamlPages\\';
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $file = self::siblingDir() . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
