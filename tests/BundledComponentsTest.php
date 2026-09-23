<?php

declare(strict_types=1);

namespace MiGears\XmlPages\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Both frontend packages ship the same set of bundled components (see spec.md §10,
 * the copy note).
 *
 * "Change one place, sync the other" is a documented promise; this test turns it
 * into an executable check: it compares the two packages verbatim when they are
 * checked out as sibling directories, and is skipped when installed standalone.
 */
final class BundledComponentsTest extends TestCase
{
    private const SIBLING = '/migears-yaml-pages/components';

    public function testBundledComponentsMatchTheOtherFrontend(): void
    {
        $ownDir = dirname(__DIR__) . '/components';
        $siblingDir = dirname(__DIR__, 2) . self::SIBLING;

        if (! is_dir($siblingDir)) {
            self::markTestSkipped('migears/yaml-pages not checked out in the same repository; skipping the copy-consistency check');
        }

        $own = $this->componentNames($ownDir);
        $sibling = $this->componentNames($siblingDir);

        self::assertNotEmpty($own);
        self::assertSame($own, $sibling, 'the component lists on both sides must match');

        foreach ($own as $name) {
            self::assertSame(
                file_get_contents($siblingDir . '/' . $name),
                file_get_contents($ownDir . '/' . $name),
                "$name no longer matches the migears/yaml-pages copy — both sides must be verbatim identical"
            );
        }
    }

    /**
     * @return list<string>
     */
    private function componentNames(string $dir): array
    {
        $names = array_map('basename', glob($dir . '/*.php') ?: []);
        sort($names);

        return $names;
    }
}
