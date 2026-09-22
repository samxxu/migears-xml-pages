<?php

declare(strict_types=1);

namespace MiGears\XmlPages\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 两个前端包各自分发同一套内置组件（见 spec.md §10 复制说明）。
 *
 * 「改一处必须同步另一处」是文档承诺，本测试把它变成可执行的检查：
 * 在两个包作为同级目录检出时逐字比对，独立安装时跳过。
 */
final class BundledComponentsTest extends TestCase
{
    private const SIBLING = '/migears-yaml-pages/components';

    public function testBundledComponentsMatchTheOtherFrontend(): void
    {
        $ownDir = dirname(__DIR__) . '/components';
        $siblingDir = dirname(__DIR__, 2) . self::SIBLING;

        if (! is_dir($siblingDir)) {
            self::markTestSkipped('未在同一仓库中检出 migears/yaml-pages，跳过副本一致性检查');
        }

        $own = $this->componentNames($ownDir);
        $sibling = $this->componentNames($siblingDir);

        self::assertNotEmpty($own);
        self::assertSame($own, $sibling, '两侧的内置组件清单必须一致');

        foreach ($own as $name) {
            self::assertSame(
                file_get_contents($siblingDir . '/' . $name),
                file_get_contents($ownDir . '/' . $name),
                "$name 与 migears/yaml-pages 的副本已不一致——两侧必须逐字相同"
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
