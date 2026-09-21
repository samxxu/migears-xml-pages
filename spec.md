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

实现规模保持在千行量级（本包解析层约 440 行——编译逻辑全部在 migears/pages 共享层约 950 行；CLI 约 110 行，组件为纯模板 PHP 文件）。任何让实现显著膨胀的特性都拒绝。

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

### 4.2 XML 编写注意

解析层是 libxml（PHP 内置 SimpleXML）。节点模型的对应规则：

- **元素名即节点类型**：`<text>`、`<heading>`、`<link>`、`<if>`、`<each>`、`<form>`、`<table>`、`<component>`。
- **字段走属性**：如 `<heading level="2">`、`<link href="..." target="_blank">`、`<if when="...">`。
- **文本内容走元素文本**：`<text>`、`<heading>`、`<link>` 的元素文本即 `text` 字段；首尾空白会被修剪。
- **容器子元素**：`<if>` 的子元素是 `<then>`/`<else>`；`<each>` 的是 `<body>`；`<form>` 的是 `<fields>`（内含 `<field>`）；`<table>` 的是 `<columns>`（内含 `<column>`）；`<field>` 的是 `<options>`（内含 `<option value="...">`）；`<column>` 的是 `<content>`；`<component>` 的是 `<data>`（子元素名即数据键）。

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
- `required="true"` 解析为布尔；`"true"`/`"1"`/`"yes"`/`"on"`（大小写不敏感）均为真。
- `level`、`rows` 解析为整数。
- 叶子节点（`text`/`heading`/`link`）内不要嵌套子元素——嵌套元素的标签会丢失，只剩拼接后的文本；需要 HTML 时用 CDATA。
- 未知属性、未知子元素、拼错的容器子元素（如 `<colum>`）**一律编译错误**，不静默丢弃；节点类型写错报"未知节点类型"。详见 §4.3 与 §9。
- 容器只接受**子元素**。直接写在容器里的文本或 CDATA 够不到节点模型，因此是编译错误——请用 `<text>` 包裹；需要原样 HTML 时写 `<text><![CDATA[...]]></text>`。缩进产生的空白不算。

### 4.3 属性透传

节点上的属性分三类处理：

1. **DSL 字段**——该节点类型自己消费的字段（如 `heading.level`、`link.href`、`form.action`）。
2. **透传属性**——原样输出到该节点生成的标签上。白名单：
   - `__event`——`@event` 的可读写法（见下）
   - 带冒号的框架指令名：`x-on:click`、`x-bind:href`、`v-on:click`、`wire:click`、`on:click`、`:href` 等
   - 前缀：`x-`、`v-`、`hx-`、`data-`
   - 常用 HTML 钩子：`class`、`id`、`style`
3. **其余一律编译错误**——未知属性视为拼写错误，绝不静默丢弃（旧行为会静默吞掉指令，是最危险的失败模式）。

不输出标签的节点（`text`、`if`、`each`、`component`）不接受透传属性，需用 `el` 包裹。

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
| PHP 数组字面量（component 的 `data`） | 字符串拼接 `'...' . $this->e($expr) . '...'` | `'title' => '编辑 ' . $this->e($user['name'] ?? '')` |

PHP 上下文绝不能输出 `## ##` 糖——它会被 TemplateCompiler 二次替换进 PHP 字符串字面量，造成语法错误。

插值只在这两种上下文生效。其余字段是**字面量字段**：`layout`、section 名、`form.method`、`field.name`、`field.label`、`<option>` 的 value 与显示文本、`empty`、`column.label`、`component.name`。这些字段原样输出，在其中写 `{{ }}` 不生效，属编译错误（不再静默忽略）。

### 5.3 非法表达式

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

元素文本即 `text` 值，原样输出（字面部分由作者控制，可含 HTML）。插值自动转义。多行文本允许（首尾空白修剪）。嵌套子元素的标签会丢失，需要 HTML 时用 CDATA。

### 6.2 heading

```xml
<heading level="2">用户管理</heading>
```

`level` 属性取值 1–6，默认 1，越界即编译错误。编译为 `<hN>...</hN>`。

### 6.3 link

```xml
<link href="/users/{{ user.id }}/edit">编辑</link>
```

`href` 属性必填、元素文本即 `text`，均支持插值（插值自动转义，属性上下文安全）。`target` 属性可选。

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
| `value` | path | 否 | 绑定值，编译为 `value="## $path ?? '' ##"` |
| `required` | bool | 否 | 默认 false，加 `required` 属性 |
| `placeholder` | string | 否 | 仅 text/password/email/number |
| `options` | 子元素 | 仅 select | `<options>` 内含 `<option value="...">` |
| `checked` | path | 仅 checkbox | 真值时输出 `checked` 属性 |
| `rows` | int | 仅 textarea | 默认 4 |

`input` 枚举：`text`、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。非法枚举即编译错误。`select` 缺 `options`、`options` 用在不支持的 input 上、`select` 上使用 `value`、`<option>` 缺 `value` 属性，均编译错误。

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
    <column label="ID" bind="id"/>
    <column label="姓名" bind="name"/>
    <column label="操作">
      <content>
        <link href="/users/{{ user.id }}/edit">编辑</link>
      </content>
    </column>
  </columns>
