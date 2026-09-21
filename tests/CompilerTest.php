<?php

declare(strict_types=1);

namespace MiGears\XmlPages\Tests;

use MiGears\XmlPages\Compiler;
use MiGears\XmlPages\Exception\CompileException;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testTextPlain(): void
    {
        $out = $this->compile('<page><body><text>你好</text></body></page>');
        $this->assertSame('你好', $out);
    }

    public function testTextMultiLine(): void
    {
        $out = $this->compile('<page><body><text>第一行&#10;第二行</text></body></page>');
        $this->assertSame("第一行\n第二行", $out);
    }

    public function testTextSingleInterpolation(): void
    {
        $out = $this->compile('<page><body><text>你好，{{ user.name }}</text></body></page>');
        $this->assertSame('你好，## $user[\'name\'] ?? \'\' ##', $out);
    }

    public function testTextMultipleInterpolations(): void
    {
        $out = $this->compile('<page><body><text>{{ user.name }}（{{ user.age }}）</text></body></page>');
        $this->assertSame('## $user[\'name\'] ?? \'\' ##（## $user[\'age\'] ?? \'\' ##）', $out);
    }

    public function testHeadingDefaultLevel(): void
    {
        $out = $this->compile('<page><body><heading>用户管理</heading></body></page>');
        $this->assertSame('<h1>用户管理</h1>', $out);
    }

    public function testHeadingLevel(): void
    {
        $out = $this->compile('<page><body><heading level="2">用户管理</heading></body></page>');
        $this->assertSame('<h2>用户管理</h2>', $out);
    }

    public function testHeadingLevelOutOfRange(): void
    {
        $this->expectError('<page><body><heading level="7">x</heading></body></page>', 'level');
    }

    public function testLinkWithInterpolation(): void
    {
        $out = $this->compile('<page><body><link href="/users/{{ user.id }}/edit">编辑</link></body></page>');
        $this->assertSame('<a href="/users/## $user[\'id\'] ?? \'\' ##/edit">编辑</a>', $out);
    }

    public function testLinkTarget(): void
    {
        $out = $this->compile('<page><body><link href="/x" target="_blank">x</link></body></page>');
        $this->assertSame('<a href="/x" target="_blank">x</a>', $out);
    }

    public function testLinkMissingHref(): void
    {
        $this->expectError('<page><body><link>x</link></body></page>', 'href');
    }

    public function testIfThen(): void
    {
        $out = $this->compile('<page><body><if when="user.loggedIn"><then><text>欢迎</text></then></if></body></page>');
        $this->assertSame(
            "<?php if (\$user['loggedIn'] ?? null): ?>\n欢迎\n<?php endif ?>",
            $out
        );
    }

    public function testIfThenElse(): void
    {
        $out = $this->compile('<page><body><if when="user.loggedIn"><then><text>A</text></then><else><text>B</text></else></if></body></page>');
        $this->assertSame(
            "<?php if (\$user['loggedIn'] ?? null): ?>\nA\n<?php else: ?>\nB\n<?php endif ?>",
            $out
        );
    }

    public function testIfNegation(): void
    {
        $out = $this->compile('<page><body><if when="!user.hidden"><then><text>A</text></then></if></body></page>');
        $this->assertStringContainsString(
            "<?php if (!(\$user['hidden'] ?? null)): ?>",
            $out
        );
    }

    public function testIfMissingWhen(): void
    {
        $this->expectError('<page><body><if><then><text>A</text></then></if></body></page>', 'when');
    }

    public function testEachBasic(): void
    {
        $out = $this->compile('<page><body><each items="users"><body><text>{{ item.name }}</text></body></each></body></page>');
        $this->assertSame(
            "<?php foreach (\$users ?? [] as \$item): ?>\n## \$item['name'] ?? '' ##\n<?php endforeach ?>",
            $out
        );
    }

    public function testEachWithAsAndIndex(): void
    {
        $out = $this->compile('<page><body><each items="users" as="user" index="i"><body><text>{{ i }} {{ user.name }}</text></body></each></body></page>');
        $this->assertStringContainsString(
            "<?php foreach (\$users ?? [] as \$i => \$user): ?>",
            $out
        );
    }

    public function testEachNested(): void
    {
        $out = $this->compile('<page><body><each items="groups" as="group"><body><each items="group.users" as="user"><body><text>{{ user.name }}</text></body></each></body></each></body></page>');
        $this->assertStringContainsString(
            "<?php foreach (\$groups ?? [] as \$group): ?>\n<?php foreach (\$group['users'] ?? [] as \$user): ?>",
            $out
        );
    }

    public function testEachMissingItems(): void
    {
        $this->expectError('<page><body><each><body/></each></body></page>', 'items');
    }

    public function testFormBasic(): void
    {
        $out = $this->compile('<page><body><form action="/users/save"><fields><field name="name" label="姓名"/></fields></form></body></page>');
        $this->assertSame(
            "<form action=\"/users/save\" method=\"post\">\n  <label for=\"name\">姓名</label>\n  <input type=\"text\" name=\"name\" id=\"name\">\n</form>",
            $out
        );
    }

    public function testFormFieldTypes(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="a" label="密码" input="password"/>'
            . '<field name="b" label="邮箱" input="email"/>'
            . '<field name="c" label="数量" input="number"/>'
            . '<field name="d" label="隐藏" input="hidden" value="user.token"/>'
            . '<field name="e" label="保存" input="submit"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString('<input type="password" name="a" id="a">', $out);
        $this->assertStringContainsString('<input type="email" name="b" id="b">', $out);
        $this->assertStringContainsString('<input type="number" name="c" id="c">', $out);
        $this->assertStringContainsString('<input type="hidden" name="d" value="## $user[\'token\'] ?? \'\' ##">', $out);
        $this->assertStringContainsString('<input type="submit" value="保存">', $out);
    }

    public function testFormFieldValueBinding(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="name" label="姓名" value="user.name" required="true" placeholder="请输入"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<input type="text" name="name" id="name" value="## $user[\'name\'] ?? \'\' ##" placeholder="请输入" required>',
            $out
        );
    }

    public function testRequiredReadsHtmlBooleanSpellings(): void
    {
        $compile = fn (string $attr): string => $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="a" label="A" ' . $attr . '/>'
            . '</fields></form></body></page>');

        // word lists, case-insensitive
        foreach (['required="true"', 'required="TRUE"', 'required="yes"', 'required="on"', 'required="1"'] as $attr) {
            self::assertStringContainsString(' required>', $compile($attr), $attr);
        }
        // HTML reads a boolean attribute as true when it is present but empty,
        // and when its value repeats its own name
        foreach (['required=""', 'required="required"'] as $attr) {
            self::assertStringContainsString(' required>', $compile($attr), $attr);
        }

        foreach (['required="false"', 'required="FALSE"', 'required="no"', 'required="off"', 'required="0"'] as $attr) {
            self::assertStringNotContainsString(' required>', $compile($attr), $attr);
        }
    }

    public function testRequiredRejectsUnknownBooleanSpelling(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" required="maybe"/></fields></form></body></page>',
            'required 的值 "maybe" 不是布尔'
        );
    }

    public function testFormTextarea(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="bio" label="简介" input="textarea" rows="4" value="user.bio"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<textarea name="bio" id="bio" rows="4">## $user[\'bio\'] ?? \'\' ##</textarea>',
            $out
        );
    }

    public function testFormSelect(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="role" label="角色" input="select"><options>'
            . '<option value="admin">管理员</option>'
            . '<option value="user">普通用户</option>'
            . '</options></field>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<select name="role" id="role">',
            $out
        );
        $this->assertStringContainsString('<option value="admin">管理员</option>', $out);
        $this->assertStringContainsString('<option value="user">普通用户</option>', $out);
    }

    public function testFormCheckboxChecked(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="active" label="启用" input="checkbox" checked="user.active"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<input type="checkbox" name="active" id="active"<?= ($user[\'active\'] ?? null) ? \' checked\' : \'\' ?>>',
            $out
        );
    }

    public function testFormInvalidInput(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="color"/></fields></form></body></page>',
            'input'
        );
    }

    public function testFormSelectMissingOptions(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="select"/></fields></form></body></page>',
            'options'
        );
    }

    public function testFormOptionsOnUnsupportedInput(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A"><options><option value="x">y</option></options></field></fields></form></body></page>',
            'options'
        );
    }

    public function testFormSelectWithValueRejected(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="select" value="user.role"><options><option value="x">y</option></options></field></fields></form></body></page>',
            'value'
        );
    }

    public function testOptionMissingValue(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="select"><options><option>x</option></options></field></fields></form></body></page>',
            'value'
        );
    }

    public function testTableBindColumns(): void
    {
        $out = $this->compile('<page><body><table items="users"><columns>'
            . '<column label="ID" bind="id"/>'
            . '<column label="姓名" bind="name"/>'
            . '</columns></table></body></page>');
        $this->assertSame(
            "<table>\n<thead><tr><th>ID</th><th>姓名</th></tr></thead>\n<tbody>\n<?php foreach (\$users ?? [] as \$row): ?>\n<tr>\n<td>## \$row['id'] ?? '' ##</td>\n<td>## \$row['name'] ?? '' ##</td>\n</tr>\n<?php endforeach ?>\n</tbody>\n</table>",
            $out
        );
    }

    public function testTableCustomAs(): void
    {
        $out = $this->compile('<page><body><table items="users" as="user"><columns><column label="ID" bind="id"/></columns></table></body></page>');
        $this->assertStringContainsString('<?php foreach ($users ?? [] as $user): ?>', $out);
        $this->assertStringContainsString("## \$user['id'] ?? '' ##", $out);
    }

    public function testTableContentColumn(): void
    {
        $out = $this->compile('<page><body><table items="users"><columns>'
            . '<column label="操作"><content><link href="/users/{{ user.id }}/edit">编辑</link></content></column>'
            . '</columns></table></body></page>');
        $this->assertStringContainsString(
            '<td><a href="/users/## $user[\'id\'] ?? \'\' ##/edit">编辑</a></td>',
            $out
        );
    }

    public function testTableEmptyText(): void
    {
        $out = $this->compile('<page><body><table items="users" empty="暂无数据"><columns><column label="ID" bind="id"/></columns></table></body></page>');
        $this->assertStringContainsString(
            "<?php if ((\$users ?? []) === []): ?>\n<tr><td colspan=\"1\">暂无数据</td></tr>\n<?php else: ?>",
            $out
        );
        $this->assertStringContainsString('<?php endif ?>', $out);
    }

    public function testTableColumnBindAndContentConflict(): void
    {
        $this->expectError(
            '<page><body><table items="users"><columns><column label="ID" bind="id"><content><text>x</text></content></column></columns></table></body></page>',
            'bind'
        );
    }

    public function testTableMissingColumns(): void
    {
        $this->expectError('<page><body><table items="users"/></body></page>', 'columns');
    }

    public function testBodyStandalone(): void
    {
        $out = $this->compile('<page><body><text>A</text><text>B</text></body></page>');
        $this->assertSame("A\nB", $out);
    }

    public function testLayoutWithSections(): void
    {
        $out = $this->compile('<page layout="layout/admin"><sections><section name="content"><text>主体</text></section></sections></page>');
        $this->assertSame(
            "<?php \$this->extends('layout/admin') ?>\n\n<?php \$this->start('content') ?>\n主体\n<?php \$this->end() ?>\n",
            $out
        );
    }

    public function testLayoutWithTitleSection(): void
    {
        $out = $this->compile('<page title="用户管理" layout="layout/admin"><sections><section name="content"><text>主体</text></section></sections></page>');
        $this->assertStringContainsString(
            "<?php \$this->start('title') ?>\n用户管理\n<?php \$this->end() ?>",
            $out
        );
    }

    public function testLayoutAndBodyConflict(): void
    {
        $this->expectError(
            '<page layout="layout/admin"><body><text>x</text></body><sections><section name="content"/></sections></page>',
            'body'
        );
    }

    public function testLayoutWithoutSections(): void
    {
        $this->expectError('<page layout="layout/admin"/>', 'sections');
    }

    public function testNoLayoutNoBody(): void
    {
        $this->expectError('<page/>', 'body');
    }

    public function testTitleWithoutLayoutWarns(): void
    {
        $warnings = [];
        $compiler = new Compiler(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $compiler->compileSource('<page title="忽略"><body><text>A</text></body></page>');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('title', $warnings[0]);
    }

    public function testComponentLiteralData(): void
    {
        $out = $this->compile('<page><body><component name="card"><data><title>标题</title><body>简介</body></data></component></body></page>');
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => '标题',\n    'body' => '简介',\n]) ?>",
            $out
        );
    }

    public function testComponentInterpolatedData(): void
    {
        $out = $this->compile('<page><body><component name="card"><data><title>{{ user.name }}</title><body>编辑 {{ user.name }} 的信息</body></data></component></body></page>');
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => (\$user['name'] ?? ''),\n    'body' => '编辑 ' . (\$user['name'] ?? '') . ' 的信息',\n]) ?>",
            $out
        );
    }

    public function testComponentNoData(): void
    {
        $out = $this->compile('<page><body><component name="badge"/></body></page>');
        $this->assertSame("<?= \$this->component('badge') ?>", $out);
    }

    public function testUnknownNodeType(): void
    {
        $this->expectError('<page><body><marquee>x</marquee></body></page>', 'marquee');
    }

    public function testNonPageRoot(): void
    {
        $this->expectError('<html/>', '根元素');
    }

    public function testSectionMissingName(): void
    {
        $this->expectError(
            '<page layout="layout/admin"><sections><section><text>A</text></section></sections></page>',
            'name'
        );
    }

    public function testInvalidInterpolation(): void
    {
        $this->expectError('<page><body><text>{{ user.name + 1 }}</text></body></page>', '非法路径');
    }

    public function testInvalidPath(): void
    {
        $this->expectError('<page><body><each items="1users"><body/></each></body></page>', '路径');
    }

    public function testXmlSyntaxError(): void
    {
        $this->expectError('<page><body>', 'XML');
    }

    public function testErrorCarriesNodePath(): void
    {
        try {
            $this->compile('<page layout="layout/admin"><sections><section name="content"><text>A</text><wat>x</wat></section></sections></page>');
            $this->fail('Expected CompileException');
        } catch (CompileException $e) {
            $this->assertStringContainsString('sections.content[1]', $e->getMessage());
        }
    }

    public function testEachItemsRejectsNegation(): void
    {
        $this->expectError(
            '<page><body><each items="!users"><body><text>x</text></body></each></body></page>',
            '非法路径'
        );
    }

    public function testFieldTypeOptionalButMustMatch(): void
    {
        $out = $this->compile(
            '<page><body><form action="/s"><fields><field type="field" name="a" label="A"/></fields></form></body></page>'
        );
        $this->assertStringContainsString('<input type="text" name="a" id="a">', $out);

        $this->expectError(
            '<page><body><form action="/s"><fields><field type="column" name="a" label="A"/></fields></form></body></page>',
            'type 必须是 "field"'
        );
    }

    public function testColumnTypeOptionalButMustMatch(): void
    {
        $out = $this->compile(
            '<page><body><table items="users"><columns><column type="column" label="ID" bind="id"/></columns></table></body></page>'
        );
        $this->assertStringContainsString('<th>ID</th>', $out);

        $this->expectError(
            '<page><body><table items="users"><columns><column type="field" label="ID" bind="id"/></columns></table></body></page>',
            'type 必须是 "column"'
        );
    }

    public function testFieldLabelRejectsInterpolation(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="{{ user.name }}"/></fields></form></body></page>',
            '不支持 {{ }} 插值'
        );
    }

    public function testTableEmptyRejectsInterpolation(): void
    {
        $this->expectError(
            '<page><body><table items="users" empty="{{ user.name }}"><columns><column label="ID" bind="id"/></columns></table></body></page>',
            '不支持 {{ }} 插值'
        );
    }

    /* ---------------------------------------------------------------- *
     * 前端框架兼容：属性透传 / <el> / <attr>
     * ---------------------------------------------------------------- */

    public function testPassthroughAlpineOnEl(): void
    {
        $out = $this->compile('<page><body><el tag="div" x-data="{ open: false }" x-on:click="open = ! open" x-cloak="" x-show="open"><text>切换</text></el></body></page>');
        $this->assertSame(
            "<div x-data=\"{ open: false }\" x-on:click=\"open = ! open\" x-cloak=\"\" x-show=\"open\">\n切换\n</div>",
            $out
        );
    }

    public function testPassthroughPrefixesAndHtmlHooks(): void
    {
        $cases = [
            'hx-get="/x"' => ' hx-get="/x"',
            'hx-trigger="click"' => ' hx-trigger="click"',
            'wire:click="save"' => ' wire:click="save"',
            'v-on:click="go"' => ' v-on:click="go"',
            'data-controller="menu"' => ' data-controller="menu"',
            ':href="url"' => ' :href="url"',
            'class="box"' => ' class="box"',
            'id="main"' => ' id="main"',
            'style="color: red"' => ' style="color: red"',
        ];
        foreach ($cases as $attr => $expected) {
            $this->assertSame(
                '<h2' . $expected . '>标题</h2>',
                $this->compile('<page><body><heading level="2" ' . $attr . '>标题</heading></body></page>'),
                "属性 {$attr} 未按预期透传"
            );
        }
    }

    public function testPassthroughValueIsEscaped(): void
    {
        $out = $this->compile('<page><body><heading level="2" x-data="{ a: 1 &amp;&amp; b: 2 }">T</heading></body></page>');
        $this->assertSame('<h2 x-data="{ a: 1 &amp;&amp; b: 2 }">T</h2>', $out);
    }

    public function testPassthroughKeepsSingleQuotesReadable(): void
    {
        $out = $this->compile('<page><body><heading level="2" x-on:click="alert(\'hi\')">T</heading></body></page>');
        $this->assertSame('<h2 x-on:click="alert(\'hi\')">T</h2>', $out);
    }

    public function testPassthroughValueSupportsInterpolation(): void
    {
        $out = $this->compile('<page><body><el tag="div" x-data="{ name: \'{{ user.name }}\' }"><text>hi</text></el></body></page>');
        $this->assertStringContainsString('x-data="{ name: ', $out);
        $this->assertStringContainsString("## \$user['name'] ?? '' ##", $out);
    }

    public function testPassthroughOnLinkFormTableFieldColumn(): void
    {
        $out = $this->compile('<page><body>'
            . '<link href="/x" x-on:click="go()">去</link>'
            . '<form action="/s" x-on:submit.prevent="save()"><fields>'
            . '<field name="q" label="查" x-model="kw"/>'
            . '</fields></form>'
            . '<table items="users" class="grid"><columns>'
            . '<column label="ID" bind="id" class="w-8"/>'
            . '</columns></table>'
            . '</body></page>');

        $this->assertStringContainsString('<a href="/x" x-on:click="go()">去</a>', $out);
        $this->assertStringContainsString('<form action="/s" method="post" x-on:submit.prevent="save()">', $out);
        $this->assertStringContainsString('name="q" id="q" x-model="kw"', $out);
        $this->assertStringContainsString('<table class="grid">', $out);
        $this->assertStringContainsString('<td class="w-8">', $out);
    }

    public function testUnknownAttributeRejected(): void
    {
        $this->expectError('<page><body><heading level="2" levl="3">T</heading></body></page>', '未知属性 "levl"');
    }

    public function testPassthroughOnTaglessNodeRejected(): void
    {
        $this->expectError('<page><body><text x-data="{ open: true }">hi</text></body></page>', '不输出标签');
    }

    public function testPassthroughOnIfRejected(): void
    {
        $this->expectError('<page><body><if when="a" class="x"><then><text>t</text></then></if></body></page>', '不输出标签');
    }

    public function testUnknownPageAttributeRejected(): void
    {
        $this->expectError('<page title="T" layuot="layout/main"><body><text>x</text></body></page>', '未知属性 "layuot"');
    }

    public function testUnexpectedPageChildRejected(): void
    {
        $this->expectError('<page><boddy><text>x</text></boddy></page>', '不允许的子元素');
    }

    public function testStrayChildInLeafRejected(): void
    {
        $this->expectError('<page><body><heading level="2">标<strong>题</strong></heading></body></page>', '不允许的子元素');
    }

    public function testContainerStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><table items="users"><columns><colum label="ID" bind="id"/></columns></table></body></page>',
            '不允许的子元素 <colum>'
        );
    }

    public function testElNode(): void
    {
        $out = $this->compile('<page><body><el tag="section" class="card"><heading level="2">标题</heading><text>正文</text></el></body></page>');
        $this->assertSame("<section class=\"card\">\n<h2>标题</h2>\n正文\n</section>", $out);
    }

    public function testElEmptyBody(): void
    {
        $out = $this->compile('<page><body><el tag="div" x-ref="anchor"></el></body></page>');
        $this->assertSame('<div x-ref="anchor"></div>', $out);
    }

    public function testElMissingTag(): void
    {
        $this->expectError('<page><body><el class="x"><text>t</text></el></body></page>', '缺少 string 字段 "tag"');
    }

    public function testElInvalidTag(): void
    {
        $this->expectError('<page><body><el tag="DIV!"><text>t</text></el></body></page>', '非法的 tag');
    }

    public function testAttrNodeCoversAtShorthand(): void
    {
        $out = $this->compile('<page><body><el tag="button" class="btn"><attr name="@click" value="open = true"/><attr name=":class" value="open &amp;&amp; \'on\'"/><text>切换</text></el></body></page>');
        $this->assertSame(
            "<button class=\"btn\" @click=\"open = true\" :class=\"open &amp;&amp; 'on'\">\n切换\n</button>",
            $out
        );
    }

    public function testAttrMissingValue(): void
    {
        $this->expectError('<page><body><el tag="div"><attr name="@click"/><text>t</text></el></body></page>', '缺少 value 属性');
    }

    public function testAttrMissingName(): void
    {
        $this->expectError('<page><body><el tag="div"><attr value="x"/><text>t</text></el></body></page>', '缺少 name 属性');
    }

    public function testAttrDuplicateRejected(): void
    {
        $this->expectError('<page><body><el tag="div" class="a"><attr name="class" value="b"/><text>t</text></el></body></page>', '重复');
    }

    public function testAttrOnTaglessNodeRejected(): void
    {
        $this->expectError('<page><body><text><attr name="@click" value="x"/>hi</text></body></page>', '不输出标签');
    }

    public function testAttrInContainerRejected(): void
    {
        $this->expectError('<page><body><attr name="@click" value="x"/><text>hi</text></body></page>', '<attr> 只能作为');
    }

    public function testDunderIsAtShorthand(): void
    {
        $out = $this->compile('<page><body><el tag="button" __click="open = ! open" __keydown.escape.window="close()"><text>切换</text></el></body></page>');
        $this->assertSame(
            "<button @click=\"open = ! open\" @keydown.escape.window=\"close()\">\n切换\n</button>",
            $out
        );
    }

    public function testDunderOnTaglessNodeRejected(): void
    {
        $this->expectError('<page><body><text __click="x">hi</text></body></page>', '不输出标签');
    }

    public function testDunderDuplicateWithAttrRejected(): void
    {
        $this->expectError(
            '<page><body><el tag="div" __click="a"><attr name="@click" value="b"/><text>t</text></el></body></page>',
            '重复'
        );
    }

    public function testAttrNameIsEmittedLiterallyNotMapped(): void
    {
        $out = $this->compile('<page><body><el tag="div"><attr name="@click" value="go()"/><attr name="__raw" value="x"/><text>t</text></el></body></page>');
        $this->assertSame("<div @click=\"go()\" __raw=\"x\">\nt\n</div>", $out);
    }

    public function testAttrCannotShadowDslField(): void
    {
        $this->expectError(
            '<page><body><link href="/x"><attr name="href" value="/y"/>去</link></body></page>',
            '与节点字段 "href" 同名'
        );
    }

    public function testHyphenFormOfColonDirectiveRejected(): void
    {
        $cases = [
            'x-on-click="go()"' => 'x-on:click',
            'x-bind-href="url"' => 'x-bind:href',
            'x-transition-enter="fade"' => 'x-transition:enter',
        ];
        foreach ($cases as $attr => $suggested) {
            try {
                $this->compile('<page><body><el tag="div" ' . $attr . '><text>t</text></el></body></page>');
                $this->fail("{$attr} 应当编译失败");
            } catch (CompileException $e) {
                $this->assertStringContainsString($suggested, $e->getMessage());
            }
        }
    }

    public function testHyphenEventAlsoSuggestsDunder(): void
    {
        try {
            $this->compile('<page><body><el tag="div" x-on-click="go()"><text>t</text></el></body></page>');
            $this->fail('应当编译失败');
        } catch (CompileException $e) {
            $this->assertStringContainsString('__click', $e->getMessage());
        }
    }

    public function testColonlessXDirectivesStillForward(): void
    {
        $out = $this->compile('<page><body><el tag="div" x-show="open" x-data="{ n: 1 }"><text>t</text></el></body></page>');
        $this->assertStringContainsString('x-show="open"', $out);
        $this->assertStringContainsString('x-data="{ n: 1 }"', $out);
    }

    public function testIfStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><if when="a"><then><text>T</text></then><els><text>E</text></els></if></body></page>',
            '不允许的子元素 <els>'
        );
    }

    public function testEachStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><each items="u"><body><text>T</text></body><extra><text>X</text></extra></each></body></page>',
            '不允许的子元素 <extra>'
        );
    }

    public function testComponentStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><component name="card"><data><title>T</title></data><foo/></component></body></page>',
            '不允许的子元素 <foo>'
        );
    }

    public function testSectionsStrayChildRejected(): void
    {
        $this->expectError(
            '<page layout="layout/main"><sections><sectoin name="content"><text>正文</text></sectoin></sections></page>',
            '不允许的子元素 <sectoin>'
        );
    }

    public function testTripleBraceRejected(): void
    {
        foreach (['{{{ user.name }}}', '{{ user.name }}}', '{{{ user.name }}'] as $text) {
            $this->expectError('<page><body><text>' . $text . '</text></body></page>', '三个花括号');
        }
    }

    public function testAdjacentInterpolationsStillAllowed(): void
    {
        $out = $this->compile('<page><body><text>{{ a }}{{ b }}</text></body></page>');
        $this->assertSame("## \$a ?? '' #### \$b ?? '' ##", $out);
    }

    public function testAtSignParseErrorCarriesFixHint(): void
    {
        try {
            $this->compile('<page><body><text @click="open = true">hi</text></body></page>');
            $this->fail('应当编译失败');
        } catch (CompileException $e) {
            $this->assertStringContainsString('error parsing attribute name', $e->getMessage());
            $this->assertStringContainsString('__click', $e->getMessage());
        }
    }

    public function testAtSignHintOnlyFiresForAttributePosition(): void
    {
        // 文本里的邮箱不该触发提示：@ 前面不是空白
        try {
            $this->compile('<page><body><text>联系 a@b.com</text></body>');
            $this->fail('应当编译失败');
        } catch (CompileException $e) {
            $this->assertStringContainsString('XML 语法错误', $e->getMessage());
            $this->assertStringNotContainsString('__click', $e->getMessage());
        }
    }

    public function testBareTextInContainersRejected(): void
    {
        $cases = [
            '<page><body><el tag="div">裸文本</el></body></page>' => 'body[0].body',
            '<page><body><if when="a"><then>裸文本</then></if></body></page>' => 'then',
            '<page><body><if when="a"><then><text>T</text></then><else>裸文本</else></if></body></page>' => 'else',
            '<page><body><each items="u"><body>裸文本</body></each></body></page>' => 'body',
            '<page><body><table items="u"><columns><column label="A"><content>裸文本</content></column></columns></table></body></page>' => 'content',
            '<page layout="layout/main"><sections><section name="content">裸文本</section></sections></page>' => 'sections.content',
            '<page><body>裸文本</body></page>' => 'body',
            // CDATA 同样够不到节点模型
            '<page><body><el tag="div"><![CDATA[<b>粗</b>]]></el></body></page>' => 'body[0].body',
            '<page><body><table items="u"><columns>裸文本<column label="A" bind="b"/></columns></table></body></page>' => 'columns',
            '<page><body><form action="/s"><fields>裸文本<field name="a" label="A"/></fields></form></body></page>' => 'fields',
            '<page><body><component name="card"><data>裸文本</data></component></body></page>' => 'data',
        ];
        foreach ($cases as $xml => $needle) {
            try {
                $this->compile($xml);
                $this->fail("{$xml} 应当编译失败");
            } catch (CompileException $e) {
                $this->assertStringContainsString('不能直接写文本', $e->getMessage(), $xml);
                $this->assertStringContainsString($needle, $e->getMessage(), $xml);
            }
        }
    }

    public function testIndentationAndLeafTextAreNotBareText(): void
    {
        $out = $this->compile("<page><body>\n  <el tag=\"div\">\n    <heading level=\"2\">标题</heading>\n    <text>正文</text>\n  </el>\n</body></page>");
        $this->assertSame("<div>\n<h2>标题</h2>\n正文\n</div>", $out);
    }

    public function testStrayChildRejectedEverywhere(): void
    {
        $cases = [
            '<page><body><form action="/s"><fields><field name="a" label="A"/></fields><foo/></form></body></page>',
            '<page><body><table items="u"><columns><column label="A" bind="b"/></columns><foo/></table></body></page>',
            '<page><body><form action="/s"><fields><field name="a" label="A"><foo/></field></fields></form></body></page>',
            '<page><body><table items="u"><columns><column label="A" bind="b"><foo/></column></columns></table></body></page>',
        ];
        foreach ($cases as $xml) {
            try {
                $this->compile($xml);
                $this->fail("{$xml} 应当编译失败");
            } catch (CompileException $e) {
                $this->assertStringContainsString('不允许的子元素 <foo>', $e->getMessage(), $xml);
            }
        }
    }

    public function testOptionWithChildElementRejected(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="select">'
            . '<options><option value="x"><b>X</b></option></options></field></fields></form></body></page>',
            '只接受文本内容'
        );
    }

    public function testAttrWithChildContentRejected(): void
    {
        $this->expectError(
            '<page><body><el tag="div"><attr name="@click" value="go()">多余</attr></el></body></page>',
            '不能带子内容'
        );
    }

    private function compile(string $xml): string
    {
        return $this->compiler->compileSource($xml);
    }

    private function expectError(string $xml, string $needle): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($needle);
        $this->compile($xml);
    }
}
