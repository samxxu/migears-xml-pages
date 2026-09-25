# migears/xml-pages Module Specification

Version: 2.0.0 (draft, pending review)
Date: 2026-09-20

## 1. Positioning

xml-pages is an optional companion module for the miGears framework: an XML-based declarative page definition tool that compiles page declarations into template files of migears/template (`.tpl.php` syntax). It is not a core component and carries no runtime responsibility; it does only the compile-time "declaration → template" translation.

It is **two input formats for the same DSL** as `migears/yaml-pages`: the node model (body / sections / field / column / component data) and the compiled output are completely identical; only the parsing layer differs. Both exist and the user picks one.

Both are **syntax frontends** for `migears/pages`: after each parses its own format into an array IR, node compilation, validation, interpolation and attribute passthrough are all done by the shared compiler in the pages package (see migears/pages' spec.md for the IR contract). This package keeps only the XML parsing layer and a few spelling hooks.

It solves three problems:

1. **AI generation accuracy** — structured XML declarations are more reliably generated without errors by large models than mixed HTML/PHP template code.
2. **Readable page structure** — what a page looks like and which data it binds is clear at a glance from the XML, so non-developers can participate too.
3. **Extend rather than replace** — migears/template itself is minimal and limited; xml-pages fixes common page shapes (lists, forms, conditionals, loops) with a declarative abstraction, letting business development focus on data and structure.

## 2. Scope

### 2.1 In scope

- Page structure definition (node tree)
- Data binding (`{{ path }}` interpolation)
- Conditional display (`if`)
- Loops / lists (`each`, `table`)
- Form fields (`form` + `field`)
- Table column definitions (`table` + `column`)
- Layout inheritance (`layout` + `sections`)
- Built-in components + custom component references
- Attribute passthrough (front-end framework directives and `class` / `id` / `style` emitted verbatim on the tag)
- The generic element node `el` (an attribute container for things like `x-data`)

### 2.2 Out of scope (explicitly not done)

- Business logic, event handling, state management, routing definition — never go into XML; those belong to the front-end framework
- Runtime XML parsing — compilation is the only entry point; runtime depends only on the generated template
- Composer third-party dependencies — the parsing layer uses PHP's built-in SimpleXML (libxml), zero external dependencies
- XML Schema / DTD validation — no schema documents; all structural validation happens at compile time

## 3. Core Principles

### 3.1 XML is the single source of truth

Every change to a page is made back in XML. The generated `.tpl.php` is a **derived file** that can be overwritten at any time by recompiling and must not be hand-edited. The workflow is fixed as: edit XML → run the compiler → render.

### 3.2 Two deliberate compilations

First compilation: xml-pages parses the XML declaration into the array IR of `migears/pages`, whose shared compiler translates it into a `.tpl.php` sugar-syntax template. This step keeps the output readable — it is obvious at a glance which template syntax each DSL keyword maps to, and developers understand the declaration semantics and control the generated code by reading the output.

Second compilation: migears/template's `TemplateCompiler` compiles the `.tpl.php` into a pure PHP template (mtime-cached, recompiled only when the template changes). Rendering is done by PHP: the template runtime outputs variables to the browser as HTML; the declaration layer never enters runtime.

Both compilations matter. Neither is merged nor elided.

### 3.3 Extremely lightweight

The implementation stays at the thousand-line scale (this package's parsing layer is about 500 lines — all compilation logic lives in the migears/pages shared layer, about 1050 lines; the CLI is about 110 lines; components are plain template PHP files). Any feature that would significantly bloat the implementation is rejected.

### 3.4 Compile is validation

Structure, fields, paths and attributes are fully validated at compile time, with **no silent drops**: unknown attributes, unknown child elements and misspelled container children all error out. Any scenario where XML cannot express the template capability is rejected at compile time instead of inventing workaround syntax on the XML side — the sole exception is §4.4's `<attr>`, which addresses a limitation of the XML language itself (`@` cannot appear in attribute names), not a template capability.

## 4. Declaration Format

File extension `.page.xml`; the compiled artifact takes the same name with `.tpl.php` (e.g. `users.page.xml` → `users.tpl.php`).

The root element must be `<page>`, whose attributes are the top-level fields and whose child elements are the content:

```xml
<page title="用户管理" layout="layout/admin">
  <sections>
    <section name="title">...</section>
    <section name="content">...</section>
  </sections>
</page>
```

### 4.1 Top-level fields

| Field | Form | Required | Meaning |
|------|------|------|------|
| `title` | `<page>` attribute | no | Page title, written into the `title` section |
| `layout` | `<page>` attribute | no | Inherited layout template name (e.g. `layout/admin`) |
| `body` | `<page>` child | conditional | Page body node tree when there is no `layout` |
| `sections` | `<page>` child | conditional | Contains several `<section name="...">`, paired with `layout` |

Rules: when `layout` is present, `sections` is required and `body` is forbidden; when `layout` is absent, `body` is required and `sections` is forbidden. Violating this is a compile error.

A `<section>` must have a `name` attribute; its children form the node-tree array. When the `title` attribute is present, a `title` section is generated automatically (only effective with `layout`; ignored with a warning when there is no `layout`).

A section's `name` must be unique within the page: the section map is keyed by name, so a repeat would silently collapse two sections into one, and a duplicate is therefore a compile error (`is defined more than once; a section name may only appear once`). Surrounding whitespace around the name is trimmed like every other text field (a stray space would never match the layout section it is meant to fill); a name that trims to empty counts as the attribute being absent and errors the same way as a missing `name`.

### 4.2 XML writing notes

The parsing layer is libxml (PHP's built-in SimpleXML). Correspondences to the node model:

- **Element name is the node type**: `<text>`, `<heading>`, `<link>`, `<if>`, `<each>`, `<form>`, `<table>`, `<el>`, `<component>`.
- **Fields go in attributes**: e.g. `<heading level="2">`, `<link href="..." target="_blank">`, `<if when="...">`.
- **Text content goes in the element text**: the element text of `<text>`, `<heading>`, `<link>` is the `text` field; leading/trailing whitespace is trimmed.
- **Container children**: `<if>`'s children are `<then>`/`<else>`; `<each>`'s is `<body>`; `<form>`'s is `<fields>` (containing `<field>`); `<table>`'s is `<columns>` (containing `<column>`); `<field>`'s is `<options>` (containing `<option value="...">`); `<column>`'s is `<content>`; `<component>`'s is `<data>` (child element names are the data keys).
- **A container child may appear at most once**: `<sections>` / `<body>` / `<then>` / `<else>` / `<fields>` / `<columns>` / `<data>` / `<options>` / `<content>` are singular by definition, and SimpleXML's `isset($el->body)` answers about the first match only — a second one was dropped with the page still compiling. A repeat is therefore a compile error (`<body> is defined more than once; a container element may only appear once`).

Escaping and special characters:

| Case | Write |
|------|------|
| `<`, `>`, `&` in text | entities `&lt;` `&gt;` `&amp;` |
| Newline | literal newline, or entity `&#10;` |
| Multi-line text | write it inline; outer whitespace trimmed, inner whitespace preserved verbatim |
| HTML fragment to output as-is | `<text><![CDATA[<strong>粗体</strong>]]></text>` |
| Valueless attribute (`x-cloak`) | must write `x-cloak=""` — XML does not allow an attribute with no value |

Other notes:

- Attribute values are wrapped in double quotes; when the value itself contains a double quote, use `&quot;` or wrap the attribute value in single quotes (`href='/x'`).
- `{{ path }}` interpolation needs **no escaping in XML** — `{` and `}` are not XML-special characters; this is a natural advantage over YAML.
- `required` is read per HTML boolean-attribute semantics: `"true"`/`"1"`/`"yes"`/`"on"` (case-insensitive) are true, `"false"`/`"0"`/`"no"`/`"off"` are false, and both `required=""` and `required="required"` count as "present" (HTML's two present spellings). Any other spelling is a compile error — answering false would silently drop the attribute, which is exactly the silent drop this module forbids.
- `level` and `rows` parse as decimal integers; a non-numeric value (e.g. `level="two"`) is a compile error, and range checks (e.g. `level` 1–6) still belong to the shared compiler.
- Leaf nodes (`text`/`heading`/`link`) allow only `<attr>` children: nesting any other tag is a compile error, not a silent drop that leaves a concatenated string; use CDATA for HTML.
- Unknown attributes, unknown child elements and misspelled container children (e.g. `<colum>`) are **always compile errors**, never silently dropped; a misspelled node type is reported as "unknown node type". See §4.3 and §9.
- Containers accept **child elements only**. Text or CDATA written directly inside a container is unreachable from the node model and is therefore a compile error — wrap it in `<text>`; write `<text><![CDATA[...]]></text>` for raw HTML. Indentation whitespace does not count.

### 4.3 Attribute passthrough

Attributes on a node are handled in three categories:

1. **DSL fields** — fields the node type itself consumes (e.g. `heading.level`, `link.href`, `form.action`).
2. **Forwarded attributes** — emitted verbatim on the tag the node produces. Whitelist:
   - `__event` — the readable spelling of `@event` (below)
   - framework directive names containing a colon: `x-on:click`, `x-bind:href`, `v-on:click`, `wire:click`, `on:click`, `:href`, etc.
   - prefixes: `x-`, `v-`, `hx-`, `data-`
   - common HTML hooks: `class`, `id`, `style`, and `bind` (the front-end framework's binding attribute, whose value is a browser-side variable name)
3. **Everything else is a compile error** — an unknown attribute is treated as a typo and never silently dropped (the old behavior silently swallowed directives, the most dangerous failure mode).

Nodes that emit no tag (`text`, `if`, `each`, `component`) do not accept forwarded attributes; wrap them in `el`.

**Wrapper elements** (`sections` / `body` / `then` / `else` / `fields` / `columns` / `data` / `options` / `content`) emit no tag either, so there is nothing for an attribute to attach to and they accept **no** attributes at all; any attribute on one is a compile error (`unknown attribute "class" on <section>`). The only wrapper spellings that mean something are whitelisted element by element: `<section>` takes only `name`, `<option>` only `value`, and `<attr>` only `name` / `value`. Everything else is treated as a typo, because a wrapper attribute used to vanish without a trace.

Forwarded-attribute values are HTML-attribute-escaped first (`ENT_COMPAT`, keeping single quotes readable), then `{{ }}`-interpolated — the order must not be reversed, otherwise the quotes inside the `## ##` sugar syntax would be broken by escaping.

**`__event` → `@event`**: XML does not allow `@` in attribute names (not a legal NameStartChar), so `@click` cannot be written directly. Attribute names starting with `__` are mapped positionally — `__` becomes `@`, the rest is copied verbatim:

| Write | Compiles to |
|----|--------|
| `__click="open = ! open"` | `@click="open = ! open"` |
| `__keydown.escape.window="close()"` | `@keydown.escape.window="close()"` |

The mapping is **positional** (`@` is always first), so there is no splitting ambiguity. By contrast, hyphenated forms like `x-on-click` cannot be reliably restored — `x-on-keydown-enter` cannot tell whether to split into `x-on:keydown-enter` or `x-on-keydown:enter` — so this module does **not** guess; it errors directly instead (see below).

`@` is shorthand for `x-on:`, so `__event` and `x-on:event` are fully equivalent; and `:` / `x-bind:` / `wire:` / `hx-` etc. can be written normally and need no compensation. Once the `__` prefix is used it is taken; for your project's own `__xxx` forwarded attributes, use `<attr>` (see §4.4).

**Targeted error for hyphenated forms**: `x-on-*`, `x-bind-*` and `x-transition-*` do not exist in Alpine (Alpine always uses the colon). Because the `x-` prefix would otherwise let these spellings through, they would be silently forwarded and compile successfully while the directive stays dead — so they are intercepted and a suggestion is given:

```
body[0]: 未知属性 "x-on-click"；Alpine 的事件/绑定指令用冒号形式，请写 "x-on:click" 或 "__click"
```

XML is stricter than HTML about attribute names: `:` is a reserved namespace separator, but libxml only warns and still keeps the attribute, so the colon forms in the table above work; the only thing truly unwritable is a leading `@`.

Attributes are read through DOM rather than SimpleXML's `attributes()`, which returns unprefixed names only, so a namespaced name such as `xml:lang` is no longer silently ignored: on a wrapper element it is refused like any other unknown attribute, and on a node that emits a tag it is forwarded verbatim under the same "a name containing a colon is passed through" rule as the directives above.

### 4.4 `<attr>` explicit attributes

When you need a name that the `__` mapping cannot cover (or any legal HTML attribute name), use an `<attr>` child node — the attribute name is passed as a **value** and is not bound by XML name rules:

```xml
<el tag="button" class="btn">
  <attr name="@click" value="open = ! open"/>
  <attr name=":class" value="open &amp;&amp; 'on'"/>
  <attr name="__raw" value="literal"/>
  切换
</el>
```

Compiles to:

```php
<button class="btn" @click="open = ! open" :class="open &amp;&amp; 'on'" __raw="literal">
切换
</button>
```

| Constraint | Meaning |
|------|------|
| Allowed placement | child of a node that emits a tag: `el` / `heading` / `link` / `form` / `table` / `field` / `column` |
| `name` | required, any legal HTML attribute name (including `@`), **emitted verbatim, no `__` mapping** |
| `name` legality | since the name is emitted exactly as written, it may not contain whitespace, quotes, `<`, `>`, `/` or `=`; anything else is a compile error (`is not a legal attribute name`) |
| `value` | required, supports `{{ }}` interpolation |
| Duplicate | conflicts with an existing attribute of the same name is a compile error |
| Field collision | colliding with the node's own DSL field is a compile error (e.g. `<attr name="href">` on a `<link>`) |
| Misuse | being a direct child of a container (`body`/`then`/`content` etc.) is a compile error |

The `<attr>` name is not mapped, so it is both the canonical explicit way to write `@click` and the escape hatch when you need a literal `__xxx` attribute. When you can write the standard form directly, prefer a plain attribute (`x-on:click` or `__click`).

## 5. Data Binding Syntax

### 5.1 Path expressions

A path is the sole carrier of data binding, with strict grammar:

```
path   := segment ( "." segment )*
segment := [A-Za-z_][A-Za-z0-9_]*
```

The first segment is the variable name; later segments are array-key access. Examples:

| Path | Compiles to |
|------|--------|
| `users` | `$users` |
| `user.name` | `$user['name']` |
| `form.errors.email` | `$form['errors']['email']` |

The compiled access always carries a `?? ''` fallback (text/attribute context) or `?? null` fallback (conditional/loop context) to avoid warnings on undefined keys.

### 5.2 Interpolation `{{ path }}`

Text and attribute values support `{{ path }}` interpolation, compiled to **auto-escaped** output:

```xml
<text>你好，{{ user.name }}</text>
```

Compiles to:

```php
你好，## $user['name'] ?? '' ##
```

`## ##` is compiled by TemplateCompiler into `<?= $this->e($user['name'] ?? '') ?>`; XSS protection is handled by the template engine.

Interpolation appears only in two contexts, compiled differently:

| Context | Compilation | Example |
|--------|----------|------|
| HTML text / attribute (text, heading, link, etc.) | keeps the `## expr ##` sugar verbatim | `href="/users/## $user['id'] ?? '' ##"` |
| PHP array literal (component `data`) | string concatenation `'...' . ($expr) . '...'`, **not pre-escaped** | `'title' => '编辑 ' . ($user['name'] ?? '')` |

The PHP context must never output `## ##` sugar — it would be substituted a second time by TemplateCompiler into a PHP string literal and cause a syntax error.

Interpolation works only in these two contexts. All other fields are **literal fields**: `layout`, section name, `form.method`, `field.name`, `field.label`, `<option>`'s value and display text, `empty`, `column.label`, `component.name`. These fields are emitted verbatim; writing `{{ }}` in them has no effect and is a compile error (no longer silently ignored).

### 5.3 Invalid paths and interpolation markers

Anything inside a `{{ ... }}` that does not match path grammar (function calls, arithmetic, string literals, nested interpolation) is a compile error, reported with the node path.

Interpolation allows at most two braces: an occurrence of `{{{` or `}}}` is a compile error. Three braces trick the pairing count (inside `{{{ a }}}` there is one `{{` and one `}}`, which looks paired), the regex matches only the inner `{{ a }}`, and the leftover braces remain in the output verbatim — so the page would display mangled `{` `}`.

### 5.4 Data shape constraint

Paths compile to array access (`$user['name']`). Page data is contractually **array-shaped**, normalized by the controller at the boundary (Domain entities converted to arrays). This is a documented constraint; no object compatibility is done inside this module.

## 6. Node Vocabulary

Every element in body/sections is a node, and **the element name is the type**. There are 9 node types + 2 nested structures; the nested structures (`<field>`, `<column>`) are also typed by their element name, so a `type` attribute is unnecessary and, if written, must match the element name (so the two node models stay fully identical):

| Node | Purpose |
|------|------|
| `<text>` | text, supports interpolation |
| `<heading>` | heading |
| `<link>` | link |
| `<if>` | conditional display |
| `<each>` | loop / list |
| `<form>` + `<field>` | form and its fields |
| `<table>` + `<column>` | table and its columns |
| `<el>` | generic element container, carries attributes and a child node tree |
| `<component>` | references a built-in or custom component |

### 6.1 text

```xml
<text>你好，{{ user.name }}</text>
```

The element text is the `text` value, emitted verbatim (the literal part is controlled by the author and may contain HTML). Interpolation is auto-escaped. Multi-line text is allowed (outer whitespace trimmed). No children other than `<attr>` — a nested tag is a compile error and is never silently dropped; use CDATA for HTML.

### 6.2 heading

```xml
<heading level="2">用户管理</heading>
```

The `level` attribute takes 1–6, default 1; out of range is a compile error. Compiles to `<hN>...</hN>`.

### 6.3 link

```xml
<link href="/users/{{ user.id }}/edit">编辑</link>
```

The `href` attribute is required; the element text is `text`; both support interpolation (interpolation is auto-escaped, safe in attribute context). The `target` attribute is optional and supports interpolation; its value is not validated — HTML allows named targets beyond `_blank`, and an enum whitelist would wrongly reject legitimate uses.

### 6.4 if

```xml
<if when="user.loggedIn">
  <then><text>A</text></then>
  <else><text>B</text></else>
</if>
```

The `when` attribute is required; the path may take a `!` prefix for negation; `<then>` is required; `<else>` is optional. Compiles to:

```php
<?php if ($user['loggedIn'] ?? null): ?>
  ...then...
<?php else: ?>
  ...else...
<?php endif ?>
```

The negated form `when="!user.hidden"` (`!` needs no escaping in an XML attribute) compiles to `<?php if (!($user['hidden'] ?? null)): ?>`.

### 6.5 each

```xml
<each items="users" as="user" index="i">
  <body>
    <text>{{ user.name }}</text>
  </body>
</each>
```

The `items` attribute is a required path, `as` defaults to `item`, `index` is optional. `!` negation belongs only to `if.when`; writing `!` on `items` is reported as an invalid path. Compiles to:

```php
<?php foreach ($users as $i => $user): ?>
  ...body...
<?php endforeach ?>
```

Nested each is allowed; an inner `as` with the same name naturally shadows per PHP semantics.

### 6.6 form + field

```xml
<form action="/users/save" method="post">
  <fields>
    <field name="name" label="姓名" input="text" value="user.name" required="true" placeholder="请输入姓名"/>
    <field name="role" label="角色" input="select">
      <options>
        <option value="admin">管理员</option>
        <option value="user">普通用户</option>
      </options>
    </field>
    <field name="bio" label="简介" input="textarea" rows="4" value="user.bio"/>
    <field name="active" label="启用" input="checkbox" checked="user.active"/>
    <field name="submit" label="保存" input="submit"/>
  </fields>
</form>
```

**form**: the `action` attribute is required, `method` defaults to `post`, and `<fields>` is required.

**field** fields:

| Field | Type | Required | Meaning |
|------|------|------|------|
| `name` | string | yes | field name (`name` / `id` attribute) |
| `label` | string | yes | label text; the button text for `submit` type |
| `input` | enum | no | see below, defaults to `text` |
| `value` | path | no | bound value, compiles to `value="## $path ?? '' ##"`; not supported for `submit` (button text uses `label`) |
| `required` | bool | no | default false; adds `required` on the input that supports it; `true` on `hidden` / `submit` is a compile error |
| `placeholder` | string | no | text/password/email/number only; elsewhere a compile error |
| `options` | child | select only | `<options>` containing `<option value="...">` |
| `checked` | path | checkbox only | emits the `checked` attribute when truthy; elsewhere a compile error |
| `rows` | int | textarea only | default 4; elsewhere a compile error |

`input` enum: `text`, `password`, `email`, `number`, `textarea`, `select`, `checkbox`, `hidden`, `submit`. An invalid enum is a compile error. A `select` without `options`, `options` used on an input that does not support it, `value` used on a `select`, and an `<option>` without a `value` attribute are all compile errors. The options are keyed by `value`, so a repeated `<option value="...">` would collapse two entries into one with the first silently gone; a duplicate `value` is therefore a compile error (`an option value may only appear once`).

A field's **usage scope** is likewise a hard constraint, and going out of range is a compile error (these fields used to be silently dropped): `placeholder` is text/password/email/number only, `checked` is checkbox only, `rows` is textarea only, `value` does not support submit, `required` is text/password/email/number/textarea/select/checkbox only. When `required` is true, the `required` attribute is emitted on select / textarea / checkbox as well.

A `<field>` is a nested structure: its type is determined by the element name, so a `type` attribute is unnecessary; if written, the value must be `field`, otherwise a compile error. `name`, `label`, and `<option>`'s value and text are literal fields and do not support `{{ }}` interpolation.

Example compiled output (excerpt):

```php
<form action="/users/save" method="post">
  <label for="name">姓名</label>
  <input type="text" name="name" id="name" value="## $user['name'] ?? '' ##" required>
  <label for="role">角色</label>
  <select name="role" id="role">
    <option value="admin">管理员</option>
    <option value="user">普通用户</option>
  </select>
  <input type="submit" value="保存">
</form>
```

### 6.7 table + column

```xml
<table items="users" as="user" empty="暂无数据">
  <columns>
    <column label="ID" pop="{{ user.id }}"/>
    <column label="姓名" pop="{{ user.name }}"/>
    <column label="操作">
      <content>
        <link href="/users/{{ user.id }}/edit">编辑</link>
      </content>
    </column>
  </columns>
</table>
```

The `items` attribute is required, `as` defaults to `row`, `empty` is optional (empty-list message), and `<columns>` is required. **column**: the `label` attribute is required; exactly one of `pop` (a data reference rendered into the cell, written as `{{ row.id }}`) or `<content>` (node tree, in the row variable scope) is required, and providing both is a compile error. `pop` must be wrapped in `{{ }}` and its first segment must equal the table's `as` variable.

A `<column>` likewise does not need a `type` attribute; if written, the value must be `column`, otherwise a compile error. `label` and `empty` are literal text and do not support `{{ }}` interpolation.

Compiles to:

```php
<table>
<thead><tr><th>ID</th><th>姓名</th><th>操作</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="3">暂无数据</td></tr>
<?php else: ?>
<?php foreach ($users as $user): ?>
<tr>
<td>## $user['id'] ?? '' ##</td>
<td>## $user['name'] ?? '' ##</td>
<td><a href="/users/## $user['id'] ?? '' ##/edit">编辑</a></td>
</tr>
<?php endforeach ?>
<?php endif ?>
</tbody>
</table>
```

A `<content>` column's nodes run in the row-variable scope and can reference `user.*` directly.

### 6.8 component

```xml
<component name="card">
  <data>
    <title>{{ user.name }}</title>
    <body>简介</body>
  </data>
</component>
```

The `name` attribute is required; `<data>` is optional. Child element names are the data keys; element text is the value (supports interpolation, compiled as PHP-context concatenation). A `<data>` value is **text only** — a value holding child elements has no representation here and is a compile error (`has child elements that would be dropped`), instead of being folded into its text with the tags gone. Data keys must be unique too: the map is keyed by element name, so a repeat is a compile error (`a data key may only appear once`). Compiles to:

```php
<?= $this->component('card', [
    'title' => ($user['name'] ?? ''),
    'body' => '简介',
]) ?>
```

**Escaping contract**: interpolated values are passed to the component **unescaped**; the escaping responsibility lies with the component template, which chooses `$this->e()` (text) or `$this->raw()` (trusted HTML) per field semantics. Pre-escaping at compile time would stack with the component template's escaping into double escaping (`&amp;lt;`). Among the built-ins, `card.title`/`button.text`/`alert.text`/`badge.text` go through `e()`, while `card.body` uses `raw()`.

### 6.9 el

```xml
<el tag="div" x-data="{ open: false }" class="panel">
  <heading level="3">{{ user.name }}</heading>
  <text>正文</text>
</el>
```

The `tag` attribute is required (lowercase HTML tag name); children form the node tree; any forwarded attribute and `<attr>` are accepted. This is the only way to give a home to attributes like `x-data` — `text`/`if`/`each` emit no tag themselves. An empty child body is legal and compiles to `<div></div>`.

### 6.10 attr

See §4.4. Appears only as a child of a node that emits a tag, for writing attributes whose names XML cannot spell (mainly leading `@`).

## 7. Component Mechanism

Built-in and custom components share the same mechanism: both are migears/template component template files, invoked at runtime by `$this->component('name', $data)`.

**Built-in components** (shipped with the package, template files in `components/`):

- `card` — card: `title`, `body`
- `button` — button: `text`, `href` (optional; renders `<button>` without href), `type` (default `default`, optional `primary`)
- `alert` — alert bar: `type` (`info`/`success`/`warning`/`danger`, default `info`), `text`
- `badge` — badge: `text`, `type` (a field with the same name as alert's, default `default`; its value is spliced verbatim into the class, no enum validation)

The built-in component files are ordinary miGears/template components (`$this->e()` output) that users can read and copy-adapt directly.

**Custom components**: users write PHP template files themselves per migears/template's component spec — `.php` is the native form, `.tpl.php` is the `## ##` sugar syntax (`## $expr ##` escaped, `### $expr ###` verbatim, and it goes through TemplateCompiler to drop a compilation cache; `.tpl.php` wins over a same-named `.php`) — e.g. `components/my-card.php`, referenced in XML as `<component name="my-card"/>`. There is no registration; `name` is the template name. This package ships one runnable custom component example, `examples/components/my-card.php`, referenced by name from `examples/full-featured.page.xml`.

Runtime assembly: the page template must be able to find the component files. The README explains adding the package's `components/` directory to the template search path via `$tpl->addPath()`, or copying it into your project's template directory. The resolution rules are decided by migears/template's `findTemplate()`: it first tries `<path>/<name>.tpl.php`, then `<path>/<name>.php` (the `.tpl.php` pass must run through all paths before `.php`, so sugar-syntax files win), paths are searched in reverse order of `addPath()`, with later-added directories hit first — so a same-named file can override a built-in component (theme override); `name` can be a subdirectory path (`admin/table` resolves `<path>/admin/table.php`); a missing file is not an error at compile time and only throws `Component not found` at render time.

## 8. CLI

Entry point `bin/xml-pages` (a PHP shebang script):

```
php bin/xml-pages compile <input> [output-dir] [--check]
php bin/xml-pages --help
```

| Argument | Meaning |
|------|------|
| `compile` | sub-command. `<input>` is a `.page.xml` file or a directory; a directory is processed recursively for all `.page.xml` files |
| `[output-dir]` | optional. Defaults to the same directory as the source (in-place generation); when specified, outputs there keeping the same name |
| `--check` | validate only, write nothing |
| `--help` | usage info (standard help, no extra sub-command) |

Behavior conventions:

- Output file name: `users.page.xml` → `users.tpl.php`
- Existing artifacts are overwritten unconditionally (derived-file semantics)
- When processing a directory, reports per file `compiled: <source> -> <target>`; a failure does not interrupt the other files
- Exit code: 0 if all succeed; 1 if any fails
- An unrecognised `-`/`--option` is an error: it never falls through to the positional arguments, where a mistyped `--check` would silently become the output directory and turn a dry run into a real write
- An incomplete installation is reported before any file is read, so the message appears once instead of once per page: a `Compiler` class that cannot be autoloaded (a checkout where `composer install` never ran) and a missing `ext-simplexml` / `ext-dom` each write one line to stderr and exit 1
- `--help` is answered before those checks, so help still works in an installation that cannot compile anything
- Any `Error` raised inside the compiler is caught at the top level and reported as `fatal: <message>` with exit code 1, keeping the exit-code contract instead of PHP's uncaught-fatal 255

## 9. Error Handling

All errors throw `CompileException` (extends `\RuntimeException`); after the CLI catches it, it is printed to stderr, in the format:

```
views/pages/users.page.xml: sections.content[2]: 未知节点类型 "foo"
```

Error categories and their messages:

| Category | Detection | Example |
|------|------|------|
| XML syntax error | `simplexml_load_string` fails + libxml error message; includes a fix hint when the source contains `@attr`; an empty document is the one failure libxml does not report, so there the message stands without a parser part | XML 语法错误: error parsing attribute name；…请改用 __click |
| Root element error | root element is not `<page>` | XML 根元素必须是 <page> |
| Structure error | top-level rule violated, section missing name, option missing value | 同时指定 layout 与 body |
| Template name error | `layout` / component `name` is not a relative name inside the view roots — the shared compiler's rule | page: layout "../outside" must be a template name relative to the views root; empty, "." and ".." segments are not allowed |
| Duplicate definition | a section name / container element / data key / option value / `<attr>` name appears twice — all of these become keyed maps, so the repeat would collapse two entries into one | `<body> is defined more than once; a container element may only appear once` |
| Unknown node | element name not in the vocabulary | 未知节点类型 |
| Missing/invalid field | required attribute missing, enum out of range, type mismatch | if 缺 when；level 为 7 |
| Boolean attribute spelling | `required` value not in the true/false vocabulary nor the two "present" spellings | required 的值 "maybe" 不是布尔；真值可用 true / 1 / yes / on / required / 空值，假值可用 false / 0 / no / off |
| Integer attribute spelling | `level` on `<heading>` / `rows` on `<field>` is not a decimal integer — each attribute is converted where it is legal, so an element that cannot carry one reports the unknown attribute instead | level 的值 "two" 不是整数；请写十进制数字（如 2） |
| Path error | interpolation/path grammar mismatch | 非法路径 "user..name" |
| Context error | e.g. pop/content mutually exclusive | column 同时含 pop 与 content；pop 未引用行变量 |
| Literal error | `{{ }}` written in a literal field | "empty" 是字面量字段，不支持 {{ }} 插值 |
| Template-layer marker | `##` appears inside a literal field (`label` / `name` / `tag` / `empty` / option etc.) — these fields are written into the output verbatim with no place to escape | body[0].fields[0]: "label" 是字面量，不允许出现 "##"（模板层语法） |
| Nested-structure type error | field/column type does not match the element name | type 必须是 "field" |
| Unknown attribute | attribute is neither the node's DSL field nor in the passthrough whitelist; namespaced names such as `xml:lang` are read through DOM and land here too | 未知属性 "levl" |
| Hyphenated directive name | `x-on-*` / `x-bind-*` / `x-transition-*` (Alpine has only the colon form) | 请写 "x-on:click" 或 "__click" |
| Attribute has no mount point | forwarded attribute or `<attr>` on a node that emits no tag | 节点 <text> 不输出标签，请改用 <el tag="..."> 包裹内容 |
| Unknown/out-of-range child | a container has an unlisted child element (`fields` / `columns` / `options` / `sections` / `then` / `else` / `body` / `data`), or a leaf node has a nested tag | 不允许的子元素 <sectoin>（可用: section） |
| Brace disorder | interpolation contains `{{{` or `}}}` | 插值符号不能连续三个花括号 |
| Bare text in a container | text or CDATA written directly inside a container (`body`/`then`/`else`/`content`/`section`/`el`/`sections`/`fields`/`columns`/`options`/`data`) | 不能直接写文本或 CDATA（会被丢弃），请用 <text> 包裹 |
| `<attr>` with child content | `<attr>` has children or text | `<attr>` 只接受 name / value 属性，不能带子内容 |
| `<attr>` misuse | missing name/value, duplicate with a same-named attribute, placed under a container | `<attr>` 只能作为会输出标签的节点的子元素 |
| Wrapper element attribute | an attribute on a wrapper element (`sections` / `body` / `then` / `else` / `fields` / `columns` / `data` / `options` / `content`) beyond its whitelisted spelling (`section.name`, `option.value`, `attr.name` / `attr.value`) | unknown attribute "class" on <section> |
| Data value is not text | a `<data>` key whose value contains child elements | "title" has child elements that would be dropped |
| Illegal `<attr>` name | `<attr name>` contains whitespace, quotes, `<`, `>`, `/` or `=` | `<attr name="a b"> is not a legal attribute name; it is emitted exactly as written` |

The compiler maintains a path from the root to each node (e.g. `sections.content[2]`), and every error carries the path. Indices in a path are always positional (`body[0]`, `fields[0]`, `columns[0]`, `sections[0]`, `options[0]`), not element names — SimpleXML gives element names as keys when iterating repeated children, and the frontend normalizes uniformly to positional indices via `childList()`. When an XML syntax error cannot be located to a node, the parser message plus the file path is output.

The shared layer's list and type guards (`requireList()`'s list-shape check, `required` boolean, `option` text, `layout` / `title` string, etc.) are unreachable from the XML side: at frontend parse time attributes are always strings and normalized as needed (`level` / `rows` to integers, `required` to boolean), and container children are necessarily built into lists. These guards are the defense the three frontends share by using the same compilation contract; for the array DSL and the YAML frontend they are reachable paths.

Fail-fast: the first error throws, and the CLI continues processing the remaining files in the directory.

**An incomplete installation is reported rather than crashed into.** `Compiler::parse()` checks `function_exists('simplexml_load_string')` and `function_exists('dom_import_simplexml')`, raising a `CompileException` naming the missing extension: composer only validates the `ext-*` requirements at install time, and both extensions can be compiled out — without the check the call itself raises an `Error` that a caller cannot catch by type. The CLI checks the same conditions up front, together with the Composer autoloader, so the message is printed once rather than once per file; whatever still escapes is caught as `fatal: <message>` and reported with exit code 1.

## 10. Module Structure

```
migears-xml-pages/
├── composer.json            name: migears/xml-pages; require: php ^8.1, ext-dom, ext-simplexml, migears/pages ^2.0
├── README.md                bilingual (Chinese/English), architecture, install, quick start, XML reference, error handling, testing notes
├── LICENSE
├── bin/
│   └── xml-pages            CLI entry point
├── src/
│   ├── Compiler.php         XML parsing layer (XML → array IR, ~500 lines), extends migears/pages' shared compiler
│   └── Exception/
│       └── CompileException.php
├── components/              built-in component templates
│   ├── card.php
│   ├── button.php
│   ├── alert.php
│   └── badge.php
├── examples/                full-featured examples (compilable and renderable)
│   ├── full-featured.page.xml   covers the entire declaration syntax
│   ├── views/layout/main.php    companion minimal layout
│   └── components/my-card.php   custom component example, referenced by name from full-featured.page.xml
└── tests/
    ├── CompilerTest.php
    ├── CliTest.php
    ├── IntegrationTest.php
    ├── BundledComponentsTest.php   cross-package copy consistency (checked on same-repo checkout, skipped on standalone install)
    └── fixtures/
        ├── pages/           .page.xml input samples
        └── views/           layouts for integration tests
```

Composer dependency notes: at runtime the actually run code is the generated template and the built-in components, both depending on migears/template; at compile time the shared compiler of migears/pages is used, so it is set as `require` (the pages package itself declares migears/template). The parsing layer uses PHP's built-in SimpleXML (libxml), no composer third-party packages.

Copy notes: `components/*.php` and `bin/xml-pages` are byte-identical to the sibling frontend `migears/yaml-pages` (the four built-in components are byte-level identical). This is a deliberately accepted cost — components must ship with the package to be found by `addPath`, and each CLI depends on its own parsing extension — but changing one place (e.g. badge's default `type`) requires syncing the other, and both sides' component lists and tests must be checked together. `tests/BundledComponentsTest.php` turns this constraint into an executable check: on same-repo checkout it compares the component list and content byte-for-byte, and skips when installed standalone (sibling package absent).

## 11. Test Plan (TDD)

Unit tests are driven by XML strings/fixtures: input a `.page.xml`, assert the compiled artifact is exactly identical to the expected `.tpl.php` (or contains the specified fragments).

Regression tests of the shared compilation layer (node grammar, interpolation, passthrough, the base behavior of validation) are carried by migears/pages' CompilerTest; this package's tests focus on XML parsing and the overall behavior after inheritance.

| Group | Cases |
|------|------|
| text | text plain / single interpolation / multiple interpolations / multi-line (`&#10;`) |
| structure | heading at all levels, out-of-range level errors; link href/text interpolation; a template name (`layout` / component `name`) cannot climb out of the view roots |
| conditionals | if then / if then+else / `!` negation / missing when errors |
| loops | each basic / index / nested / missing items errors |
| forms | each input enum / select options / checkbox checked / submit / invalid enum / select without options / options on an unsupported input / option missing value errors |
| boolean attributes | required truthy and falsy vocabularies (case-insensitive), `required=""` and `required="required"` count as true, unknown spelling errors out and lists the usable values |
| integer attributes | level / rows decimal forms work, non-numeric reports a syntax error, out-of-range still reports a range error from the shared layer |
| path indices | error paths for fields / columns / sections / options use positional indices (`fields[0]`, not `fields[field]`) |
| tables | pop column (`{{ row.x }}`) / content column / empty / as default and custom / pop+content together errors / missing columns errors |
| layout | layout+sections / standalone body / both together errors / both missing errors / title section / section missing name errors |
| components | no data / data interpolation (PHP-context concatenation) / data literal |
| binding | path grammar boundaries (invalid characters, empty segments, `!` only allowed on when) |
| negation boundary | `each.items` with `!` errors (`!` belongs only to `if.when`) |
| nested structures | `<field type="field">` passes, `<field type="column">` errors |
| literals | `{{ }}` in literal fields like `label`, `empty`, `<option>` errors |
| template-layer marker | `##` in text is escaped per template-layer syntax (artifact contains `\##`); a single `#` needs no escaping (shared layer, reachable from the frontend too) |
| parsing | XML syntax errors error out, root not `<page>` errors out, an empty document reports the error with no parser part to append |
| passthrough | Alpine / Vue / htmx / Livewire / Stimulus directives plus `class`/`id`/`style` forwarded; value escaping; interpolation inside values; single quotes stay readable |
| `__event` | `__click` → `@click`; with modifiers (`__keydown.escape.window`); errors on a tag-less node; duplicate with `<attr name="@click">` errors |
| hyphen interception | `x-on-click` / `x-bind-href` / `x-transition-enter` error out and give the colon-form suggestion; colon-less directives (`x-show`/`x-data`) unaffected |
| passthrough misuse | unknown attributes error; a tag-less node (`text`/`if`/`each`/`component`) carrying attributes errors; unknown page-root attribute errors; an attribute belonging to another element (`rows` / `required` on a non-field) reports the unknown attribute rather than an integer or boolean problem |
| el | with children / empty children / missing tag errors / invalid tag errors |
| attr | `@click` and similar shorthands reachable; missing name/value errors; same-name duplicate errors; placed under a container errors |
| child-element validation | unknown page-root child errors; leaf node with nested tag errors; misspelled container child errors (`<colum>` in `columns`, `<sectoin>` in `sections`, extra subtree in `if`, extra subtree in `each`, extra subtree in `component`) |
| interpolation markers | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` error; adjacent `{{ a }}{{ b }}` still passes |
| bare text in a container | bare text and CDATA in `el` / `then` / `else` / `body` / `content` / `section` / `sections` / `fields` / `columns` / `data` all error; indentation whitespace and leaf-node text unaffected |
| parsing hints | failed parse from `@click` attaches a `__click` hint; email addresses in text do not trigger the hint |
| escaping contract | component data interpolation is escaped exactly once (render cascade test, asserts no `&amp;lt;`) |
| CLI | single-file compile / directory recursion / output-dir / --check / --help / unknown option rejected / failure exit code |
| Installation | missing Composer autoloader / missing `ext-simplexml` / missing `ext-dom` / an unexpected `Error`: one stderr line, exit code 1, no stack trace; `--help` still answers |
| integration | the compiled artifact renders successfully after TemplateCompiler's second compilation (tested against migears/template) |
| copy consistency | built-in components byte-identical to `migears/yaml-pages` (checked on same-repo checkout, skipped on standalone install) |

## 12. Explicitly Out of Scope (Future Candidates)

- Event handling, state management, routing — never entered
- Custom components defined inside XML (components exist only as PHP template files)
- Expression-language extensions (arithmetic, functions, ternaries)
- Runtime XML parsing / hot reload
- XML Schema / DTD validation documents
- HTML form controls beyond `input` (file upload, date pickers, etc.)

---
# migears/xml-pages 模块规格说明

版本：2.0.0（草案，待评审）
日期：2026-09-20

## 1. 定位

xml-pages 是 miGears 框架的可选配套模块：一种基于 XML 的声明式页面定义工具，把页面声明编译为 migears/template 的模板文件（`.tpl.php` 语法）。它不是核心组件，不承担运行期职责，只做编译期的"声明 → 模板"翻译。

它与 `migears/yaml-pages` 是**同一 DSL 的两种输入格式**：节点模型（body / sections / field / column / component data）与编译产物完全一致，仅解析层不同。两者同时存在，由用户二选一。

两者都是 `migears/pages` 的**语法前端**：把自己的格式解析成数组 IR 后，节点编译、校验、插值、属性透传全部由 pages 包的共享编译器完成（IR 契约见 migears/pages 的 spec.md）。本包只保留 XML 解析层与少量拼写钩子。

它解决三个问题：

1. **AI 生成准确率**——结构化的 XML 声明比混合 HTML/PHP 的模板代码更容易被大模型无差错地生成。
2. **页面结构可读**——页面长什么样、绑定了哪些数据，扫一眼 XML 就清楚，非开发者也能参与。
3. **拓展而非替代**——migears/template 本身极简、能力有限；xml-pages 用一层声明式抽象把常用页面形态（列表、表单、条件、循环）固定下来，让业务开发聚焦在数据与结构上。

## 2. 边界

### 2.1 范围内

- 页面结构定义（节点树）
- 数据绑定（`{{ path }}` 插值）
- 条件显示（`if`）
- 循环列表（`each`、`table`）
- 表单字段（`form` + `field`）
- 表格列定义（`table` + `column`）
- layout 继承（`layout` + `sections`）
- 内置组件 + 自定义组件引用
- 属性透传（前端框架指令与 `class` / `id` / `style` 原样输出到标签）
- 通用元素节点 `el`（承载 `x-data` 之类的属性容器）

### 2.2 范围外（明确不做）

- 业务逻辑、事件处理、状态管理、路由定义——一律不进 XML；这些由前端框架承担
- 运行期解析 XML——编译是唯一入口，运行期只依赖生成的模板
- composer 第三方依赖——解析层用 PHP 内置的 SimpleXML（libxml），零外部依赖
- XML Schema / DTD 校验——不做 schema 文档，结构校验全部在编译期完成

## 3. 核心原则

### 3.1 XML 是唯一事实标准

页面的一切修改都回到 XML 完成。生成的 `.tpl.php` 是**派生文件**，可随时被重新编译覆盖，不应被手工修改。工作流固定为：改 XML → 运行编译 → 渲染。

### 3.2 刻意两次编译

第一次编译：xml-pages 把 XML 声明解析为 `migears/pages` 的数组 IR，由共享编译器翻译为 `.tpl.php` 糖语法模板。这一步保留产物可读性——每个 DSL 词汇对应什么模板语法一目了然，开发者通过读产物理解声明语义、掌控生成代码。

第二次编译：migears/template 的 `TemplateCompiler` 把 `.tpl.php` 编译成纯 PHP 模板文件（mtime 缓存，仅模板变更后重编一次）。渲染由 PHP 执行：模板运行时把变量以 HTML 形式输出给浏览器，声明层不进入运行期。

两次编译各有意义，不合并、不省略。

### 3.3 极轻量

实现规模保持在千行量级（本包解析层约 500 行——编译逻辑全部在 migears/pages 共享层约 1050 行；CLI 约 110 行，组件为纯模板 PHP 文件）。任何让实现显著膨胀的特性都拒绝。

### 3.4 编译即校验

编译期对结构、字段、路径、属性做完整校验，**不静默丢弃**：未知属性、未知子元素、拼错的容器子元素一律报错。凡是 XML 表达不了模板能力的场景，编译期直接报错，不在 XML 侧发明变通语法——唯一例外是 §4.4 的 `<attr>`，它解决的是 XML 语言本身的限制（`@` 不能出现在属性名里），而非模板能力。

## 4. 声明格式

文件扩展名 `.page.xml`，编译产物同名 `.tpl.php`（如 `users.page.xml` → `users.tpl.php`）。

根元素必须是 `<page>`，其属性即顶层字段，子元素即内容：

```xml
<page title="用户管理" layout="layout/admin">
  <sections>
    <section name="title">...</section>
    <section name="content">...</section>
  </sections>
</page>
```

### 4.1 顶层字段

| 字段 | 形式 | 必填 | 说明 |
|------|------|------|------|
| `title` | `<page>` 属性 | 否 | 页面标题，写入 `<title>` section |
| `layout` | `<page>` 属性 | 否 | 继承的布局模板名（如 `layout/admin`） |
| `body` | `<page>` 子元素 | 视情况 | 无 `layout` 时的页面主体节点树 |
| `sections` | `<page>` 子元素 | 视情况 | 含若干 `<section name="...">`，与 `layout` 搭配 |

规则：`layout` 存在时 `sections` 必填、`body` 禁用；`layout` 不存在时 `body` 必填、`sections` 禁用。违反即编译错误。

`<section>` 必须有 `name` 属性，其子元素即节点树数组。`title` 属性存在时自动生成一个 `title` section（仅在有 `layout` 时生效，无 layout 时忽略并告警）。

section 的 `name` 在页面内必须唯一：section 表以名为键，重名会把两个 section 静默折成一个，因此重名是编译错误（`is defined more than once; a section name may only appear once`）。名称两侧空白会像其他文本字段一样被裁掉（残留的空格永远匹配不上它要填充的那个布局 section）；裁掉空白后为空的名称视为该属性缺失，按缺失 `name` 报同样的错。

### 4.2 XML 编写注意

解析层是 libxml（PHP 内置 SimpleXML）。节点模型的对应规则：

- **元素名即节点类型**：`<text>`、`<heading>`、`<link>`、`<if>`、`<each>`、`<form>`、`<table>`、`<el>`、`<component>`。
- **字段走属性**：如 `<heading level="2">`、`<link href="..." target="_blank">`、`<if when="...">`。
- **文本内容走元素文本**：`<text>`、`<heading>`、`<link>` 的元素文本即 `text` 字段；首尾空白会被修剪。
- **容器子元素**：`<if>` 的子元素是 `<then>`/`<else>`；`<each>` 的是 `<body>`；`<form>` 的是 `<fields>`（内含 `<field>`）；`<table>` 的是 `<columns>`（内含 `<column>`）；`<field>` 的是 `<options>`（内含 `<option value="...">`）；`<column>` 的是 `<content>`；`<component>` 的是 `<data>`（子元素名即数据键）。
- **容器子元素至多出现一次**：`<sections>` / `<body>` / `<then>` / `<else>` / `<fields>` / `<columns>` / `<data>` / `<options>` / `<content>` 按定义都是单数，而 SimpleXML 的 `isset($el->body)` 只回答第一个匹配——第二个会被丢弃、页面却照常编译成功。因此重复即编译错误（`<body> is defined more than once; a container element may only appear once`）。

转义与特殊字符：

| 场景 | 写法 |
|------|------|
| 文本中的 `<`、`>`、`&` | 实体 `&lt;` `&gt;` `&amp;` |
| 换行 | 字面换行，或实体 `&#10;` |
| 多行文本 | 直接写在元素文本中；首尾空白被修剪，内部空白原样保留 |
| 需要原样输出的 HTML 片段 | `<text><![CDATA[<strong>粗体</strong>]]></text>` |
| 无值属性（`x-cloak`） | 必须写成 `x-cloak=""`——XML 不允许没有值的属性 |

其他注意：

- 属性值用双引号包裹；值内含双引号时可用 `&quot;` 或单引号包裹属性值（`href='/x'`）。
- `{{ path }}` 插值在 XML 中**无需转义**——`{`、`}` 不是 XML 特殊字符，这是相对 YAML 的天然优势。
- `required` 按 HTML 布尔属性语义解析：`"true"`/`"1"`/`"yes"`/`"on"`（大小写不敏感）为真，`"false"`/`"0"`/`"no"`/`"off"` 为假，`required=""` 与 `required="required"` 均视为「存在」（HTML 的两种 present 写法）。其余拼写一律编译错误——静默判假会把属性悄悄丢掉，属本模块明令禁止的静默丢弃。
- `level`、`rows` 解析为十进制整数；非数字值（如 `level="two"`）属编译错误，范围校验（如 `level` 为 1–6）仍由共享编译器负责。
- 叶子节点（`text`/`heading`/`link`）内只允许 `<attr>` 子元素：嵌套任何其他标签都是编译错误，而不是静默丢掉标签、只留拼接文本；需要 HTML 时用 CDATA。
- 未知属性、未知子元素、拼错的容器子元素（如 `<colum>`）**一律编译错误**，不静默丢弃；节点类型写错报"未知节点类型"。详见 §4.3 与 §9。
- 容器只接受**子元素**。直接写在容器里的文本或 CDATA 够不到节点模型，因此是编译错误——请用 `<text>` 包裹；需要原样 HTML 时写 `<text><![CDATA[...]]></text>`。缩进产生的空白不算。

### 4.3 属性透传

节点上的属性分三类处理：

1. **DSL 字段**——该节点类型自己消费的字段（如 `heading.level`、`link.href`、`form.action`）。
2. **透传属性**——原样输出到该节点生成的标签上。白名单：
   - `__event`——`@event` 的可读写法（见下）
   - 带冒号的框架指令名：`x-on:click`、`x-bind:href`、`v-on:click`、`wire:click`、`on:click`、`:href` 等
   - 前缀：`x-`、`v-`、`hx-`、`data-`
   - 常用 HTML 钩子：`class`、`id`、`style`，以及 `bind`（前端框架的绑定属性，值是浏览器端变量名）
3. **其余一律编译错误**——未知属性视为拼写错误，绝不静默丢弃（旧行为会静默吞掉指令，是最危险的失败模式）。

不输出标签的节点（`text`、`if`、`each`、`component`）不接受透传属性，需用 `el` 包裹。

**包装元素**（`sections` / `body` / `then` / `else` / `fields` / `columns` / `data` / `options` / `content`）同样不输出标签，属性没有可挂载之处，因此**不接受任何属性**；在它们上面写属性即编译错误（`unknown attribute "class" on <section>`）。只有少数拼写确有含义，按元素逐个白名单化：`<section>` 只接受 `name`，`<option>` 只接受 `value`，`<attr>` 只接受 `name` / `value`。其余一律按拼写错误处理——包装元素上的属性此前会无声消失。

透传属性的值先做 HTML 属性转义（`ENT_COMPAT`，保留单引号可读性），再做 `{{ }}` 插值——顺序不能反，否则 `## ##` 糖语法里的引号会被转义破坏。

**`__event` → `@event`**：XML 的属性名不允许 `@`（不是合法 NameStartChar），因此无法直接书写 `@click`。属性名以 `__` 开头的按位置映射——`__` 换 `@`，其余照抄：

| 写 | 编译为 |
|----|--------|
| `__click="open = ! open"` | `@click="open = ! open"` |
| `__keydown.escape.window="close()"` | `@keydown.escape.window="close()"` |

映射是**位置性**的（`@` 永远在首位），所以不存在切分歧义。相比之下 `x-on-click` 这类连字符写法无法可靠还原——`x-on-keydown-enter` 不知道该切成 `x-on:keydown-enter` 还是 `x-on-keydown:enter`，因此**不**做这种猜测，而是直接报错（见下）。

`@` 是 `x-on:` 的简写，所以 `__event` 与 `x-on:event` 完全等价；`:` / `x-bind:` / `wire:` / `hx-` 等本就能正常书写，无需补偿。`__` 前缀一旦使用即被占用，项目自己的 `__xxx` 透传属性请改用 `<attr>`（见 §4.4）。

**连字符形式的定向报错**：`x-on-*`、`x-bind-*`、`x-transition-*` 在 Alpine 中不存在（Alpine 一律用冒号）。由于 `x-` 前缀本会放行，这类拼写会被静默透传、编译成功而指令失效——因此单独拦截并给出建议：

```
body[0]: 未知属性 "x-on-click"；Alpine 的事件/绑定指令用冒号形式，请写 "x-on:click" 或 "__click"
```

XML 在属性名上比 HTML 严格：`:` 属于保留的命名空间分隔符，libxml 会警告但仍保留属性，所以上表中的冒号形式可用；真正写不出来的只有 `@` 开头。

属性改为经 DOM 读取，而非 SimpleXML 的 `attributes()`（它只返回不带前缀的名字），因此 `xml:lang` 这类带命名空间前缀的属性不再被静默忽略：在包装元素上按未知属性拒绝，在会产出标签的节点上则按上文「名字含冒号即透传」的同一规则原样输出。

### 4.4 `<attr>` 显式属性

需要书写 `__` 映射覆盖不到的名字（或任意合法 HTML 属性名）时，用 `<attr>` 子节点——属性名作为一个**值**传入，不受 XML 名称规则约束：

```xml
<el tag="button" class="btn">
  <attr name="@click" value="open = ! open"/>
  <attr name=":class" value="open &amp;&amp; 'on'"/>
  <attr name="__raw" value="literal"/>
  切换
</el>
```

编译为：

```php
<button class="btn" @click="open = ! open" :class="open &amp;&amp; 'on'" __raw="literal">
切换
</button>
```

| 约束 | 说明 |
|------|------|
| 可用位置 | 会输出标签的节点的子元素：`el` / `heading` / `link` / `form` / `table` / `field` / `column` |
| `name` | 必填，任意合法 HTML 属性名（含 `@`），**原样输出、不做 `__` 映射** |
| `name` 合法性 | 名字原样输出，因此不得含空白、引号、`<`、`>`、`/`、`=`；其余写法即编译错误（`is not a legal attribute name`） |
| `value` | 必填，支持 `{{ }}` 插值 |
| 重复 | 与已有的同名属性冲突即编译错误 |
| 撞字段 | 与节点的 DSL 字段同名即编译错误（如 `<link>` 上写 `<attr name="href">`） |
| 误用 | 作为容器（`body`/`then`/`content` 等）的直接子元素即编译错误 |

`<attr>` 的 name 不做映射，因此它既是 `@click` 的规范显式写法，也是需要字面 `__xxx` 属性时的出口。顺手能写标准形式时优先直接写属性（`x-on:click` 或 `__click`）。

## 5. 数据绑定语法

### 5.1 路径表达式

路径是数据绑定的唯一载体，文法严格：

```
path   := segment ( "." segment )*
segment := [A-Za-z_][A-Za-z0-9_]*
```

首段即变量名，后续段为数组键访问。示例：

| 路径 | 编译为 |
|------|--------|
| `users` | `$users` |
| `user.name` | `$user['name']` |
| `form.errors.email` | `$form['errors']['email']` |

编译后的访问统一带 `?? ''`（文本/属性上下文）或 `?? null`（条件/循环上下文）兜底，避免未定义键告警。

### 5.2 插值 `{{ path }}`

文本与属性值中支持 `{{ path }}` 插值，编译为**自动转义**输出：

```xml
<text>你好，{{ user.name }}</text>
```

编译为：

```php
你好，## $user['name'] ?? '' ##
```

`## ##` 由 TemplateCompiler 编译为 `<?= $this->e($user['name'] ?? '') ?>`，XSS 防护由模板引擎承担。

插值只出现在两种上下文，编译方式不同：

| 上下文 | 编译方式 | 示例 |
|--------|----------|------|
| HTML 文本 / 属性（text、heading、link 等） | 原样保留 `## expr ##` 糖 | `href="/users/## $user['id'] ?? '' ##"` |
| PHP 数组字面量（component 的 `data`） | 字符串拼接 `'...' . ($expr) . '...'`，**不预转义** | `'title' => '编辑 ' . ($user['name'] ?? '')` |

PHP 上下文绝不能输出 `## ##` 糖——它会被 TemplateCompiler 二次替换进 PHP 字符串字面量，造成语法错误。

插值只在这两种上下文生效。其余字段是**字面量字段**：`layout`、section 名、`form.method`、`field.name`、`field.label`、`<option>` 的 value 与显示文本、`empty`、`column.label`、`component.name`。这些字段原样输出，在其中写 `{{ }}` 不生效，属编译错误（不再静默忽略）。

### 5.3 非法路径与插值符号

任何 `{{ ... }}` 内不符合路径文法的内容（函数调用、算术、字符串字面量、嵌套插值）都是编译错误，带节点路径上报。

插值符号最多两个花括号：出现 `{{{` 或 `}}}` 即编译错误。三个花括号会骗过配对计数（`{{{ a }}}` 里 `{{` 与 `}}` 各一个，看起来配对成功），正则只匹配到内层 `{{ a }}`，剩下的花括号原样留在产物里，页面就会显示错乱的 `{` `}`。

### 5.4 数据形态约束

路径编译为数组访问（`$user['name']`）。页面数据约定为**数组形态**，由控制器在边界处归一化（Domain 实体转为数组）。这是文档化约束，不在本模块内做对象兼容。

## 6. 节点词表

body/sections 中的每个元素都是一个节点，**元素名即类型**。共 9 种节点 + 2 种内嵌结构；内嵌结构（`<field>`、`<column>`）同样由元素名决定类型，不必写 `type` 属性，若写出则必须与元素名一致（两版节点模型因此完全一致）：

| 节点 | 用途 |
|------|------|
| `<text>` | 文本，支持插值 |
| `<heading>` | 标题 |
| `<link>` | 链接 |
| `<if>` | 条件显示 |
| `<each>` | 循环列表 |
| `<form>` + `<field>` | 表单及其字段 |
| `<table>` + `<column>` | 表格及其列 |
| `<el>` | 通用元素容器，承载属性与子节点树 |
| `<component>` | 引用内置或自定义组件 |

### 6.1 text

```xml
<text>你好，{{ user.name }}</text>
```

元素文本即 `text` 值，原样输出（字面部分由作者控制，可含 HTML）。插值自动转义。多行文本允许（首尾空白修剪）。除 `<attr>` 外不接受子元素——嵌套标签是编译错误，不会静默丢标签；需要 HTML 时用 CDATA。

### 6.2 heading

```xml
<heading level="2">用户管理</heading>
```

`level` 属性取值 1–6，默认 1，越界即编译错误。编译为 `<hN>...</hN>`。

### 6.3 link

```xml
<link href="/users/{{ user.id }}/edit">编辑</link>
```

`href` 属性必填、元素文本即 `text`，均支持插值（插值自动转义，属性上下文安全）。`target` 属性可选、支持插值，取值不校验——HTML 允许 `_blank` 之外的命名目标，枚举白名单会误杀合法用法。

### 6.4 if

```xml
<if when="user.loggedIn">
  <then><text>A</text></then>
  <else><text>B</text></else>
</if>
```

`when` 属性必填，路径可带 `!` 前缀取反；`<then>` 必填；`<else>` 可选。编译为：

```php
<?php if ($user['loggedIn'] ?? null): ?>
  ...then...
<?php else: ?>
  ...else...
<?php endif ?>
```

取反形式 `when="!user.hidden"`（XML 属性中 `!` 无需转义）编译为 `<?php if (!($user['hidden'] ?? null)): ?>`。

### 6.5 each

```xml
<each items="users" as="user" index="i">
  <body>
    <text>{{ user.name }}</text>
  </body>
</each>
```

`items` 属性必填路径，`as` 默认 `item`，`index` 可选。`!` 取反只属于 `if.when`，`items` 上写 `!` 按非法路径报错。编译为：

```php
<?php foreach ($users as $i => $user): ?>
  ...body...
<?php endforeach ?>
```

嵌套 each 允许，内层 `as` 同名时按 PHP 语义自然遮蔽。

### 6.6 form + field

```xml
<form action="/users/save" method="post">
  <fields>
    <field name="name" label="姓名" input="text" value="user.name" required="true" placeholder="请输入姓名"/>
    <field name="role" label="角色" input="select">
      <options>
        <option value="admin">管理员</option>
        <option value="user">普通用户</option>
      </options>
    </field>
    <field name="bio" label="简介" input="textarea" rows="4" value="user.bio"/>
    <field name="active" label="启用" input="checkbox" checked="user.active"/>
    <field name="submit" label="保存" input="submit"/>
  </fields>
</form>
```

**form**：`action` 属性必填，`method` 默认 `post`，`<fields>` 必填。

**field** 字段：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 字段名（`name` / `id` 属性） |
| `label` | string | 是 | 标签文本；`submit` 类型时为按钮文字 |
| `input` | enum | 否 | 见下，默认 `text` |
| `value` | path | 否 | 绑定值，编译为 `value="## $path ?? '' ##"`；不支持 `submit`（按钮文字用 `label`） |
| `required` | bool | 否 | 默认 false；在支持该属性的 input 上加 `required`，`hidden` / `submit` 上写 true 属编译错误 |
| `placeholder` | string | 否 | 仅 text/password/email/number；其他 input 上属编译错误 |
| `options` | 子元素 | 仅 select | `<options>` 内含 `<option value="...">` |
| `checked` | path | 仅 checkbox | 真值时输出 `checked` 属性；其他 input 上属编译错误 |
| `rows` | int | 仅 textarea | 默认 4；其他 input 上属编译错误 |

`input` 枚举：`text`、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。非法枚举即编译错误。`select` 缺 `options`、`options` 用在不支持的 input 上、`select` 上使用 `value`、`<option>` 缺 `value` 属性，均编译错误。options 以 `value` 为键，重复的 `<option value="...">` 会把两项折成一项、第一项静默消失，因此重复的 `value` 即编译错误（`an option value may only appear once`）。

字段的**使用范围**同样是硬约束，越界即编译错误（这些字段此前会被静默丢弃）：`placeholder` 仅 text/password/email/number、`checked` 仅 checkbox、`rows` 仅 textarea、`value` 不支持 submit、`required` 仅 text/password/email/number/textarea/select/checkbox。`required` 为真时在 select / textarea / checkbox 上同样输出 `required` 属性。

`<field>` 是内嵌结构：类型由元素名决定，不必写 `type` 属性；若写出，值必须是 `field`，否则编译错误。`name`、`label`、`<option>` 的 value 与文本是字面量字段，不支持 `{{ }}` 插值。

编译产物示例（节选）：

```php
<form action="/users/save" method="post">
  <label for="name">姓名</label>
  <input type="text" name="name" id="name" value="## $user['name'] ?? '' ##" required>
  <label for="role">角色</label>
  <select name="role" id="role">
    <option value="admin">管理员</option>
    <option value="user">普通用户</option>
  </select>
  <input type="submit" value="保存">
</form>
```

### 6.7 table + column

```xml
<table items="users" as="user" empty="暂无数据">
  <columns>
    <column label="ID" pop="{{ user.id }}"/>
    <column label="姓名" pop="{{ user.name }}"/>
    <column label="操作">
      <content>
        <link href="/users/{{ user.id }}/edit">编辑</link>
      </content>
    </column>
  </columns>
</table>
```

`items` 属性必填，`as` 默认 `row`，`empty` 可选（空列表提示），`<columns>` 必填。**column**：`label` 属性必填；`pop`（服务端渲染进单元格的数据引用，写成 `{{ row.id }}`）与 `<content>`（节点树，行变量作用域）二选一必填，同时提供即编译错误。`pop` 必须带 `{{ }}` 且首段等于该表格的 `as` 变量。

`<column>` 同样不必写 `type` 属性；若写出，值必须是 `column`，否则编译错误。`label` 与 `empty` 是字面量文本，不支持 `{{ }}` 插值。

编译为：

```php
<table>
<thead><tr><th>ID</th><th>姓名</th><th>操作</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="3">暂无数据</td></tr>
<?php else: ?>
<?php foreach ($users as $user): ?>
<tr>
<td>## $user['id'] ?? '' ##</td>
<td>## $user['name'] ?? '' ##</td>
<td><a href="/users/## $user['id'] ?? '' ##/edit">编辑</a></td>
</tr>
<?php endforeach ?>
<?php endif ?>
</tbody>
</table>
```

`<content>` 列的节点运行在行变量作用域内，可直接引用 `user.*`。

### 6.8 component

```xml
<component name="card">
  <data>
    <title>{{ user.name }}</title>
    <body>简介</body>
  </data>
</component>
```

`name` 属性必填，`<data>` 可选；子元素名即数据键，元素文本即值（支持插值，PHP 上下文拼接编译）。`<data>` 的值**只能是文本**——值内含子元素在此没有表示方式，因此是编译错误（`has child elements that would be dropped`），而不是被折成文本、标签无声消失。数据键同样必须唯一：映射以元素名为键，重复即编译错误（`a data key may only appear once`）。编译为：

```php
<?= $this->component('card', [
    'title' => ($user['name'] ?? ''),
    'body' => '简介',
]) ?>
```

**转义契约**：插值值以**未转义**形式传给组件，转义责任在组件模板——它按字段语义选择 `$this->e()`（文本）或 `$this->raw()`（信任的 HTML）。编译期若预先转义会与组件模板的转义叠加成双重转义（`&amp;lt;`）。内置组件中 `card.title`/`button.text`/`alert.text`/`badge.text` 走 `e()`，`card.body` 走 `raw()`。

### 6.9 el

```xml
<el tag="div" x-data="{ open: false }" class="panel">
  <heading level="3">{{ user.name }}</heading>
  <text>正文</text>
</el>
```

`tag` 属性必填（小写 HTML 标签名），子元素即节点树，接受任意透传属性与 `<attr>`。这是给 `x-data` 这类属性找一个挂载点的唯一方式——`text`/`if`/`each` 自己不输出标签。空子节点（`body`）合法，编译为 `<div></div>`。

### 6.10 attr

见 §4.4。仅作为会输出标签的节点的子元素出现，用于书写 XML 属性名拼不出来的属性（主要是 `@` 开头）。

## 7. 组件机制

内置组件与自定义组件走同一机制：都是 migears/template 的 component 模板文件，运行时由 `$this->component('name', $data)` 调用。

**内置组件**（随包分发，模板文件位于 `components/`）：

- `card` — 卡片：`title`、`body`
- `button` — 按钮：`text`、`href`（可选，无 href 时渲染 `<button>`）、`type`（默认 `default`，可选 `primary`）
- `alert` — 提示条：`type`（`info`/`success`/`warning`/`danger`，默认 `info`）、`text`
- `badge` — 标签：`text`、`type`（与 alert 同名的字段，默认 `default`；取值原样拼进 class，不做枚举校验）

内置组件文件内容为普通 miGears/template 组件（`$this->e()` 输出），用户可直接阅读、复制改造。

**自定义组件**：用户按 migears/template 的 component 规范自行编写 PHP 模板文件——`.php` 为原生写法，`.tpl.php` 为 `## ##` 糖语法（`## $expr ##` 转义、`### $expr ###` 原样输出，且会经 TemplateCompiler 落一份编译缓存；`.tpl.php` 优先于同名 `.php`）——如 `components/my-card.php`，在 XML 中 `<component name="my-card"/>` 引用。无需注册，`name` 即模板名。本包自带一个可运行的自定义组件示例 `examples/components/my-card.php`，由 `examples/full-featured.page.xml` 按名引用。

运行期组装：页面模板需能找到组件文件。README 说明通过 `$tpl->addPath()` 将包内 `components/` 目录加入模板搜索路径，或拷贝到项目模板目录。解析规则由 migears/template 的 `findTemplate()` 决定：先 `<path>/<name>.tpl.php`、再 `<path>/<name>.php`（`.tpl.php` 那一轮遍历完全部路径才轮到 `.php`，故糖语法文件优先），路径按 `addPath()` 逆序搜索、后加的目录先命中——同名文件因此可覆盖内置组件（主题覆盖）；`name` 可以是子目录路径（`admin/table` 命中 `<path>/admin/table.php`）；文件缺失编译期不报错，渲染时才抛 `Component not found`。

## 8. CLI

入口 `bin/xml-pages`（PHP shebang 脚本）：

```
php bin/xml-pages compile <input> [output-dir] [--check]
php bin/xml-pages --help
```

| 参数 | 说明 |
|------|------|
| `compile` | 子命令。`<input>` 为 `.page.xml` 文件或目录；目录则递归处理所有 `.page.xml` |
| `[output-dir]` | 可选。缺省时与源文件同目录（原位生成）；指定时输出到该目录，保持同名 |
| `--check` | 仅校验不写文件 |
| `--help` | 用法说明（标准 help，不另设子命令） |

行为约定：

- 输出文件名：`users.page.xml` → `users.tpl.php`
- 已存在的产物无条件覆盖（派生文件语义）
- 处理目录时逐文件报告 `编译: <source> → <target>`，失败不中断其他文件
- 退出码：全部成功 0；任一失败 1
- 未识别的 `-`/`--option` 一律报错：它不会落到位置参数上——否则拼错的 `--check` 会被静默当成输出目录，把干跑变成真实写盘
- 安装不完整时在任何文件被读取前报错，消息只出现一次而非每页一次：`Compiler` 无法自动加载（未跑过 `composer install` 的检出）与缺 `ext-simplexml` / `ext-dom`，各自向 stderr 写一行并退出 1
- `--help` 在上述检查之前响应，因此一个什么也编译不了的环境仍然能看帮助
- 编译器内部抛出的任何 `Error` 都在顶层捕获并报 `fatal: <消息>`、退出码 1，维持退出码约定而不是 PHP 未捕获致命的 255

## 9. 错误处理

所有错误抛 `CompileException`（继承 `\RuntimeException`），CLI 捕获后打印到 stderr，格式：

```
views/pages/users.page.xml: sections.content[2]: 未知节点类型 "foo"
```

错误分类与信息要求：

| 类别 | 检测 | 示例 |
|------|------|------|
| XML 语法错误 | `simplexml_load_string` 失败 + libxml 错误消息；源码含 `@attr` 时附修复提示；空文档是唯一 libxml 不报告的情况，此时消息不带解析器部分 | XML 语法错误: error parsing attribute name；…请改用 __click |
| 根元素错误 | 根元素不是 `<page>` | XML 根元素必须是 <page> |
| 结构错误 | 顶层规则违反、section 缺 name、option 缺 value | 同时指定 layout 与 body |
| 模板名错误 | `layout` / 组件 `name` 不是视图根内的相对名——共享编译器的规则 | page: layout "../outside" must be a template name relative to the views root; empty, "." and ".." segments are not allowed |
| 重复定义 | section 名 / 容器元素 / 数据键 / option 值 / `<attr>` 名出现两次——这些都会变成以键索引的映射，重复会把两项折成一项 | `<body> is defined more than once; a container element may only appear once` |
| 未知节点 | 元素名不在词表 | 未知节点类型 |
| 字段缺失/非法 | 必填属性缺失、枚举越界、类型不符 | if 缺 when；level 为 7 |
| 布尔属性拼写错误 | `required` 的值不在真假词表与两种「存在」写法之内 | required 的值 "maybe" 不是布尔；真值可用 true / 1 / yes / on / required / 空值，假值可用 false / 0 / no / off |
| 整数属性拼写错误 | `level` 在 `<heading>` 上、`rows` 在 `<field>` 上时不是十进制整数——每个属性都在它合法的地方转换，因此承载不了它的元素报的是未知属性 | level 的值 "two" 不是整数；请写十进制数字（如 2） |
| 路径错误 | 插值/路径文法不匹配 | 非法路径 "user..name" |
| 上下文错误 | pop/content 互斥等 | column 同时含 pop 与 content；pop 未引用行变量 |
| 字面量错误 | 字面量字段写了 `{{ }}` | "empty" 是字面量字段，不支持 {{ }} 插值 |
| 模板层标记 | 字面量字段（`label` / `name` / `tag` / `empty` / option 等）里出现 `##`——这些字段原样写入产物，没有可转义的位置 | body[0].fields[0]: "label" 是字面量，不允许出现 "##"（模板层语法） |
| 内嵌结构类型错误 | field/column 的 type 与元素名不符 | type 必须是 "field" |
| 未知属性 | 属性既非该节点的 DSL 字段，也不在透传白名单；`xml:lang` 这类带命名空间前缀的名字也经 DOM 读出、同样落在此处 | 未知属性 "levl" |
| 连字符指令名 | `x-on-*` / `x-bind-*` / `x-transition-*`（Alpine 只有冒号形式） | 请写 "x-on:click" 或 "__click" |
| 属性无挂载点 | 透传属性或 `<attr>` 出现在不输出标签的节点上 | 节点 <text> 不输出标签，请改用 <el tag="..."> 包裹内容 |
| 未知/越界子元素 | 容器出现未列出的子元素（`fields` / `columns` / `options` / `sections` / `then` / `else` / `body` / `data`）、叶子节点出现嵌套标签 | 不允许的子元素 <sectoin>（可用: section） |
| 花括号错乱 | 插值出现 `{{{` 或 `}}}` | 插值符号不能连续三个花括号 |
| 容器内裸文本 | 容器（`body`/`then`/`else`/`content`/`section`/`el`/`sections`/`fields`/`columns`/`options`/`data`）里直接写文本或 CDATA | 不能直接写文本或 CDATA（会被丢弃），请用 <text> 包裹 |
| `<attr>` 带子内容 | `<attr>` 有子元素或文本 | `<attr>` 只接受 name / value 属性，不能带子内容 |
| `<attr>` 误用 | 缺 name/value、与同名属性重复、出现在容器下 | `<attr>` 只能作为会输出标签的节点的子元素 |
| 包装元素带属性 | 包装元素（`sections` / `body` / `then` / `else` / `fields` / `columns` / `data` / `options` / `content`）上出现其白名单拼写（`section.name`、`option.value`、`attr.name` / `attr.value`）之外的属性 | unknown attribute "class" on <section> |
| data 值不是文本 | 某个 `<data>` 键的值含子元素 | "title" has child elements that would be dropped |
| `<attr>` 名非法 | `<attr name>` 含空白、引号、`<`、`>`、`/`、`=` | `<attr name="a b"> is not a legal attribute name; it is emitted exactly as written` |

编译器为每个节点维护从根到自身的路径（如 `sections.content[2]`），错误必带路径。路径中的下标一律是位置（`body[0]`、`fields[0]`、`columns[0]`、`sections[0]`、`options[0]`），不是元素名——SimpleXML 迭代重复子元素时给出的键是元素名，前端统一经 `childList()` 归一为位置索引。XML 语法错误无法定位到节点时，输出解析器消息 + 文件路径。

共享层的列表与类型守卫（`requireList()` 的列表形态判定、`required` 布尔、`option` 文本、`layout` / `title` 字符串等）在 XML 侧不可达：前端解析时属性一律是字符串并按需归一（`level` / `rows` 转整数、`required` 转布尔），容器子元素必然被构造成列表。这些守卫是三个前端共享同一份编译契约的防线，对数组 DSL 与 YAML 前端则是可达路径。

失败即中止（fail-fast）：首个错误抛出，CLI 继续处理目录内其余文件。

**安装不完整是报错，不是撞上致命错误。** `Compiler::parse()` 检查 `function_exists('simplexml_load_string')` 与 `function_exists('dom_import_simplexml')`，缺失时抛出点名对应扩展的 `CompileException`：composer 只在安装期校验 `ext-*`，而这两个扩展都可能被裁剪掉——没有这道检查，调用本身就会抛出调用方无法按类型捕获的 `Error`。CLI 再把同一条件连同 Composer autoloader 一起前置检查，使消息只打印一次而非每文件一次；其余仍然逃逸的异常统一按 `fatal: <消息>` 捕获并以退出码 1 报告。

## 10. 模块结构

```
migears-xml-pages/
├── composer.json            name: migears/xml-pages; require: php ^8.1, ext-dom, ext-simplexml, migears/pages ^2.0
├── README.md                双语（中英）、架构、安装、快速开始、XML 参考、错误处理、测试说明
├── LICENSE
├── bin/
│   └── xml-pages            CLI 入口
├── src/
│   ├── Compiler.php         XML 解析层（XML → 数组 IR，约 500 行），继承 migears/pages 的共享编译器
│   └── Exception/
│       └── CompileException.php
├── components/              内置组件模板
│   ├── card.php
│   ├── button.php
│   ├── alert.php
│   └── badge.php
├── examples/                全特性示例（可编译可渲染）
│   ├── full-featured.page.xml   覆盖全部声明语法
│   ├── views/layout/main.php    配套最小布局
│   └── components/my-card.php   自定义组件示例，被 full-featured.page.xml 按名引用
└── tests/
    ├── CompilerTest.php
    ├── CliTest.php
    ├── IntegrationTest.php
    ├── BundledComponentsTest.php   跨包副本一致性（同仓检出时校验，独立安装时跳过）
    └── fixtures/
        ├── pages/           .page.xml 输入样例
        └── views/           集成测试用布局
```

composer 依赖说明：运行期实际执行的是生成的模板与内置组件，均依赖 migears/template；编译期依赖 migears/pages 的共享编译器，故设为 `require`（pages 包自身声明 migears/template）。解析层使用 PHP 内置的 SimpleXML（libxml），无 composer 第三方包。

复制说明：`components/*.php` 与 `bin/xml-pages` 与另一前端 `migears/yaml-pages` 逐字相同（四个内置组件 byte 级一致）。这是刻意接受的代价——组件必须随包分发才能被 `addPath` 找到，CLI 依赖各自的解析扩展——但改动其中一处（如 badge 的默认 type）必须同步另一处，两侧的组件清单与测试也需一起核对。`tests/BundledComponentsTest.php` 把这条约束变成可执行检查：同仓检出时逐字比对组件清单与内容，独立安装（兄弟包不存在）时跳过。

## 11. 测试计划（TDD）

单元测试以 XML 字符串/fixtures 驱动：输入 `.page.xml`，断言编译产物与期望 `.tpl.php` 完全一致（或含指定片段）。

共享编译层的回归测试（节点文法、插值、透传、校验的基类行为）由 migears/pages 的 CompilerTest 承担；本包测试聚焦 XML 解析与继承后的整体行为。

| 分组 | 用例 |
|------|------|
| 文本 | text 纯文本 / 单插值 / 多插值 / 多行（`&#10;`） |
| 结构 | heading 各级、越界 level 报错；link href/text 插值；模板名（`layout` / 组件名）不得越出视图根 |
| 条件 | if then / if then+else / `!` 取反 / when 缺失报错 |
| 循环 | each 基础 / index / 嵌套 / items 缺失报错 |
| 表单 | 各 input 枚举 / select options / checkbox checked / submit / 非法枚举 / select 缺 options / options 用在不支持的 input / option 缺 value 报错 |
| 布尔属性 | required 真值词表与假值词表（大小写不敏感）、`required=""` 与 `required="required"` 视为真、未知拼写报错并列出可用值 |
| 整数属性 | level / rows 的十进制写法可用、非数字报语法错误、越界仍由共享层报范围错误 |
| 路径下标 | fields / columns / sections / options 的错误路径使用位置下标（`fields[0]`，不是 `fields[field]`） |
| 表格 | pop 列（`{{ row.x }}`）/ content 列 / empty / as 默认与自定义 / pop+content 同存报错 / columns 缺失报错 |
| 布局 | layout+sections / body 独立 / 两者同存报错 / 双缺失报错 / title section / section 缺 name 报错 |
| 组件 | 无 data / data 插值（PHP 上下文拼接）/ data 字面量 |
| 绑定 | 路径文法边界（非法字符、空段、`!` 只允许 when） |
| 取反边界 | `each.items` 带 `!` 报错（`!` 只属于 `if.when`） |
| 内嵌结构 | `<field type="field">` 可通过，`<field type="column">` 报错 |
| 字面量 | `label`、`empty`、`<option>` 等字面量字段写 `{{ }}` 报错 |
| 模板层标记 | 文本里出现 `##` 时按模板层语法转义（产物含 `\##`）；单个 `#` 不需转义（共享层，前端侧同样可达） |
| 解析 | XML 语法错误报错、根元素非 `<page>` 报错、空文档报错且不带解析器部分 |
| 透传 | Alpine / Vue / htmx / Livewire / Stimulus 指令与 `class`/`id`/`style` 透传；值转义；值内插值；单引号保持可读 |
| `__event` | `__click` → `@click`；带修饰符（`__keydown.escape.window`）；无标签节点上报错；与 `<attr name="@click">` 重复报错 |
| 连字符拦截 | `x-on-click` / `x-bind-href` / `x-transition-enter` 报错且给出冒号形式建议；无冒号指令（`x-show`/`x-data`）不受影响 |
| 透传误用 | 未知属性报错；无标签节点（`text`/`if`/`each`/`component`）承载属性报错；页面根未知属性报错；属于别的元素的属性（`rows` / `required` 出现在非 field 上）报未知属性，而不是整数或布尔错误 |
| el | 带子节点 / 空子节点 / 缺 tag 报错 / 非法 tag 报错 |
| attr | `@click` 等简写可达；缺 name/value 报错；同名重复报错；写在容器下报错 |
| 子元素校验 | 页面根未知子元素报错；叶子节点嵌套标签报错；容器拼错子元素报错（`columns` 的 `<colum>`、`sections` 的 `<sectoin>`、`if` 的多余子树、`each` 的多余子树、`component` 的多余子树） |
| 插值符号 | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` 报错；相邻的 `{{ a }}{{ b }}` 仍放行 |
| 容器内裸文本 | `el` / `then` / `else` / `body` / `content` / `section` / `sections` / `fields` / `columns` / `data` 里的裸文本与 CDATA 一律报错；缩进空白与叶子节点文本不受影响 |
| 解析提示 | `@click` 导致解析失败时附 `__click` 提示；文本中的邮箱不触发提示 |
| 转义契约 | 组件 data 插值恰好转义一次（渲染级联测，断言无 `&amp;lt;`） |
| CLI | 单文件编译 / 目录递归 / output-dir / --check / --help / 未识别选项被拒 / 失败退出码 |
| 安装环境 | 缺 Composer autoloader / 缺 `ext-simplexml` / 缺 `ext-dom` / 未预料 `Error`：stderr 一行、退出码 1、无调用栈；`--help` 仍可响应 |
| 集成 | 编译产物经 TemplateCompiler 二次编译后渲染成功（与 migears/template 联测） |
| 副本一致性 | 内置组件与 `migears/yaml-pages` 逐字相同（同仓检出时校验，独立安装时跳过） |

## 12. 明确不做（后续候选）

- 事件处理、状态管理、路由——永不进入
- XML 内自定义组件（组件只以 PHP 模板形态存在）
- 表达式语言扩展（算术、函数、三元）
- 运行期 XML 解析 / 热更新
- XML Schema / DTD 校验文档
- 覆盖 `input` 之外的 HTML 表单控件（文件上传、日期选择等）