</table>
```

`items` 属性必填，`as` 默认 `row`，`empty` 可选（空列表提示），`<columns>` 必填。**column**：`label` 属性必填；`bind`（相对行变量的路径）与 `<content>`（节点树，行变量作用域）二选一必填，同时提供即编译错误。

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

`name` 属性必填，`<data>` 可选；子元素名即数据键，元素文本即值（支持插值，PHP 上下文拼接编译）。编译为：

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
- `badge` — 标签：`text`、`type`（同 alert）

内置组件文件内容为普通 miGears/template 组件（`$this->e()` 输出），用户可直接阅读、复制改造。

**自定义组件**：用户按 migears/template 的 component 规范自行编写 PHP 模板文件（如 `components/my-card.php`），在 XML 中 `<component name="my-card"/>` 引用。无需注册，`name` 即模板名。

运行期组装：页面模板需能找到组件文件。README 说明通过 `$tpl->addPath()` 将包内 `components/` 目录加入模板搜索路径，或拷贝到项目模板目录。

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

## 9. 错误处理

所有错误抛 `CompileException`（继承 `\RuntimeException`），CLI 捕获后打印到 stderr，格式：

```
views/pages/users.page.xml: sections.content[2]: 未知节点类型 "foo"
```

错误分类与信息要求：

| 类别 | 检测 | 示例 |
|------|------|------|
| XML 语法错误 | `simplexml_load_string` 失败 + libxml 错误消息；源码含 `@attr` 时附修复提示 | XML 语法错误: error parsing attribute name；…请改用 __click |
| 根元素错误 | 根元素不是 `<page>` | XML 根元素必须是 <page> |
| 结构错误 | 顶层规则违反、section 缺 name、option 缺 value | 同时指定 body 与 sections |
| 未知节点 | 元素名不在词表 | 未知节点类型 |
| 字段缺失/非法 | 必填属性缺失、枚举越界、类型不符 | if 缺 when；level 为 7 |
| 路径错误 | 插值/路径文法不匹配 | 非法表达式 |
| 上下文错误 | bind/content 互斥等 | column 同时含 bind 与 content |
| 字面量错误 | 字面量字段写了 `{{ }}` | "empty" 是字面量字段，不支持 {{ }} 插值 |
| 内嵌结构类型错误 | field/column 的 type 与元素名不符 | type 必须是 "field" |
| 未知属性 | 属性既非该节点的 DSL 字段，也不在透传白名单 | 未知属性 "levl" |
| 连字符指令名 | `x-on-*` / `x-bind-*` / `x-transition-*`（Alpine 只有冒号形式） | 请写 "x-on:click" 或 "__click" |
| 属性无挂载点 | 透传属性或 `<attr>` 出现在不输出标签的节点上 | 节点 <text> 不输出标签，请改用 <el tag="..."> 包裹内容 |
| 未知/越界子元素 | 容器出现未列出的子元素（`fields` / `columns` / `options` / `sections` / `then` / `else` / `body` / `data`）、叶子节点出现嵌套标签 | 不允许的子元素 <sectoin>（可用: section） |
| 花括号错乱 | 插值出现 `{{{` 或 `}}}` | 插值符号不能连续三个花括号 |
| 容器内裸文本 | 容器（`body`/`then`/`else`/`content`/`section`/`el`/`sections`/`fields`/`columns`/`options`/`data`）里直接写文本或 CDATA | 不能直接写文本或 CDATA（会被丢弃），请用 <text> 包裹 |
| `<attr>` 带子内容 | `<attr>` 有子元素或文本 | `<attr>` 只接受 name / value 属性，不能带子内容 |
| `<attr>` 误用 | 缺 name/value、与同名属性重复、出现在容器下 | `<attr>` 只能作为会输出标签的节点的子元素 |

编译器为每个节点维护从根到自身的路径（如 `sections.content[2]`），错误必带路径。XML 语法错误无法定位到节点时，输出解析器消息 + 文件路径。

共享层的列表与类型守卫（`requireList()` 的列表形态判定、`required` 布尔、`option` 文本、`layout` / `title` 字符串等）在 XML 侧不可达：前端解析时属性一律是字符串并按需归一（`level` / `rows` 转整数、`required` 转布尔），容器子元素必然被构造成列表。这些守卫是三个前端共享同一份编译契约的防线，对数组 DSL 与 YAML 前端则是可达路径。

失败即中止（fail-fast）：首个错误抛出，CLI 继续处理目录内其余文件。

## 10. 模块结构

```
migears-xml-pages/
├── composer.json            name: migears/xml-pages; require: php >=8.1, ext-dom, ext-simplexml, migears/pages ^2.0
├── README.md                双语（中英）、架构、安装、快速开始、XML 参考、错误处理、测试说明
├── LICENSE
├── bin/
│   └── xml-pages            CLI 入口
├── src/
│   ├── Compiler.php         XML 解析层（XML → 数组 IR，约 440 行），继承 migears/pages 的共享编译器
│   └── Exception/
│       └── CompileException.php
├── components/              内置组件模板
│   ├── card.php
│   ├── button.php
│   ├── alert.php
│   └── badge.php
├── examples/                全特性示例（可编译可渲染）
│   ├── full-featured.page.xml   覆盖全部声明语法
│   └── views/layout/main.php    配套最小布局
└── tests/
    ├── CompilerTest.php
    ├── CliTest.php
    ├── IntegrationTest.php
    └── fixtures/
        ├── pages/           .page.xml 输入样例
        └── views/           集成测试用布局
