# migears/xml-pages

![Version](https://img.shields.io/badge/version-2.0.0-blue)

A declarative XML page definition tool that compiles `.page.xml` declarations into miGears Template files (`.tpl.php`), which the template engine then compiles to pure PHP on first render. The XML declaration is the single source of truth; generated templates are derived artifacts and must not be hand-edited.

Sister package of `migears/yaml-pages`: the same declarative DSL expressed in XML instead of YAML. Identical node model and compiled output — both frontends parse into the array DSL of `migears/pages`, which owns the shared compiler. Only the parsing layer differs; pick whichever format you prefer.

## Features

- PHP 8.1+, PSR-4 autoloading, namespace `MiGears\XmlPages`
- **Zero third-party dependencies** — XML parsing via built-in SimpleXML (libxml)
- Declares page structure, data binding, conditionals (`if`), loops (`each`), form fields, table columns and layout inheritance
- `{{ path }}` interpolation with auto-escaping — XSS protection inherited from the template engine
- Compile-time validation of structure, fields, paths and attributes — nothing is silently dropped
- **Attribute passthrough** for front-end frameworks: `x-on:click`, `v-bind:href`, `wire:click`, `hx-get`, `data-*`, `class`/`id`/`style` are forwarded verbatim to the emitted tag
- Generic `<el tag="...">` container, so wrapper attributes (Alpine's `x-data`) have somewhere to live
- Built-in components (`card`, `button`, `alert`, `badge`) plus custom components written per miGears Template conventions
- Deliberately out of scope: business logic, event handling, state management, routing, runtime XML parsing — those belong to the front-end framework you pair it with

## How It Works

**Two deliberate compilations:**

1. xml-pages parses the XML declaration into the array DSL of `migears/pages`; the shared compiler there turns it into `.tpl.php` sugar syntax (`## $expr ##`). The intermediate output stays readable, so each DSL keyword maps visibly to template syntax.
2. `migears/template`'s `TemplateCompiler` turns that sugar into a pure PHP template (mtime-cached, recompiled only when the template changes). Rendering is plain PHP: the template runs and its variables are output to the browser as HTML. The declaration layer never enters runtime.

The generated `.tpl.php` file is a derived artifact — re-running the compiler overwrites it. Edit the XML, never the output.

## Installation

```bash
composer require migears/xml-pages
```

Requires PHP 8.1+ and `migears/template` ^2.0. No XML extension to install — SimpleXML ships with PHP.

Make the built-in components findable by the template engine:

```php
use MiGears\Template\Template;

$tpl = new Template(__DIR__ . '/views');
$tpl->addPath('vendor/migears/xml-pages/components');
```

Or copy `components/` into your project's template directory.

## Quick Start

Write a page declaration `views/pages/users.page.xml`:

```xml
<page title="用户管理" layout="layout/main">
    <sections>
        <section name="title">
            <text>用户管理</text>
        </section>
        <section name="content">
            <heading level="2">用户列表</heading>
            <table items="users" as="user" empty="暂无数据">
                <columns>
                    <column label="ID" bind="id"/>
                    <column label="姓名" bind="name"/>
                    <column label="操作">
                        <content>
                            <link href="/users/{{ user.id }}/edit">编辑</link>
                        </content>
                    </column>
                </columns>
            </table>
        </section>
    </sections>
</page>
```

Compile it:

```bash
php vendor/bin/xml-pages compile views/pages/users.page.xml
```

This produces `views/pages/users.tpl.php`. Render it like any other template:

```php
echo $tpl->render('pages/users', [
    'users' => [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ],
]);
```

A complete example covering every syntax feature ships in `examples/full-featured.page.xml`, with a minimal layout in `examples/views/layout/main.php`. Compile it in place and it renders straight away:

```bash
php bin/xml-pages compile examples/full-featured.page.xml examples/views
```

## XML Notes

The mapping rules:

- **Element name = node type**: `<text>`, `<heading>`, `<link>`, `<if>`, `<each>`, `<form>`, `<table>`, `<el>`, `<component>`.
- **Fields go in attributes**: `<heading level="2">`, `<link href="..." target="_blank">`, `<if when="...">`.
- **Text content goes in the element text**: the element text of `<text>` / `<heading>` / `<link>` is the `text` field; leading/trailing whitespace is trimmed.
- **Container children**: `<if>` → `<then>` / `<else>`; `<each>` → `<body>`; `<form>` → `<fields>` (`<field>`); `<table>` → `<columns>` (`<column>`); `<field>` → `<options>` (`<option value="...">`); `<column>` → `<content>`; `<component>` → `<data>` (child element names are the data keys).

Escaping and special characters:

| Case | Write |
|------|-------|
| `<`, `>`, `&` in text | entities `&lt;` `&gt;` `&amp;` |
| Newline | literal newline, or entity `&#10;` |
| Multi-line text | write it inline; outer whitespace trimmed, inner whitespace preserved |
| Raw HTML output | `<text><![CDATA[<strong>bold</strong>]]></text>` |
| Valueless attribute (`x-cloak`) | write `x-cloak=""` — XML forbids an attribute with no value |

Also:

- `{{ path }}` needs **no escaping in XML** — `{` / `}` are not XML-special. A plus over YAML.
- `required="true"` parses as boolean; `"true"` / `"1"` / `"yes"` / `"on"` (case-insensitive) are true.
- `level` and `rows` parse as integers.
- Don't nest child elements inside leaf nodes (`text` / `heading` / `link`) — child tags are lost, only concatenated text remains; use CDATA for HTML.
- Unknown attributes, unknown child elements and misspelled container children (`<colum>`) are **compile errors**, never silently ignored; a misspelled node type fails too. See *Front-end Framework Integration* and *Errors*.
- Containers accept **child elements only**. Text or CDATA written directly inside one is unreachable from the node model, so it is a compile error rather than a silent drop — wrap it in `<text>` (use `<text><![CDATA[...]]></text>` for raw HTML). Indentation whitespace is ignored.

## Front-end Framework Integration

XML is stricter than HTML about attribute **names**: `@` is not a legal NameStartChar, and `:` is reserved for namespaces. Nodes therefore handle attributes in three ways:

1. **DSL fields** — consumed by the node itself (`heading.level`, `link.href`, `form.action`).
2. **Forwarded attributes** — emitted on the tag the node produces:
   - `__event` — the readable spelling of `@event` (below)
   - any name containing a colon: `x-on:click`, `x-bind:href`, `v-on:click`, `wire:click`, `on:click`, `:href`
   - prefixes `x-`, `v-`, `hx-`, `data-`
   - the HTML hooks `class`, `id`, `style`
3. **Everything else is a compile error** — treated as a typo, never dropped silently.

Nodes that emit no tag of their own (`text`, `if`, `each`, `component`) reject forwarded attributes; wrap them in `<el>` instead. Forwarded values are HTML-escaped first and interpolated second, so `{{ }}` still works inside them (and single quotes stay readable instead of turning into `&#039;`).

### `__event` is `@event`

XML attribute names cannot contain `@`, so `@click` cannot be written at all. A name starting with `__` is mapped **positionally** — `__` becomes `@`, the rest is copied verbatim:

| Write | Compiles to |
|-------|-------------|
| `__click="open = ! open"` | `@click="open = ! open"` |
| `__keydown.escape.window="close()"` | `@keydown.escape.window="close()"` |

Positional means no splitting guesswork — which is exactly why `x-on-click` is **not** accepted: `x-on-keydown-enter` could mean `x-on:keydown-enter` or `x-on-keydown:enter`. So `x-on-click`, `x-bind-href` and `x-transition-enter` fail with a suggestion, rather than being forwarded into a directive Alpine silently ignores.

Because `@` is shorthand for `x-on:`, `__event` and `x-on:event` are equivalent; the `:` forms and `wire:` / `hx-` need no compensation. Using `__` claims that prefix: a project's own `__xxx` attribute has to go through `<attr>`.

For anything else — including a literal `__xxx` name — use an `<attr>` child: the name travels as a *value*, and `<attr>` names are emitted verbatim with no `__` mapping:

```xml
<el tag="div" x-data="{ open: false }" class="panel">
    <attr name="@click" value="open = ! open"/>
    <attr name=":class" value="open &amp;&amp; 'on'"/>
    <text>Toggle</text>
</el>
```

| Constraint | Rule |
|------------|------|
| Placement | child of a node that emits a tag: `el` / `heading` / `link` / `form` / `table` / `field` / `column` |
| `name` | required, any legal HTML attribute name (including `@`); emitted verbatim, no `__` mapping |
| `value` | required, supports `{{ }}` interpolation |
| Duplicate | colliding with an existing attribute of the same name is a compile error |

Prefer the standard spelling (`x-on:click`) when it works; `<attr>` is only for names XML cannot express.

### Framework matrix

| Framework | Attribute style | Result |
|-----------|-----------------|--------|
| Alpine | `x-on:click`, `x-bind:href`, `x-data`, `x-show`, `x-cloak=""` | works |
| Alpine | `__click`, `__keydown.escape.window` | works (same as `@click`) |
| Alpine | `@click` | fails — `@` is not a legal XML name. Use `__click`, `x-on:click` or `<attr>`; `:href` works |
| Alpine | `x-on-click` (hyphen) | rejected — Alpine only has the colon form |
| Vue | `v-on:click`, `v-bind:href`, `:href` | works |
| Vue | `__click` | works (same as `@click`) |
| Vue | `@click` | fails — use `__click`, `v-on:click` or `<attr>` |
| htmx | `hx-get`, `hx-trigger` | works |
| Stimulus | `data-controller`, `data-action` | works |
| Livewire | `wire:click`, `wire:model.live` | works |

### One owner per region

Server-side and client-side rendering must not both own the same DOM region. Render structure on the server with `each` / `if` / `table`, then hang interaction on top with Alpine — do not also drive that list with `x-for`, or Alpine regenerates it from its own template and you get duplicated nodes plus flicker.

## Data Binding

`{{ path }}` interpolates a dot path into an auto-escaped output:

| Path | Compiles to |
|------|-------------|
| `{{ users }}` | `## $users ?? '' ##` |
| `{{ user.name }}` | `## $user['name'] ?? '' ##` |
| `{{ form.errors.email }}` | `## $form['errors']['email'] ?? '' ##` |

Rules:

- Only `a.b.c` paths — no function calls, no arithmetic, no string literals. Anything else is a compile error.
- Paths compile to **array access**; normalize Domain entities to arrays at the controller boundary.
- Only `if.when` takes a leading `!` for negation; a `!` anywhere else (e.g. `each.items`) is a compile error.
- Conditions and loops fall back with `?? null`, bound text and attributes with `?? ''`.
- At most two braces per interpolation: `{{{` or `}}}` is a compile error. A third brace slips past the pairing check and would leave stray braces in the rendered output.
- Literal fields — `layout`, section names, `form.method`, `field.name`, `field.label`, `<option>` value and text, `empty`, `column.label`, `component.name` — are emitted as-is. Writing `{{ }}` there is a compile error, not a silent no-op.

## Node Reference

Every element in `body` / `sections` is a node; its tag name is the type. Available types: `text`, `heading`, `link`, `if`, `each`, `form`, `table`, `component`. Nested structures (`<field>`, `<column>`) are typed by their tag name: a `type` attribute is unnecessary, and must match (`field` / `column`) when written or compilation fails.

### Page root

| Field | Form | Required | Meaning |
|-------|------|----------|---------|
| `title` | `<page>` attribute | no | Page title; becomes the `title` section (only with `layout`) |
| `layout` | `<page>` attribute | no | Layout template name, e.g. `layout/main` |
| `body` | `<page>` child | conditional | Node tree when there is no `layout` |
| `sections` | `<page>` child | conditional | `<section name="...">` elements, required together with `layout` |

`layout` + `sections` and `body` are mutually exclusive. Every `<section>` needs a `name` attribute.

### text / heading / link

```xml
<text>你好，{{ user.name }}</text>
<heading level="2">用户管理</heading>
<link href="/users/{{ user.id }}/edit">编辑</link>
```

- `text` — element text is the text; literal output, interpolations auto-escaped
- `heading` — `level` 1–6 (default 1)
- `link` — `href` required, `target` optional

### if

| Field | Required | Meaning |
|-------|----------|---------|
| `when` | yes | Path, optional `!` prefix |
| `<then>` | yes | Node tree |
| `<else>` | no | Node tree |

### each

| Field | Required | Meaning |
|-------|----------|---------|
| `items` | yes | Path |
| `as` | no | Loop variable, default `item` |
| `index` | no | Index variable name |
| `<body>` | yes | Node tree |

### form

| Field | Required | Meaning |
|-------|----------|---------|
| `action` | yes | Form action |
| `method` | no | `post` (default) or `get` |
| `<fields>` | yes | `<field>` elements |

Fields support these inputs: `text` (default), `password`, `email`, `number`, `textarea`, `select`, `checkbox`, `hidden`, `submit`.

| Field | Required | Meaning |
|-------|----------|---------|
| `name` | yes | Input `name` / `id` |
| `label` | yes | Label text; button text for `submit` |
| `input` | no | One of the inputs above |
| `value` | no | Bound path → `value="## $path ?? '' ##"` |
| `required` | no | Adds the `required` attribute |
| `placeholder` | no | text/password/email/number |
| `<options>` | select only | `<option value="...">label</option>` |
| `checked` | checkbox only | Bound path; outputs `checked` when truthy |
| `rows` | textarea only | Default 4 |

`select` rejects `value` (selected-state binding is out of scope); `options` on a non-select field is a compile error; `<option>` needs a `value` attribute.

`<field>` and `<column>` are typed by their tag name: a `type` attribute is unnecessary, and must match when written. `name`, `label`, `<option>` value and text, and `empty` are literal fields — `{{ }}` there is a compile error.

### table

| Field | Required | Meaning |
|-------|----------|---------|
| `items` | yes | Path |
| `as` | no | Row variable, default `row` |
| `<columns>` | yes | `<column>` elements |
| `empty` | no | Text shown for an empty list |

Columns: `label` required; exactly one of `bind` (path relative to the row variable, e.g. `id` → `row.id`) or `<content>` (node tree in row scope).

### component

```xml
<component name="card">
    <data>
        <title>{{ user.name }}</title>
        <body>简介</body>
    </data>
</component>
```

`name` required, `<data>` optional. Child element names are data keys; values support `{{ path }}` and are compiled to PHP string concatenation.

Interpolated values reach the component **unescaped** — the component template owns escaping, choosing `$this->e()` for text or `$this->raw()` for trusted markup. Pre-escaping here would double-encode anything containing HTML. Among the built-ins, `card.title` / `button.text` / `alert.text` / `badge.text` go through `e()`, while `card.body` uses `raw()`.

Built-in components (plain template files in `components/`, readable and copyable): `card` (`title`, `body`), `button` (`text`, `href`, `type`), `alert` (`type`, `text`), `badge` (`text`, `type`). Custom components are ordinary miGears Template files referenced by name.

### el

```xml
<el tag="div" x-data="{ open: false }" class="panel">
    <heading level="3">{{ user.name }}</heading>
    <text>Body</text>
</el>
```

`tag` required (lowercase HTML tag name); children form the node tree; accepts any forwarded attribute and `<attr>`. This is how wrapper attributes such as `x-data` get a home, since `text` / `if` / `each` emit no tag. An empty body is legal and compiles to `<div></div>`.

## CLI

```bash
php bin/xml-pages compile <input> [output-dir] [--check]
php bin/xml-pages --help
```

- `<input>` — a `.page.xml` file or a directory (processed recursively)
- `[output-dir]` — defaults to the source directory
- `--check` — validate only, write nothing
- `users.page.xml` → `users.tpl.php`; existing outputs are overwritten unconditionally
- Exit code: `0` all good, `1` any failure; directory mode continues with the remaining files

## Errors

Compile errors throw `MiGears\XmlPages\Exception\CompileException` with a node path, e.g.:

```
views/pages/users.page.xml: sections.content[2].columns[2]: 列同时指定 bind 与 content
```

The CLI prints errors to stderr with the file name; directory mode keeps going on failure.

A parse failure caused by `@` in an attribute position carries a fix hint, since libxml only reports `error parsing attribute name`:

```
XML 语法错误: error parsing attribute name；XML 属性名不能含 "@"：写 @click 请改用 __click（等价 x-on:click）
```

## Testing

```bash
composer test
```

Unit tests assert exact compiled output; integration tests render the compiled page through the full miGears Template pipeline.

## License

MIT

---

# migears/xml-pages

![Version](https://img.shields.io/badge/version-2.0.0-blue)

基于 XML 的声明式页面定义工具：把 `.page.xml` 页面声明编译为 miGears 模板文件（`.tpl.php`），模板引擎在首次渲染时再将其编译为纯 PHP。XML 声明是唯一事实标准；生成的模板是派生文件，不应手工修改。

与 `migears/yaml-pages` 是姊妹包：同一个声明式 DSL，用 XML 表达。节点模型与编译产物完全一致——两个前端都把各自格式解析成 `migears/pages` 的数组 DSL，共享编译器在那里。只有解析层不同，选哪个格式由你决定。

## 特性

- PHP 8.1+，PSR-4 自动加载，命名空间 `MiGears\XmlPages`
- **零第三方依赖** —— 解析用 PHP 内置 SimpleXML（libxml）
- 声明页面结构、数据绑定、条件显示（`if`）、循环列表（`each`）、表单字段、表格列与 layout 继承
- `{{ path }}` 插值自动转义 —— XSS 防护由模板引擎承担
- 编译期校验结构、字段、路径与属性，**不静默丢弃任何东西**
- **属性透传**：`x-on:click`、`v-bind:href`、`wire:click`、`hx-get`、`data-*`、`class`/`id`/`style` 原样输出到生成的标签
- 通用容器 `<el tag="...">`，给 `x-data` 这类包裹层属性一个落点
- 内置组件（`card`、`button`、`alert`、`badge`），自定义组件按 miGears Template 规范编写
- 明确不做：业务逻辑、事件处理、状态管理、路由、运行期解析 XML —— 这些交给你搭配的前端框架

## 工作原理

**刻意两次编译：**

1. xml-pages 把 XML 声明解析为 `migears/pages` 的数组 DSL，由那里的共享编译器翻译为 `.tpl.php` 糖语法（`## $expr ##`）。中间产物保持可读，每个 DSL 词汇对应什么模板语法一目了然。
2. `migears/template` 的 `TemplateCompiler` 把糖编译成纯 PHP 模板（mtime 缓存，仅模板变更后重编一次）。渲染由 PHP 执行：模板运行时把变量以 HTML 形式输出给浏览器，声明层不进入运行期。

生成的 `.tpl.php` 是派生文件——重新编译即覆盖。修改 XML，不要改产物。

## 安装

```bash
composer require migears/xml-pages
```

要求 PHP 8.1+ 与 `migears/template` ^2.0。无需安装任何扩展——SimpleXML 随 PHP 内置。

让模板引擎能找到内置组件：

```php
use MiGears\Template\Template;

$tpl = new Template(__DIR__ . '/views');
$tpl->addPath('vendor/migears/xml-pages/components');
```

或把 `components/` 拷入项目的模板目录。

## 快速开始

编写页面声明 `views/pages/users.page.xml`：

```xml
<page title="用户管理" layout="layout/main">
    <sections>
        <section name="title">
            <text>用户管理</text>
        </section>
        <section name="content">
            <heading level="2">用户列表</heading>
            <table items="users" as="user" empty="暂无数据">
                <columns>
                    <column label="ID" bind="id"/>
                    <column label="姓名" bind="name"/>
                    <column label="操作">
                        <content>
                            <link href="/users/{{ user.id }}/edit">编辑</link>
                        </content>
                    </column>
                </columns>
            </table>
        </section>
    </sections>
</page>
```

编译：

```bash
php vendor/bin/xml-pages compile views/pages/users.page.xml
```

生成 `views/pages/users.tpl.php`。与普通模板一样渲染：

```php
echo $tpl->render('pages/users', [
    'users' => [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ],
]);
```

覆盖全部语法特性的完整示例见 `examples/full-featured.page.xml`，配套最小布局在 `examples/views/layout/main.php`。编译到该目录即可直接渲染：

```bash
php bin/xml-pages compile examples/full-featured.page.xml examples/views
```

## XML 编写注意

对应规则：

- **元素名即节点类型**：`<text>`、`<heading>`、`<link>`、`<if>`、`<each>`、`<form>`、`<table>`、`<el>`、`<component>`。
- **字段走属性**：如 `<heading level="2">`、`<link href="..." target="_blank">`、`<if when="...">`。
- **文本内容走元素文本**：`<text>`、`<heading>`、`<link>` 的元素文本即 `text` 字段；首尾空白会被修剪。
- **容器子元素**：`<if>` → `<then>`/`<else>`；`<each>` → `<body>`；`<form>` → `<fields>`（内含 `<field>`）；`<table>` → `<columns>`（内含 `<column>`）；`<field>` → `<options>`（内含 `<option value="...">`）；`<column>` → `<content>`；`<component>` → `<data>`（子元素名即数据键）。

转义与特殊字符：

| 场景 | 写法 |
|------|------|
| 文本中的 `<`、`>`、`&` | 实体 `&lt;` `&gt;` `&amp;` |
| 换行 | 字面换行，或实体 `&#10;` |
| 多行文本 | 直接写在元素文本中；首尾空白修剪，内部空白原样保留 |
| 原样输出 HTML | `<text><![CDATA[<strong>粗体</strong>]]></text>` |
| 无值属性（`x-cloak`） | 写成 `x-cloak=""` —— XML 不允许没有值的属性 |

其余注意：

- `{{ path }}` 插值在 XML 中**无需转义**——`{`、`}` 不是 XML 特殊字符，这是相对 YAML 的优势。
- `required="true"` 解析为布尔；`"true"`/`"1"`/`"yes"`/`"on"`（大小写不敏感）均为真。
- `level`、`rows` 解析为整数。
- 叶子节点（`text`/`heading`/`link`）内不要嵌套子元素——嵌套标签会丢失，只剩拼接文本；需要 HTML 时用 CDATA。
- 未知属性、未知子元素、拼错的容器子元素（如 `<colum>`）**一律编译错误**，不静默丢弃；节点类型写错同样报错。详见《前端框架集成》与《错误处理》。
- 容器只接受**子元素**。直接写在容器里的文本或 CDATA 够不到节点模型，属编译错误而非静默丢弃——请用 `<text>` 包裹（原样 HTML 用 `<text><![CDATA[...]]></text>`）。缩进空白不算。

## 前端框架集成

XML 在属性**名**上比 HTML 严格：`@` 不是合法的 NameStartChar，`:` 被保留给命名空间。因此节点上的属性分三类处理：

1. **DSL 字段** —— 节点自己消费（`heading.level`、`link.href`、`form.action`）。
2. **透传属性** —— 原样输出到该节点生成的标签：
   - `__event` —— `@event` 的可读写法（见下）
   - 带冒号的名字：`x-on:click`、`x-bind:href`、`v-on:click`、`wire:click`、`on:click`、`:href`
   - 前缀：`x-`、`v-`、`hx-`、`data-`
   - HTML 钩子：`class`、`id`、`style`
3. **其余一律编译错误** —— 视为拼写错误，绝不静默丢弃。

不输出标签的节点（`text`、`if`、`each`、`component`）不接受透传属性，用 `<el>` 包裹即可。透传值先转义、后插值，所以 `{{ }}` 在属性值里照常可用（且单引号保持可读，不会变成 `&#039;`）。

### `__event` 就是 `@event`

XML 属性名不能含 `@`，所以 `@click` 根本写不出来。以 `__` 开头的属性名按**位置**映射——`__` 换成 `@`，其余照抄：

| 写 | 编译为 |
|----|--------|
| `__click="open = ! open"` | `@click="open = ! open"` |
| `__keydown.escape.window="close()"` | `@keydown.escape.window="close()"` |

位置映射意味着不需要切分猜测——这正是**不**接受 `x-on-click` 的原因：`x-on-keydown-enter` 无法判断该切成 `x-on:keydown-enter` 还是 `x-on-keydown:enter`。所以 `x-on-click`、`x-bind-href`、`x-transition-enter` 会直接报错并给出建议，而不是被透传成一个 Alpine 根本不认的指令。

由于 `@` 就是 `x-on:` 的简写，`__event` 与 `x-on:event` 完全等价；`:` 形式与 `wire:` / `hx-` 本就能写，无需补偿。启用 `__` 等于占用了这个前缀，项目自己的 `__xxx` 属性要走 `<attr>`。

其余情况（包括需要字面 `__xxx` 名字）用 `<attr>` 子节点：名字作为**值**传入，且 `<attr>` 的 name 原样输出、不做 `__` 映射：

```xml
<el tag="div" x-data="{ open: false }" class="panel">
    <attr name="@click" value="open = ! open"/>
    <attr name=":class" value="open &amp;&amp; 'on'"/>
    <text>切换</text>
</el>
```

| 约束 | 说明 |
|------|------|
| 可用位置 | 会输出标签的节点的子元素：`el` / `heading` / `link` / `form` / `table` / `field` / `column` |
| `name` | 必填，任意合法 HTML 属性名（含 `@`）；原样输出、不做 `__` 映射 |
| `value` | 必填，支持 `{{ }}` 插值 |
| 重复 | 与已有的同名属性冲突即编译错误 |

能写标准形式（`x-on:click`）时优先标准形式，`<attr>` 只用于 XML 拼不出来的名字。

### 框架可用性

| 框架 | 属性写法 | 结果 |
|------|----------|------|
| Alpine | `x-on:click`、`x-bind:href`、`x-data`、`x-show`、`x-cloak=""` | 可用 |
| Alpine | `__click`、`__keydown.escape.window` | 可用（等同 `@click`） |
| Alpine | `@click` | 失败 —— `@` 不是合法 XML 名称。改用 `__click`、`x-on:click` 或 `<attr>`；`:href` 可用 |
| Alpine | `x-on-click`（连字符） | 拒绝 —— Alpine 只有冒号形式 |
| Vue | `v-on:click`、`v-bind:href`、`:href` | 可用 |
| Vue | `__click` | 可用（等同 `@click`） |
| Vue | `@click` | 失败 —— 改用 `__click`、`v-on:click` 或 `<attr>` |
| htmx | `hx-get`、`hx-trigger` | 可用 |
| Stimulus | `data-controller`、`data-action` | 可用 |
| Livewire | `wire:click`、`wire:model.live` | 可用 |

### 同一区域只能有一个 owner

服务端渲染与客户端渲染不能同时拥有同一块 DOM。结构交给服务端的 `each` / `if` / `table`，交互挂在 Alpine 上——但不要再对该列表用 `x-for`，否则 Alpine 会用它的模板重新生成，结果是重复节点加闪烁。

## 数据绑定

`{{ path }}` 把点路径插值为自动转义输出：

| 路径 | 编译为 |
|------|--------|
| `{{ users }}` | `## $users ?? '' ##` |
| `{{ user.name }}` | `## $user['name'] ?? '' ##` |
| `{{ form.errors.email }}` | `## $form['errors']['email'] ?? '' ##` |

规则：

- 仅支持 `a.b.c` 形式的路径——函数调用、算术、字符串字面量一律编译错误。
- 路径编译为**数组访问**；Domain 实体请在控制器边界转数组。
- 只有 `if.when` 支持 `!` 前缀取反；其他位置（如 `each.items`）写 `!` 一律编译错误。
- 条件与循环用 `?? null` 兜底，文本与属性用 `?? ''` 兜底。
- 插值最多两个花括号：`{{{` 或 `}}}` 属编译错误。第三个花括号会骗过配对计数，把错乱的花括号留在渲染结果里。
- 字面量字段——`layout`、section 名、`form.method`、`field.name`、`field.label`、`<option>` 的 value 与文本、`empty`、`column.label`、`component.name`——原样输出；在其中写 `{{ }}` 属编译错误，不会静默忽略。

## 节点参考

`body` / `sections` 中的每个元素都是一个节点，**标签名即类型**。可选类型：`text`、`heading`、`link`、`if`、`each`、`form`、`table`、`component`。内嵌结构（`<field>`、`<column>`）同样由标签名决定类型——不必写 `type` 属性；若写出，值必须匹配（`field` / `column`），否则编译失败。

### 页面根

| 字段 | 形式 | 必填 | 说明 |
|------|------|------|------|
| `title` | `<page>` 属性 | 否 | 页面标题，写入 `title` section（仅 layout 时生效） |
| `layout` | `<page>` 属性 | 否 | 继承的布局模板名，如 `layout/main` |
| `body` | `<page>` 子元素 | 视情况 | 无 `layout` 时的节点树 |
| `sections` | `<page>` 子元素 | 视情况 | 含 `<section name="...">`，与 `layout` 搭配 |

`layout` + `sections` 与 `body` 互斥。每个 `<section>` 必须有 `name` 属性。

### text / heading / link

```xml
<text>你好，{{ user.name }}</text>
<heading level="2">用户管理</heading>
<link href="/users/{{ user.id }}/edit">编辑</link>
```

- `text` —— 元素文本即文本；字面输出，插值自动转义
- `heading` —— `level` 取值 1–6（默认 1）
- `link` —— `href` 必填，`target` 可选

### if

| 字段 | 必填 | 说明 |
|------|------|------|
| `when` | 是 | 路径，支持 `!` 前缀 |
| `<then>` | 是 | 节点树 |
| `<else>` | 否 | 节点树 |

### each

| 字段 | 必填 | 说明 |
|------|------|------|
| `items` | 是 | 路径 |
| `as` | 否 | 循环变量，默认 `item` |
| `index` | 否 | 索引变量名 |
| `<body>` | 是 | 节点树 |

### form

| 字段 | 必填 | 说明 |
|------|------|------|
| `action` | 是 | 表单提交地址 |
| `method` | 否 | `post`（默认）或 `get` |
| `<fields>` | 是 | `<field>` 元素 |

字段支持以下 input：`text`（默认）、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。

| 字段 | 必填 | 说明 |
|------|------|------|
| `name` | 是 | 输入框 `name` / `id` |
| `label` | 是 | 标签文本；`submit` 时为按钮文字 |
| `input` | 否 | 上述 input 之一 |
| `value` | 否 | 绑定路径 → `value="## $path ?? '' ##"` |
| `required` | 否 | 输出 `required` 属性 |
| `placeholder` | 否 | text/password/email/number |
| `<options>` | 仅 select | `<option value="...">label</option>` |
| `checked` | 仅 checkbox | 绑定路径；真值时输出 `checked` |
| `rows` | 仅 textarea | 默认 4 |

`select` 不接受 `value`（选中态绑定不在范围内）；`options` 用在非 select 字段上是编译错误；`<option>` 必须带 `value` 属性。

`<field>` 与 `<column>` 的类型由标签名决定——不必写 `type` 属性；若写出，值必须与标签名一致。`name`、`label`、`<option>` 的 value 与文本、`empty` 是字面量字段，写 `{{ }}` 属编译错误。

### table

| 字段 | 必填 | 说明 |
|------|------|------|
| `items` | 是 | 路径 |
| `as` | 否 | 行变量，默认 `row` |
| `<columns>` | 是 | `<column>` 元素 |
| `empty` | 否 | 空列表时显示的文本 |

列：`label` 必填；`bind`（相对行变量的路径，如 `id` → `row.id`）与 `<content>`（行变量作用域内的节点树）二选一。

### component

```xml
<component name="card">
    <data>
        <title>{{ user.name }}</title>
        <body>简介</body>
    </data>
</component>
```

`name` 必填，`<data>` 可选。子元素名即数据键，值支持 `{{ path }}` 插值，编译为 PHP 字符串拼接。

插值值以**未转义**形式传给组件——转义由组件模板决定：文本用 `$this->e()`，信任的 HTML 用 `$this->raw()`。编译期预转义会与组件模板的转义叠成双重转义。内置组件中 `card.title` / `button.text` / `alert.text` / `badge.text` 走 `e()`，`card.body` 走 `raw()`。

内置组件（`components/` 下的普通模板文件，可直接阅读复制）：`card`（`title`、`body`）、`button`（`text`、`href`、`type`）、`alert`（`type`、`text`）、`badge`（`text`、`type`）。自定义组件是普通的 miGears Template 文件，按名引用。

### el

```xml
<el tag="div" x-data="{ open: false }" class="panel">
    <heading level="3">{{ user.name }}</heading>
    <text>正文</text>
</el>
```

`tag` 必填（小写 HTML 标签名）；子元素即节点树；接受任意透传属性与 `<attr>`。由于 `text` / `if` / `each` 自己不输出标签，这是给 `x-data` 这类包裹层属性找落点的唯一方式。空子节点合法，编译为 `<div></div>`。

## CLI

```bash
php bin/xml-pages compile <input> [output-dir] [--check]
php bin/xml-pages --help
```

- `<input>` —— `.page.xml` 文件或目录（目录时递归处理）
- `[output-dir]` —— 缺省与源文件同目录
- `--check` —— 仅校验，不写文件
- `users.page.xml` → `users.tpl.php`；已有产物无条件覆盖
- 退出码：`0` 全部成功，`1` 任一失败；目录模式出错不中断

## 错误处理

编译错误抛出 `MiGears\XmlPages\Exception\CompileException`，信息带节点路径，例如：

```
views/pages/users.page.xml: sections.content[2].columns[2]: 列同时指定 bind 与 content
```

CLI 将错误输出到 stderr 并附文件名；目录模式继续处理其余文件。

若因属性名里的 `@` 导致解析失败，会附带修复提示——libxml 只会说 `error parsing attribute name`：

```
XML 语法错误: error parsing attribute name；XML 属性名不能含 "@"：写 @click 请改用 __click（等价 x-on:click）
```

## 测试

```bash
composer test
```

单元测试断言编译产物，集成测试把编译产物经 migears/template 完整渲染验证。

## License

MIT
