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
        $out = $this->compile('<page><body><text>Hello</text></body></page>');
        $this->assertSame('Hello', $out);
    }

    public function testTextMultiLine(): void
    {
        $out = $this->compile('<page><body><text>first line&#10;second line</text></body></page>');
        $this->assertSame("first line\nsecond line", $out);
    }

    public function testTextSingleInterpolation(): void
    {
        $out = $this->compile('<page><body><text>Hello, {{ user.name }}</text></body></page>');
        $this->assertSame('Hello, ## $user[\'name\'] ?? \'\' ##', $out);
    }

    public function testTextMultipleInterpolations(): void
    {
        $out = $this->compile('<page><body><text>{{ user.name }} ({{ user.age }})</text></body></page>');
        $this->assertSame('## $user[\'name\'] ?? \'\' ## (## $user[\'age\'] ?? \'\' ##)', $out);
    }

    public function testHeadingDefaultLevel(): void
    {
        $out = $this->compile('<page><body><heading>User management</heading></body></page>');
        $this->assertSame('<h1>User management</h1>', $out);
    }

    public function testHeadingLevel(): void
    {
        $out = $this->compile('<page><body><heading level="2">User management</heading></body></page>');
        $this->assertSame('<h2>User management</h2>', $out);
    }

    public function testHeadingLevelOutOfRange(): void
    {
        $this->expectError('<page><body><heading level="7">x</heading></body></page>', 'level');
    }

    public function testLevelAndRowsMustBeWrittenAsIntegers(): void
    {
        // Casting first turned "two" into 0, which then reported a range error
        // ("level must be an integer from 1 to 6, got 0") about a value nobody wrote.
        $this->expectError('<page><body><heading level="two">Title</heading></body></page>', 'the value "two" for level is not an integer');
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="b" label="B" input="textarea" rows="x"/></fields></form></body></page>',
            'the value "x" for rows is not an integer'
        );
        // the out-of-range case stays a range error: syntax here, range in the shared compiler
        $this->expectError('<page><body><heading level="-1">Title</heading></body></page>', 'must be an integer from 1 to 6');
    }

    public function testLevelAndRowsReadDecimalStrings(): void
    {
        $this->assertSame('<h3>Title</h3>', $this->compile('<page><body><heading level="3">Title</heading></body></page>'));
        $out = $this->compile('<page><body><form action="/s"><fields><field name="b" label="B" input="textarea" rows="6"/></fields></form></body></page>');
        $this->assertStringContainsString('rows="6"', $out);
    }

    public function testLinkWithInterpolation(): void
    {
        $out = $this->compile('<page><body><link href="/users/{{ user.id }}/edit">Edit</link></body></page>');
        $this->assertSame('<a href="/users/## $user[\'id\'] ?? \'\' ##/edit">Edit</a>', $out);
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
        $out = $this->compile('<page><body><if when="user.loggedIn"><then><text>Welcome</text></then></if></body></page>');
        $this->assertSame(
            "<?php if (\$user['loggedIn'] ?? null): ?>\nWelcome\n<?php endif ?>",
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
        $out = $this->compile('<page><body><form action="/users/save"><fields><field name="name" label="Name"/></fields></form></body></page>');
        $this->assertSame(
            "<form action=\"/users/save\" method=\"post\">\n  <label for=\"name\">Name</label>\n  <input type=\"text\" name=\"name\" id=\"name\">\n</form>",
            $out
        );
    }

    public function testFormFieldTypes(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="a" label="Password" input="password"/>'
            . '<field name="b" label="Email" input="email"/>'
            . '<field name="c" label="Quantity" input="number"/>'
            . '<field name="d" label="Hidden" input="hidden" value="user.token"/>'
            . '<field name="e" label="Save" input="submit"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString('<input type="password" name="a" id="a">', $out);
        $this->assertStringContainsString('<input type="email" name="b" id="b">', $out);
        $this->assertStringContainsString('<input type="number" name="c" id="c">', $out);
        $this->assertStringContainsString('<input type="hidden" name="d" value="## $user[\'token\'] ?? \'\' ##">', $out);
        $this->assertStringContainsString('<input type="submit" value="Save">', $out);
    }

    public function testFormFieldValueBinding(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="name" label="Name" value="user.name" required="true" placeholder="Enter"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<input type="text" name="name" id="name" value="## $user[\'name\'] ?? \'\' ##" placeholder="Enter" required>',
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
            'the value "maybe" for required is not a boolean'
        );
    }

    public function testFormTextarea(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="bio" label="About" input="textarea" rows="4" value="user.bio"/>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<textarea name="bio" id="bio" rows="4">## $user[\'bio\'] ?? \'\' ##</textarea>',
            $out
        );
    }

    public function testFormSelect(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="role" label="Role" input="select"><options>'
            . '<option value="admin">Admin</option>'
            . '<option value="user">Standard user</option>'
            . '</options></field>'
            . '</fields></form></body></page>');
        $this->assertStringContainsString(
            '<select name="role" id="role">',
            $out
        );
        $this->assertStringContainsString('<option value="admin">Admin</option>', $out);
        $this->assertStringContainsString('<option value="user">Standard user</option>', $out);
    }

    public function testFormCheckboxChecked(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="active" label="Enabled" input="checkbox" checked="user.active"/>'
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

    public function testTablePopColumns(): void
    {
        $out = $this->compile('<page><body><table items="users"><columns>'
            . '<column label="ID" pop="{{ row.id }}"/>'
            . '<column label="Name" pop="{{ row.name }}"/>'
            . '</columns></table></body></page>');
        $this->assertSame(
            "<table>\n<thead><tr><th>ID</th><th>Name</th></tr></thead>\n<tbody>\n<?php foreach (\$users ?? [] as \$row): ?>\n<tr>\n<td>## \$row['id'] ?? '' ##</td>\n<td>## \$row['name'] ?? '' ##</td>\n</tr>\n<?php endforeach ?>\n</tbody>\n</table>",
            $out
        );
    }

    public function testTableCustomAs(): void
    {
        $out = $this->compile('<page><body><table items="users" as="user"><columns><column label="ID" pop="{{ user.id }}"/></columns></table></body></page>');
        $this->assertStringContainsString('<?php foreach ($users ?? [] as $user): ?>', $out);
        $this->assertStringContainsString("## \$user['id'] ?? '' ##", $out);
    }

    public function testTableContentColumn(): void
    {
        $out = $this->compile('<page><body><table items="users"><columns>'
            . '<column label="Actions"><content><link href="/users/{{ user.id }}/edit">Edit</link></content></column>'
            . '</columns></table></body></page>');
        $this->assertStringContainsString(
            '<td><a href="/users/## $user[\'id\'] ?? \'\' ##/edit">Edit</a></td>',
            $out
        );
    }

    public function testTableEmptyText(): void
    {
        $out = $this->compile('<page><body><table items="users" empty="No data"><columns><column label="ID" pop="{{ row.id }}"/></columns></table></body></page>');
        $this->assertStringContainsString(
            "<?php if ((\$users ?? []) === []): ?>\n<tr><td colspan=\"1\">No data</td></tr>\n<?php else: ?>",
            $out
        );
        $this->assertStringContainsString('<?php endif ?>', $out);
    }

    public function testTableColumnPopAndContentConflict(): void
    {
        $this->expectError(
            '<page><body><table items="users"><columns><column label="ID" pop="{{ row.id }}"><content><text>x</text></content></column></columns></table></body></page>',
            'pop'
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
        $out = $this->compile('<page layout="layout/admin"><sections><section name="content"><text>Content</text></section></sections></page>');
        $this->assertSame(
            "<?php \$this->extends('layout/admin') ?>\n\n<?php \$this->start('content') ?>\nContent\n<?php \$this->end() ?>\n",
            $out
        );
    }

    public function testLayoutWithTitleSection(): void
    {
        $out = $this->compile('<page title="User management" layout="layout/admin"><sections><section name="content"><text>Content</text></section></sections></page>');
        $this->assertStringContainsString(
            "<?php \$this->start('title') ?>\nUser management\n<?php \$this->end() ?>",
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
        $compiler->compileSource('<page title="ignored"><body><text>A</text></body></page>');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('title', $warnings[0]);
    }

    public function testComponentLiteralData(): void
    {
        $out = $this->compile('<page><body><component name="card"><data><title>Title</title><body>About</body></data></component></body></page>');
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => 'Title',\n    'body' => 'About',\n]) ?>",
            $out
        );
    }

    public function testComponentInterpolatedData(): void
    {
        $out = $this->compile('<page><body><component name="card"><data><title>{{ user.name }}</title><body>Edit {{ user.name }} info</body></data></component></body></page>');
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => (\$user['name'] ?? ''),\n    'body' => 'Edit ' . (\$user['name'] ?? '') . ' info',\n]) ?>",
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
        $this->expectError('<html/>', 'root element');
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
        $this->expectError('<page><body><text>{{ user.name + 1 }}</text></body></page>', 'invalid path');
    }

    public function testInvalidPath(): void
    {
        $this->expectError('<page><body><each items="1users"><body/></each></body></page>', 'invalid path');
    }

    public function testXmlSyntaxError(): void
    {
        $this->expectError('<page><body>', 'XML syntax error');
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
            'invalid path'
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
            'type must be "field"'
        );
    }

    public function testColumnTypeOptionalButMustMatch(): void
    {
        $out = $this->compile(
            '<page><body><table items="users"><columns><column type="column" label="ID" pop="{{ row.id }}"/></columns></table></body></page>'
        );
        $this->assertStringContainsString('<th>ID</th>', $out);

        $this->expectError(
            '<page><body><table items="users"><columns><column type="field" label="ID" pop="{{ row.id }}"/></columns></table></body></page>',
            'type must be "column"'
        );
    }

    public function testFieldLabelRejectsInterpolation(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="{{ user.name }}"/></fields></form></body></page>',
            'does not support {{ }} interpolation'
        );
    }

    public function testTableEmptyRejectsInterpolation(): void
    {
        $this->expectError(
            '<page><body><table items="users" empty="{{ user.name }}"><columns><column label="ID" pop="{{ row.id }}"/></columns></table></body></page>',
            'does not support {{ }} interpolation'
        );
    }

    /* ---------------------------------------------------------------- *
     * Framework front-end compatibility: attribute passthrough / <el> / <attr>
     * ---------------------------------------------------------------- */

    public function testPassthroughAlpineOnEl(): void
    {
        $out = $this->compile('<page><body><el tag="div" x-data="{ open: false }" x-on:click="open = ! open" x-cloak="" x-show="open"><text>Toggle</text></el></body></page>');
        $this->assertSame(
            "<div x-data=\"{ open: false }\" x-on:click=\"open = ! open\" x-cloak=\"\" x-show=\"open\">\nToggle\n</div>",
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
                '<h2' . $expected . '>Title</h2>',
                $this->compile('<page><body><heading level="2" ' . $attr . '>Title</heading></body></page>'),
                "attribute {$attr} did not forward as expected"
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
            . '<link href="/x" x-on:click="go()">Go</link>'
            . '<form action="/s" x-on:submit.prevent="save()"><fields>'
            . '<field name="q" label="Query" x-model="kw"/>'
            . '</fields></form>'
            . '<table items="users" class="grid"><columns>'
            . '<column label="ID" pop="{{ row.id }}" class="w-8"/>'
            . '</columns></table>'
            . '</body></page>');

        $this->assertStringContainsString('<a href="/x" x-on:click="go()">Go</a>', $out);
        $this->assertStringContainsString('<form action="/s" method="post" x-on:submit.prevent="save()">', $out);
        $this->assertStringContainsString('name="q" id="q" x-model="kw"', $out);
        $this->assertStringContainsString('<table class="grid">', $out);
        $this->assertStringContainsString('<td class="w-8">', $out);
    }

    public function testUnknownAttributeRejected(): void
    {
        $this->expectError('<page><body><heading level="2" levl="3">T</heading></body></page>', 'unknown attribute "levl"');
    }

    public function testPassthroughOnTaglessNodeRejected(): void
    {
        $this->expectError('<page><body><text x-data="{ open: true }">hi</text></body></page>', 'emits no tag');
    }

    public function testPassthroughOnIfRejected(): void
    {
        $this->expectError('<page><body><if when="a" class="x"><then><text>t</text></then></if></body></page>', 'emits no tag');
    }

    public function testUnknownPageAttributeRejected(): void
    {
        $this->expectError('<page title="T" layuot="layout/main"><body><text>x</text></body></page>', 'unknown attribute "layuot"');
    }

    public function testUnexpectedPageChildRejected(): void
    {
        $this->expectError('<page><boddy><text>x</text></boddy></page>', 'disallowed child element');
    }

    public function testStrayChildInLeafRejected(): void
    {
        $this->expectError('<page><body><heading level="2">Te<strong>xt</strong></heading></body></page>', 'disallowed child element');
    }

    public function testContainerStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><table items="users"><columns><colum label="ID" pop="{{ row.id }}"/></columns></table></body></page>',
            'disallowed child element <colum>'
        );
    }

    public function testElNode(): void
    {
        $out = $this->compile('<page><body><el tag="section" class="card"><heading level="2">Title</heading><text>Content</text></el></body></page>');
        $this->assertSame("<section class=\"card\">\n<h2>Title</h2>\nContent\n</section>", $out);
    }

    public function testElEmptyBody(): void
    {
        $out = $this->compile('<page><body><el tag="div" x-ref="anchor"></el></body></page>');
        $this->assertSame('<div x-ref="anchor"></div>', $out);
    }

    public function testElMissingTag(): void
    {
        $this->expectError('<page><body><el class="x"><text>t</text></el></body></page>', 'missing string field "tag"');
    }

    public function testElInvalidTag(): void
    {
        $this->expectError('<page><body><el tag="DIV!"><text>t</text></el></body></page>', 'invalid tag');
    }

    public function testAttrNodeCoversAtShorthand(): void
    {
        $out = $this->compile('<page><body><el tag="button" class="btn"><attr name="@click" value="open = true"/><attr name=":class" value="open &amp;&amp; \'on\'"/><text>Toggle</text></el></body></page>');
        $this->assertSame(
            "<button class=\"btn\" @click=\"open = true\" :class=\"open &amp;&amp; 'on'\">\nToggle\n</button>",
            $out
        );
    }

    public function testAttrMissingValue(): void
    {
        $this->expectError('<page><body><el tag="div"><attr name="@click"/><text>t</text></el></body></page>', 'missing its value attribute');
    }

    public function testAttrMissingName(): void
    {
        $this->expectError('<page><body><el tag="div"><attr value="x"/><text>t</text></el></body></page>', 'missing its name attribute');
    }

    public function testAttrDuplicateRejected(): void
    {
        $this->expectError('<page><body><el tag="div" class="a"><attr name="class" value="b"/><text>t</text></el></body></page>', 'duplicates an existing attribute');
    }

    public function testAttrOnTaglessNodeRejected(): void
    {
        $this->expectError('<page><body><text><attr name="@click" value="x"/>hi</text></body></page>', 'emits no tag');
    }

    public function testAttrInContainerRejected(): void
    {
        $this->expectError('<page><body><attr name="@click" value="x"/><text>hi</text></body></page>', '<attr> may only be');
    }

    public function testDunderIsAtShorthand(): void
    {
        $out = $this->compile('<page><body><el tag="button" __click="open = ! open" __keydown.escape.window="close()"><text>Toggle</text></el></body></page>');
        $this->assertSame(
            "<button @click=\"open = ! open\" @keydown.escape.window=\"close()\">\nToggle\n</button>",
            $out
        );
    }

    public function testDunderOnTaglessNodeRejected(): void
    {
        $this->expectError('<page><body><text __click="x">hi</text></body></page>', 'emits no tag');
    }

    public function testDunderDuplicateWithAttrRejected(): void
    {
        $this->expectError(
            '<page><body><el tag="div" __click="a"><attr name="@click" value="b"/><text>t</text></el></body></page>',
            'duplicates an existing attribute'
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
            '<page><body><link href="/x"><attr name="href" value="/y"/>Go</link></body></page>',
            'collides with the node field "href"'
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
                $this->fail("{$attr} should have failed to compile");
            } catch (CompileException $e) {
                $this->assertStringContainsString($suggested, $e->getMessage());
            }
        }
    }

    public function testHyphenEventAlsoSuggestsDunder(): void
    {
        try {
            $this->compile('<page><body><el tag="div" x-on-click="go()"><text>t</text></el></body></page>');
            $this->fail('should have failed to compile');
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
            'disallowed child element <els>'
        );
    }

    public function testEachStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><each items="u"><body><text>T</text></body><extra><text>X</text></extra></each></body></page>',
            'disallowed child element <extra>'
        );
    }

    public function testComponentStrayChildRejected(): void
    {
        $this->expectError(
            '<page><body><component name="card"><data><title>T</title></data><foo/></component></body></page>',
            'disallowed child element <foo>'
        );
    }

    public function testSectionsStrayChildRejected(): void
    {
        $this->expectError(
            '<page layout="layout/main"><sections><sectoin name="content"><text>Content</text></sectoin></sections></page>',
            'disallowed child element <sectoin>'
        );
    }

    public function testTripleBraceRejected(): void
    {
        foreach (['{{{ user.name }}}', '{{ user.name }}}', '{{{ user.name }}'] as $text) {
            $this->expectError('<page><body><text>' . $text . '</text></body></page>', 'three braces');
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
            $this->fail('should have failed to compile');
        } catch (CompileException $e) {
            $this->assertStringContainsString('error parsing attribute name', $e->getMessage());
            $this->assertStringContainsString('__click', $e->getMessage());
        }
    }

    public function testAtSignHintOnlyFiresForAttributePosition(): void
    {
        // an email address in text must not trigger the hint: '@' is not preceded by whitespace
        try {
            $this->compile('<page><body><text>contact a@b.com</text></body>');
            $this->fail('should have failed to compile');
        } catch (CompileException $e) {
            $this->assertStringContainsString('XML syntax error', $e->getMessage());
            $this->assertStringNotContainsString('__click', $e->getMessage());
        }
    }

    public function testBareTextInContainersRejected(): void
    {
        $cases = [
            '<page><body><el tag="div">bare text</el></body></page>' => 'body[0].body',
            '<page><body><if when="a"><then>bare text</then></if></body></page>' => 'then',
            '<page><body><if when="a"><then><text>T</text></then><else>bare text</else></if></body></page>' => 'else',
            '<page><body><each items="u"><body>bare text</body></each></body></page>' => 'body',
            '<page><body><table items="u"><columns><column label="A"><content>bare text</content></column></columns></table></body></page>' => 'content',
            '<page layout="layout/main"><sections><section name="content">bare text</section></sections></page>' => 'sections.content',
            '<page><body>bare text</body></page>' => 'body',
            // CDATA cannot reach the node model either
            '<page><body><el tag="div"><![CDATA[<b>bold</b>]]></el></body></page>' => 'body[0].body',
            '<page><body><table items="u"><columns>bare text<column label="A" bind="b"/></columns></table></body></page>' => 'columns',
            '<page><body><form action="/s"><fields>bare text<field name="a" label="A"/></fields></form></body></page>' => 'fields',
            '<page><body><component name="card"><data>bare text</data></component></body></page>' => 'data',
        ];
        foreach ($cases as $xml => $needle) {
            try {
                $this->compile($xml);
                $this->fail("{$xml} should have failed to compile");
            } catch (CompileException $e) {
                $this->assertStringContainsString('cannot write text', $e->getMessage(), $xml);
                $this->assertStringContainsString($needle, $e->getMessage(), $xml);
            }
        }
    }

    public function testIndentationAndLeafTextAreNotBareText(): void
    {
        $out = $this->compile("<page><body>\n  <el tag=\"div\">\n    <heading level=\"2\">Title</heading>\n    <text>Content</text>\n  </el>\n</body></page>");
        $this->assertSame("<div>\n<h2>Title</h2>\nContent\n</div>", $out);
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
                $this->fail("{$xml} should have failed to compile");
            } catch (CompileException $e) {
                $this->assertStringContainsString('disallowed child element <foo>', $e->getMessage(), $xml);
            }
        }
    }

    public function testOptionWithChildElementRejected(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="select">'
            . '<options><option value="x"><b>X</b></option></options></field></fields></form></body></page>',
            'only accepts text content'
        );
    }

    public function testAttrWithChildContentRejected(): void
    {
        $this->expectError(
            '<page><body><el tag="div"><attr name="@click" value="go()">extra</attr></el></body></page>',
            'cannot carry child content'
        );
    }

    public function testErrorPathsUseNumericIndexes(): void
    {
        // Iterating a SimpleXML repetition hands over the element name as the
        // key, so a foreach key builds "fields[field]" — not the index form every
        // path in this project uses.
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" required="maybe"/></fields></form></body></page>',
            'body[0].fields[0]: '
        );
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="a" label="A" input="select">'
            . '<options><option>X</option></options></field></fields></form></body></page>',
            'body[0].fields[0].options[0]: '
        );
        $this->expectError(
            '<page><body><table items="u"><columns><column label="A"/></columns></table></body></page>',
            'body[0].columns[0]: '
        );
        $this->expectError(
            '<page layout="layout/main"><sections><section><text>x</text></section></sections></page>',
            'sections[0]: '
        );
    }

    public function testTwoAttrsWithTheSameNameAreRejected(): void
    {
        $this->expectError(
            '<page><body><el tag="div">'
            . '<attr name="class" value="a"/><attr name="class" value="b"/>'
            . '</el></body></page>',
            'body[0]: <attr name="class"> is defined more than once'
        );
    }

    public function testFieldScopeIsEnforcedForStringSourcesToo(): void
    {
        // Attributes arrive as strings here, but the scope of a field does not
        // depend on the frontend that spelled it.
        $this->expectError(
            '<page><body><form action="/s"><fields>'
            . '<field name="a" label="A" input="select" placeholder="p">'
            . '<options><option value="x">X</option></options></field>'
            . '</fields></form></body></page>',
            '"placeholder" is only for the text / password / email / number fields; the input here is "select"'
        );
    }

    public function testRequiredReachesTextareaAndCheckbox(): void
    {
        $out = $this->compile('<page><body><form action="/s"><fields>'
            . '<field name="b" label="B" input="textarea" required="true"/>'
            . '<field name="c" label="C" input="checkbox" required="true"/>'
            . '</fields></form></body></page>');

        $this->assertStringContainsString('<textarea name="b" id="b" rows="4" required>', $out);
        $this->assertStringContainsString('<input type="checkbox" name="c" id="c" required>', $out);
    }

    public function testOptionTextRejectsInterpolation(): void
    {
        $this->expectError(
            '<page><body><form action="/s"><fields><field name="s" label="S" input="select">'
            . '<options><option value="a">{{ a }}</option></options></field></fields></form></body></page>',
            'does not support {{ }} interpolation'
        );
    }

    public function testEmptyPathSegmentRejected(): void
    {
        $this->expectError(
            '<page><body><link href="/u/{{ user..name }}">Edit</link></body></page>',
            'invalid path "user..name"'
        );
    }

    public function testTemplateMarkerInTextIsEscaped(): void
    {
        // Text goes through the shared interpolation helper, which escapes the template
        // marker, so the hashes are rendered as written instead of being evaluated.
        self::assertStringContainsString(
            '\##',
            $this->compile('<page><body><text>## a note ##</text></body></page>')
        );
    }

    public function testSingleHashStaysLiteral(): void
    {
        self::assertStringContainsString(
            '# Heading 1',
            $this->compile('<page><body><text># Heading 1</text></body></page>')
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