```

composer 依赖说明：运行期实际执行的是生成的模板与内置组件，均依赖 migears/template；编译期依赖 migears/pages 的共享编译器，故设为 `require`（pages 包自身声明 migears/template）。解析层使用 PHP 内置的 SimpleXML（libxml），无 composer 第三方包。

## 11. 测试计划（TDD）

单元测试以 XML 字符串/fixtures 驱动：输入 `.page.xml`，断言编译产物与期望 `.tpl.php` 完全一致（或含指定片段）。

共享编译层的回归测试（节点文法、插值、透传、校验的基类行为）由 migears/pages 的 CompilerTest 承担；本包测试聚焦 XML 解析与继承后的整体行为。

| 分组 | 用例 |
|------|------|
| 文本 | text 纯文本 / 单插值 / 多插值 / 多行（`&#10;`） |
| 结构 | heading 各级、越界 level 报错；link href/text 插值；非法 target |
| 条件 | if then / if then+else / `!` 取反 / when 缺失报错 |
| 循环 | each 基础 / index / 嵌套 / items 缺失报错 |
| 表单 | 各 input 枚举 / select options / checkbox checked / submit / 非法枚举 / select 缺 options / options 用在不支持的 input / option 缺 value 报错 |
| 表格 | bind 列 / content 列 / empty / as 默认与自定义 / bind+content 同存报错 / columns 缺失报错 |
| 布局 | layout+sections / body 独立 / 两者同存报错 / 双缺失报错 / title section / section 缺 name 报错 |
| 组件 | 无 data / data 插值（PHP 上下文拼接）/ data 字面量 |
| 绑定 | 路径文法边界（非法字符、空段、`!` 只允许 when） |
| 取反边界 | `each.items` 带 `!` 报错（`!` 只属于 `if.when`） |
| 内嵌结构 | `<field type="field">` 可通过，`<field type="column">` 报错 |
| 字面量 | `label`、`empty`、`<option>` 等字面量字段写 `{{ }}` 报错 |
| 解析 | XML 语法错误报错、根元素非 `<page>` 报错 |
| 透传 | Alpine / Vue / htmx / Livewire / Stimulus 指令与 `class`/`id`/`style` 透传；值转义；值内插值；单引号保持可读 |
| `__event` | `__click` → `@click`；带修饰符（`__keydown.escape.window`）；无标签节点上报错；与 `<attr name="@click">` 重复报错 |
| 连字符拦截 | `x-on-click` / `x-bind-href` / `x-transition-enter` 报错且给出冒号形式建议；无冒号指令（`x-show`/`x-data`）不受影响 |
| 透传误用 | 未知属性报错；无标签节点（`text`/`if`/`each`/`component`）承载属性报错；页面根未知属性报错 |
| el | 带子节点 / 空子节点 / 缺 tag 报错 / 非法 tag 报错 |
| attr | `@click` 等简写可达；缺 name/value 报错；同名重复报错；写在容器下报错 |
| 子元素校验 | 页面根未知子元素报错；叶子节点嵌套标签报错；容器拼错子元素报错（`columns` 的 `<colum>`、`sections` 的 `<sectoin>`、`if` 的多余子树、`each` 的多余子树、`component` 的多余子树） |
| 插值符号 | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` 报错；相邻的 `{{ a }}{{ b }}` 仍放行 |
| 容器内裸文本 | `el` / `then` / `else` / `body` / `content` / `section` / `sections` / `fields` / `columns` / `data` 里的裸文本与 CDATA 一律报错；缩进空白与叶子节点文本不受影响 |
| 解析提示 | `@click` 导致解析失败时附 `__click` 提示；文本中的邮箱不触发提示 |
| 转义契约 | 组件 data 插值恰好转义一次（渲染级联测，断言无 `&amp;lt;`） |
| CLI | 单文件编译 / 目录递归 / output-dir / --check / --help / 失败退出码 |
| 集成 | 编译产物经 TemplateCompiler 二次编译后渲染成功（与 migears/template 联测） |

## 12. 明确不做（后续候选）

- 事件处理、状态管理、路由——永不进入
- XML 内自定义组件（组件只以 PHP 模板形态存在）
- 表达式语言扩展（算术、函数、三元）
- 运行期 XML 解析 / 热更新
- XML Schema / DTD 校验文档
- 覆盖 `input` 之外的 HTML 表单控件（文件上传、日期选择等）
