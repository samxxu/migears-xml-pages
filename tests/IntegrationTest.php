<?php

declare(strict_types=1);

namespace MiGears\XmlPages\Tests;

use MiGears\Template\Template;
use MiGears\XmlPages\Compiler;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    public function testFullPipelineRendersPage(): void
    {
        $views = $this->tempDir();
        mkdir($views . '/layout');
        copy(__DIR__ . '/fixtures/views/layout/main.php', $views . '/layout/main.php');

        $compiled = (new Compiler())->compileFile(__DIR__ . '/fixtures/pages/users.page.xml');
        file_put_contents($views . '/users.tpl.php', $compiled);

        $cache = $this->tempDir();
        $tpl = new Template($views, $cache);

        $html = $tpl->render('users', [
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => '<Bob>'],
            ],
        ]);

        $this->assertStringContainsString('<title>用户管理', $html);
        $this->assertStringContainsString('</title>', $html);
        $this->assertStringContainsString('<h2>用户列表</h2>', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
        $this->assertStringContainsString('<td>Alice</td>', $html);
        $this->assertStringContainsString('&lt;Bob&gt;', $html);
        $this->assertStringContainsString('<a href="/users/1/edit">编辑</a>', $html);
        $this->assertStringNotContainsString('##', $html);
    }

    public function testFullPipelineRendersEmptyTable(): void
    {
        $views = $this->tempDir();
        mkdir($views . '/layout');
        copy(__DIR__ . '/fixtures/views/layout/main.php', $views . '/layout/main.php');

        $compiled = (new Compiler())->compileFile(__DIR__ . '/fixtures/pages/users.page.xml');
        file_put_contents($views . '/users.tpl.php', $compiled);

        $tpl = new Template($views, $this->tempDir());
        $html = $tpl->render('users', ['users' => []]);

        $this->assertStringContainsString('暂无数据', $html);
    }

    public function testComponentDataIsEscapedExactlyOnce(): void
    {
        $views = $this->tempDir();
        $compiled = (new Compiler())->compileSource(
            '<page><body><component name="badge"><data><text>{{ user.name }}</text></data></component></body></page>'
        );
        file_put_contents($views . '/badge-demo.tpl.php', $compiled);

        $tpl = new Template($views, $this->tempDir());
        $tpl->addPath(dirname(__DIR__) . '/components');

        $html = $tpl->render('badge-demo', ['user' => ['name' => '<b>Bob</b>']]);

        $this->assertStringContainsString('&lt;b&gt;Bob&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/xml-pages-int-' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }
}
