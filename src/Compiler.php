<?php

declare(strict_types=1);

namespace MiGears\XmlPages;

use MiGears\XmlPages\Exception\CompileException;
use SimpleXMLElement;

/**
 * Compiles XML page declarations into miGears Template (.tpl.php) source.
 *
 * Two-stage pipeline: XML -> .tpl.php (this class), then TemplateCompiler
 * turns the ## ## sugar into pure PHP at render time. The .tpl.php output is
 * a derived artifact — XML is the single source of truth.
 *
 * The node model (body / sections / field / column / component data) is
 * identical to the YAML variant: only the parsing layer differs. The element
 * name of a node is its type, attributes map to fields, and element text
 * content maps to the text field of leaf nodes.
 *
 * XML is stricter than HTML about attribute *names*: '@' is not a legal
 * NameStartChar, and ':' is reserved for namespaces. Framework directives that
 * avoid both spellings (x-on:click, v-bind:href, wire:click, hx-get, data-*)
 * ride through as ordinary attributes and are forwarded to the emitted tag;
 * shorthands such as @click arrive through an <attr> child instead, because
 * there '@' is an attribute value rather than a name.
 */
class Compiler
{
    private const PATH_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const INTERPOLATION = '/\{\{\s*([^{}]+?)\s*\}\}/';

    private const INPUT_TYPES = ['text', 'password', 'email', 'number', 'textarea', 'select', 'checkbox', 'hidden', 'submit'];

    private const TRUE_VALUES = ['true', '1', 'yes', 'on'];

    /**
     * Attributes the DSL does not define are forwarded verbatim only when they
     * belong to a known front-end convention or a common HTML hook. Everything
     * else is treated as a typo: silently dropping an Alpine directive the
     * author did write is the worst possible outcome, because the page still
     * compiles and the directive simply vanishes.
     */
    private const PASSTHROUGH_PREFIXES = ['x-', 'v-', 'hx-', 'data-'];

    private const PASSTHROUGH_EXACT = ['class', 'id', 'style'];

    private const TAG_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /**
     * Alpine spells these directives with a colon. The hyphen form is not an
     * Alpine attribute at all, so a bare 'x-' prefix rule would forward it
     * happily and the directive would then do nothing — the silent failure we
     * refuse. prefix => shorthands to suggest instead.
     *
     * @var array<string, list<string>>
     */
    private const COLON_ONLY_DIRECTIVES = [
        'x-on-' => ['x-on:', '__'],
        'x-bind-' => ['x-bind:', ':'],
        'x-transition-' => ['x-transition:'],
    ];

    /** @var callable|null */
    private $warn;

    public function __construct(?callable $warn = null)
    {
        $this->warn = $warn;
    }

    public function compile(string $source): string
    {
        return $this->compilePage($this->parseXml($source));
    }

    public function compileFile(string $path): string
    {
        if (! is_file($path)) {
            throw new CompileException("页面文件不存在: {$path}");
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new CompileException("无法读取页面文件: {$path}");
        }

        return $this->compile($source);
    }

    public function compileToFile(string $sourcePath, ?string $outputDir = null): string
    {
        $compiled = $this->compileFile($sourcePath);
        $dir = $outputDir ?? dirname($sourcePath);
        $target = rtrim($dir, '/\\') . '/' . basename($sourcePath, '.page.xml') . '.tpl.php';

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new CompileException("无法创建输出目录: {$dir}");
        }

        file_put_contents($target, $compiled, LOCK_EX);

        return $target;
    }

    /* ---------------------------------------------------------------- *
     * XML parsing layer: XML -> array node model
     * ---------------------------------------------------------------- */

    private function parseXml(string $source): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($source, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        if ($xml === false) {
            $msg = $errors !== [] ? trim($errors[0]->message) : '';
            throw new CompileException('XML 语法错误' . ($msg !== '' ? ': ' . $msg : '') . $this->atSignHint($source));
        }
        if ($xml->getName() !== 'page') {
            throw new CompileException('XML 根元素必须是 <page>');
        }

        return $this->pageFromElement($xml);
    }

    /**
     * '@' is not a legal XML NameStartChar, so '@click="..."' aborts the parse
     * before any DSL validation can run, and libxml only reports "error parsing
     * attribute name" — which leaves the author with no way forward. Point at
     * the spellings that do work.
     */
    private function atSignHint(string $source): string
    {
        if (! preg_match('/\s@[A-Za-z_]/', $source)) {
            return '';
        }

        return '；XML 属性名不能含 "@"：写 @click 请改用 __click（等价 x-on:click）';
    }

    private function pageFromElement(SimpleXMLElement $page): array
    {
        $out = [];
        foreach ($page->attributes() as $name => $value) {
            $name = (string) $name;
            if (! in_array($name, ['title', 'layout'], true)) {
                $this->error("page: 未知属性 \"{$name}\"，仅支持 title 与 layout（页面根不输出标签，无法承载透传属性）");
            }
            $out[$name] = (string) $value;
        }
        $this->assertChildren($page, ['body', 'sections'], 'page');

        if (isset($page->body)) {
            $out['body'] = $this->nodesFromElement($page->body, 'body');
        }
        if (isset($page->sections)) {
            $this->assertChildren($page->sections, ['section'], 'sections');
            $sections = [];
            foreach ($page->sections->section as $i => $section) {
                if (! isset($section['name'])) {
                    $this->error("sections[{$i}]: section 缺少 name 属性");
                }
                $name = (string) $section['name'];
                $sections[$name] = $this->nodesFromElement($section, 'sections.' . $name);
            }
            $out['sections'] = $sections;
        }

        return $out;
    }

    private function nodesFromElement(SimpleXMLElement $parent, string $path, bool $allowAttr = false): array
    {
        if ($this->hasBareText($parent)) {
            $this->error("{$path}: 不能直接写文本或 CDATA（会被丢弃），请用 <text> 包裹");
        }

        $nodes = [];
        $i = 0;
        foreach ($parent->children() as $child) {
            if ($child->getName() === 'attr') {
                // <attr> decorates the parent tag; it is not a content node.
                if (! $allowAttr) {
                    $this->error("{$path}: <attr> 只能作为会输出标签的节点的子元素"
                        . '（heading / link / el / form / table / field / column）');
                }
                continue;
            }
            $nodes[] = $this->nodeFromElement($child, $path . '[' . $i . ']');
            $i++;
        }

        return $nodes;
    }

    /**
     * Containers accept exactly the child element names listed here, and no bare
     * text at all. A stray child is almost always a typo (colum / feild) that
     * would otherwise leave the structure silently incomplete.
     *
     * @param list<string> $allowed
     * @param bool $allowText leaf-like nodes carry their content as text; containers do not
     */
    private function assertChildren(SimpleXMLElement $parent, array $allowed, string $path, bool $allowText = false): void
    {
        if (! $allowText && $this->hasBareText($parent)) {
            $this->error("{$path}: 不能直接写文本或 CDATA（会被丢弃），可用子元素: " . implode(' / ', $allowed));
        }

        foreach ($parent->children() as $child) {
            $name = $child->getName();
            if (! in_array($name, $allowed, true)) {
                $this->error("{$path}: 不允许的子元素 <{$name}>（可用: " . implode(' / ', $allowed) . '）');
            }
        }
    }

    /**
     * Text this element owns directly — bare text and CDATA — as opposed to the
     * text inside its descendants. SimpleXML's children() yields elements only,
     * so such text is unreachable from the node model and used to vanish without
     * a trace; this is what lets us refuse it instead. Whitespace is ignored, so
     * ordinary indentation never trips it.
     */
    private function hasBareText(SimpleXMLElement $el): bool
    {
        foreach (dom_import_simplexml($el)->childNodes as $child) {
            if (! in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                continue;
            }
            if (trim((string) $child->nodeValue) !== '') {
                return true;
            }
        }

        return false;
    }

    private function nodeFromElement(SimpleXMLElement $el, string $path): array
    {
        $type = $el->getName();

        $attrs = [];
        foreach ($el->attributes() as $name => $value) {
            $attrs[(string) $name] = (string) $value;
        }
        if (isset($attrs['type']) && $attrs['type'] !== $type) {
            $this->error("{$path}: type 必须是 \"{$type}\"（元素名已决定节点类型）");
        }

        $node = $attrs;
        $node['type'] = $type;
        $node['_attrs'] = $attrs;

        $extra = $this->attrChildren($el, $path);
        if ($extra !== []) {
            $node['_extraAttrs'] = $extra;
        }

        if ($type === 'el') {
            $node['body'] = $this->nodesFromElement($el, $path . '.body', true);
        } elseif ($type === 'if') {
            $this->assertChildren($el, ['then', 'else', 'attr'], $path);
            if (isset($el->then)) {
                $node['then'] = $this->nodesFromElement($el->then, $path . '.then');
            }
            if (isset($el->else)) {
                $node['else'] = $this->nodesFromElement($el->else, $path . '.else');
            }
        } elseif ($type === 'each') {
            $this->assertChildren($el, ['body', 'attr'], $path);
            if (isset($el->body)) {
                $node['body'] = $this->nodesFromElement($el->body, $path . '.body');
            }
        } elseif ($type === 'form') {
            $this->assertChildren($el, ['fields', 'attr'], $path);
            if (isset($el->fields)) {
                $this->assertChildren($el->fields, ['field'], $path . '.fields');
                $fields = [];
                foreach ($el->fields->field as $i => $field) {
                    $fields[] = $this->fieldFromElement($field, $path . '.fields[' . $i . ']');
                }
                $node['fields'] = $fields;
            }
        } elseif ($type === 'table') {
            $this->assertChildren($el, ['columns', 'attr'], $path);
            if (isset($el->columns)) {
                $this->assertChildren($el->columns, ['column'], $path . '.columns');
                $columns = [];
                foreach ($el->columns->column as $i => $column) {
                    $columns[] = $this->columnFromElement($column, $path . '.columns[' . $i . ']');
                }
                $node['columns'] = $columns;
            }
        } elseif ($type === 'component') {
            $this->assertChildren($el, ['data', 'attr'], $path);
            if (isset($el->data)) {
                if ($this->hasBareText($el->data)) {
                    $this->error("{$path}.data: 不能直接写文本（会被丢弃），子元素名即数据键");
                }
                $data = [];
                foreach ($el->data->children() as $key => $value) {
                    $data[(string) $key] = trim((string) $value);
                }
                $node['data'] = $data;
            }
        } else {
            // Leaf nodes (text / heading / link / unknown): element text -> text field.
            // Their text IS the content, so text is allowed; nested markup would
            // lose its tags, so only <attr> may appear as a child element.
            $this->assertChildren($el, ['attr'], $path, true);
            $node['text'] = trim((string) $el);
        }

        if (isset($node['level'])) {
            $node['level'] = (int) $node['level'];
        }
        if (isset($node['rows'])) {
            $node['rows'] = (int) $node['rows'];
        }
        if (isset($node['required'])) {
            $node['required'] = $this->toBool($node['required']);
        }

        return $node;
    }

    private function fieldFromElement(SimpleXMLElement $el, string $path): array
    {
        $attrs = [];
        foreach ($el->attributes() as $name => $value) {
            $attrs[(string) $name] = (string) $value;
        }
        if (isset($attrs['type']) && $attrs['type'] !== 'field') {
            $this->error("{$path}: type 必须是 \"field\"（元素名已决定节点类型）");
        }
        $this->assertChildren($el, ['options', 'attr'], $path);

        $field = $attrs;
        $field['type'] = 'field';
        $field['_attrs'] = $attrs;
        $extra = $this->attrChildren($el, $path);
        if ($extra !== []) {
            $field['_extraAttrs'] = $extra;
        }

        if (isset($el->options)) {
            $this->assertChildren($el->options, ['option'], $path . '.options');
            $options = [];
            foreach ($el->options->option as $i => $option) {
                if ($option->children()->count() > 0) {
                    $this->error("{$path}.options[{$i}]: <option> 只接受文本内容与 value 属性");
                }
                if (! isset($option['value'])) {
                    $this->error("{$path}.options[{$i}]: option 缺少 value 属性");
                }
                $options[(string) $option['value']] = trim((string) $option);
            }
            $field['options'] = $options;
        }
        if (isset($field['required'])) {
            $field['required'] = $this->toBool($field['required']);
        }
        if (isset($field['rows'])) {
            $field['rows'] = (int) $field['rows'];
        }

        return $field;
    }

    private function columnFromElement(SimpleXMLElement $el, string $path): array
    {
        $attrs = [];
        foreach ($el->attributes() as $name => $value) {
            $attrs[(string) $name] = (string) $value;
        }
        if (isset($attrs['type']) && $attrs['type'] !== 'column') {
            $this->error("{$path}: type 必须是 \"column\"（元素名已决定节点类型）");
        }
        $this->assertChildren($el, ['content', 'attr'], $path);

        $column = $attrs;
        $column['type'] = 'column';
        $column['_attrs'] = $attrs;
        $extra = $this->attrChildren($el, $path);
        if ($extra !== []) {
            $column['_extraAttrs'] = $extra;
        }

        if (isset($el->content)) {
            $column['content'] = $this->nodesFromElement($el->content, $path . '.content');
        }

        return $column;
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::TRUE_VALUES, true);
    }

    /**
     * Collect <attr name="..." value="..."/> children.
     *
     * XML cannot spell an attribute name containing '@' — it is not a legal
     * NameStartChar — so front-end shorthands such as Alpine's @click arrive
     * through an attribute *value* instead of an attribute name.
     *
     * @return array<string, string>
     */
    private function attrChildren(SimpleXMLElement $el, string $path): array
    {
        $extra = [];
        foreach ($el->attr as $attr) {
            if ($attr->children()->count() > 0 || $this->hasBareText($attr)) {
                $this->error("{$path}: <attr> 只接受 name / value 属性，不能带子内容");
            }
            if (! isset($attr['name'])) {
                $this->error("{$path}: <attr> 缺少 name 属性");
            }
            $name = (string) $attr['name'];
            if (! isset($attr['value'])) {
                $this->error("{$path}: <attr name=\"{$name}\"> 缺少 value 属性");
            }
            $extra[$name] = (string) $attr['value'];
        }

        return $extra;
    }

    private function isForwardable(string $name): bool
    {
        // Namespace-style framework directives: x-on:click, v-bind:href,
        // wire:click, on:click, hx-on:click. In XML the ':' looks like an
        // undeclared namespace prefix — libxml warns but keeps the attribute,
        // so it arrives intact and is forwarded unchanged.
        if (str_contains($name, ':')) {
            return true;
        }
        if (in_array($name, self::PASSTHROUGH_EXACT, true)) {
            return true;
        }
        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the name a node attribute is emitted under.
     *
     * '__click' is the readable spelling of Alpine's '@click': XML cannot put
     * '@' in an attribute name, so the shorthand arrives as a name the parser
     * can read and the compiler rewrites. The mapping is positional — '@' is
     * always the first character — so unlike guessing 'x-on-click' into
     * 'x-on:click' it can never split in the wrong place.
     */
    private function emitName(string $name, string $path): string
    {
        if (str_starts_with($name, '__') && $name !== '__') {
            return '@' . substr($name, 2);
        }

        foreach (self::COLON_ONLY_DIRECTIVES as $prefix => $forms) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            $rest = substr($name, strlen($prefix));
            $hint = implode('" 或 "', array_map(static fn (string $f): string => $f . $rest, $forms));
            $this->error("{$path}: 未知属性 \"{$name}\"；Alpine 的事件/绑定指令用冒号形式，请写 \"{$hint}\"");
        }

        if (! $this->isForwardable($name)) {
            $this->error("{$path}: 未知属性 \"{$name}\"；透传支持 __event（等价 @event）、"
                . '带冒号的指令名（x-on:click / wire:click / :href 等）、'
                . implode(' / ', self::PASSTHROUGH_PREFIXES) . ' 前缀与 '
                . implode(' / ', self::PASSTHROUGH_EXACT)
                . '，其余写法请用 <attr name="..." value="..."/>');
        }

        return $name;
    }

    /**
     * Render the attributes a node forwards to the tag it emits, and reject
     * everything else. An unrecognised attribute is a typo, not an instruction
     * to drop it: forwarding it blindly would hide the mistake, while dropping
     * it silently yields a page that compiles and quietly lost a directive.
     *
     * @param list<string> $dslFields field names this node type consumes itself
     */
    private function forwardedAttrs(array $n, array $dslFields, string $path, bool $emitsTag): string
    {
        $emitted = [];
        $out = '';

        foreach ($n['_attrs'] ?? [] as $name => $value) {
            if ($name === 'type' || in_array($name, $dslFields, true)) {
                continue;
            }
            $target = $this->emitName($name, $path);
            if (isset($emitted[$target])) {
                $this->error("{$path}: 属性 \"{$target}\" 重复定义");
            }
            $emitted[$target] = true;
            $out .= $this->renderAttr($target, $value, $n, $path, $emitsTag);
        }

        // <attr> children are explicit: they skip the whitelist and the __
        // mapping, so their name is emitted exactly as written.
        foreach ($n['_extraAttrs'] ?? [] as $name => $value) {
            if (in_array($name, $dslFields, true)) {
                $this->error("{$path}: <attr name=\"{$name}\"> 与节点字段 \"{$name}\" 同名，请直接使用该字段");
            }
            if (isset($emitted[$name])) {
                $this->error("{$path}: <attr name=\"{$name}\"> 与已有的同名属性重复");
            }
            $emitted[$name] = true;
            $out .= $this->renderAttr($name, $value, $n, $path, $emitsTag);
        }

        return $out;
    }

    private function renderAttr(string $name, string $value, array $n, string $path, bool $emitsTag): string
    {
        if (! $emitsTag) {
            $this->error("{$path}: 节点 <{$n['type']}> 不输出标签，无法承载属性 \"{$name}\"；请改用 <el tag=\"...\"> 包裹内容");
        }

        // Escape the literal part first, then interpolate: the ## ## sugar must
        // reach the template engine unescaped, or its quotes would be mangled.
        // ENT_COMPAT (not ENT_QUOTES) keeps single quotes readable — every
        // attribute here is double-quoted, and Alpine expressions are full of
        // single quotes that would otherwise turn into &#039; noise.
        $value = $this->interpolate(htmlspecialchars($value, ENT_COMPAT), $path);

        return ' ' . $name . '="' . $value . '"';
    }

    /* ---------------------------------------------------------------- *
     * Compilation: array node model -> .tpl.php
     * ---------------------------------------------------------------- */

    private function compilePage(array $page): string
    {
        $hasLayout = array_key_exists('layout', $page);
        $hasBody = array_key_exists('body', $page);
        $hasSections = array_key_exists('sections', $page);

        if ($hasLayout && $hasBody) {
            $this->error('page: 同时指定 layout 与 body 冲突，有 layout 时请使用 sections');
        }
        if ($hasLayout && ! $hasSections) {
            $this->error('page: 指定 layout 时必须同时提供 sections');
        }
        if ($hasSections && ! $hasLayout) {
            $this->error('page: 未指定 layout 时不能使用 sections，请改用 body');
        }
        if (! $hasLayout && ! $hasBody) {
            $this->error('page: 缺少页面内容，请提供 body（无 layout 时）或 layout+sections');
        }

        if ($hasLayout) {
            return $this->compileLayout($page);
        }

        if (array_key_exists('title', $page) && $this->warn !== null) {
            ($this->warn)('page: title 仅在指定 layout 时生效，当前页面无 layout，title 已忽略');
        }

        return $this->compileNodes($page['body'], 'body');
    }

    private function compileLayout(array $page): string
    {
        $layout = $this->literal((string) $page['layout'], 'page', 'layout');
        $out = "<?php \$this->extends('" . $this->str($layout) . "') ?>\n";

        $sections = $page['sections'];
        $ordered = [];
        if (array_key_exists('title', $page) && ! array_key_exists('title', $sections)) {
            $ordered[] = ['name' => 'title', 'nodes' => [['type' => 'text', 'text' => (string) $page['title']]]];
        }
        foreach ($sections as $name => $nodes) {
            $ordered[] = ['name' => (string) $name, 'nodes' => $nodes];
        }

        foreach ($ordered as $section) {
            $name = $this->literal($section['name'], 'sections', 'section 名');
            $out .= "\n<?php \$this->start('" . $this->str($name) . "') ?>\n";
            $out .= $this->compileNodes($section['nodes'], 'sections.' . $name);
            $out .= "\n<?php \$this->end() ?>";
        }

        return $out . "\n";
    }

    private function compileNodes(array $nodes, string $path): string
    {
        $parts = [];
        foreach ($nodes as $i => $node) {
            $parts[] = $this->compileNode($node, $path . '[' . $i . ']');
        }

        return implode("\n", $parts);
    }

    private function compileNode(mixed $node, string $path): string
    {
        if (! is_array($node)) {
            $this->error("{$path}: 节点必须是对象");
        }
        if (! isset($node['type']) || ! is_string($node['type'])) {
            $this->error("{$path}: 节点缺少 type 字段");
        }

        return match ($node['type']) {
            'text' => $this->compileText($node, $path),
            'heading' => $this->compileHeading($node, $path),
            'link' => $this->compileLink($node, $path),
            'if' => $this->compileIf($node, $path),
            'each' => $this->compileEach($node, $path),
            'form' => $this->compileForm($node, $path),
            'table' => $this->compileTable($node, $path),
            'el' => $this->compileEl($node, $path),
            'component' => $this->compileComponent($node, $path),
            default => $this->error("{$path}: 未知节点类型 \"{$node['type']}\""),
        };
    }

    private function compileText(array $n, string $path): string
    {
        $text = $this->requireString($n, 'text', $path);
        $this->forwardedAttrs($n, [], $path, false);   // text emits bare text: validate only

        return $this->interpolate($text, $path);
    }

    private function compileHeading(array $n, string $path): string
    {
        $level = $n['level'] ?? 1;
        if (! is_int($level) || $level < 1 || $level > 6) {
            $this->error("{$path}: heading 的 level 必须是 1-6 的整数，收到 " . var_export($level, true));
        }

        $attrs = $this->forwardedAttrs($n, ['level'], $path, true);
        $text = $this->interpolate($this->requireString($n, 'text', $path), $path);

        return "<h{$level}{$attrs}>{$text}</h{$level}>";
    }

    private function compileLink(array $n, string $path): string
    {
        $href = $this->interpolate($this->requireString($n, 'href', $path), $path);
        $text = $this->interpolate($this->requireString($n, 'text', $path), $path);

        $out = '<a href="' . $href . '"';
        if (array_key_exists('target', $n)) {
            $out .= ' target="' . $this->interpolate($this->requireString($n, 'target', $path), $path) . '"';
        }
        $out .= $this->forwardedAttrs($n, ['href', 'target'], $path, true);

        return $out . '>' . $text . '</a>';
    }

    private function compileIf(array $n, string $path): string
    {
        $when = $this->requireString($n, 'when', $path);
        $this->forwardedAttrs($n, ['when'], $path, false);   // if emits no tag
        $then = $n['then'] ?? null;
        if (! is_array($then)) {
            $this->error("{$path}: if 缺少 then（节点树数组）");
        }

        $cond = $this->compileCondition($when, $path);

        $out = "<?php if ({$cond}): ?>\n" . $this->compileNodes($then, $path . '.then');
        if (array_key_exists('else', $n)) {
            $else = $n['else'];
            if (! is_array($else)) {
                $this->error("{$path}: if 的 else 必须是节点树数组");
            }
            $out .= "\n<?php else: ?>\n" . $this->compileNodes($else, $path . '.else');
        }

        return $out . "\n<?php endif ?>";
    }

    private function compileEach(array $n, string $path): string
    {
        $items = $this->compilePath($this->requireString($n, 'items', $path), $path);
        $this->forwardedAttrs($n, ['items', 'as', 'index'], $path, false);   // each emits no tag
        $as = $n['as'] ?? 'item';
        if (! is_string($as) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
            $this->error("{$path}: each 的 as 必须是合法变量名");
        }

        $body = $n['body'] ?? null;
        if (! is_array($body)) {
            $this->error("{$path}: each 缺少 body（节点树数组）");
        }

        $loop = "foreach ({$items} ?? [] as ";
        if (array_key_exists('index', $n)) {
            $index = $n['index'];
            if (! is_string($index) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index)) {
                $this->error("{$path}: each 的 index 必须是合法变量名");
            }
            $loop .= '$' . $index . ' => ';
        }

        return "<?php {$loop}\$" . $as . "): ?>\n" . $this->compileNodes($body, $path . '.body') . "\n<?php endforeach ?>";
    }

    private function compileForm(array $n, string $path): string
    {
        $action = $this->interpolate($this->requireString($n, 'action', $path), $path);
        $method = $this->literal((string) ($n['method'] ?? 'post'), $path, 'method');
        $fields = $n['fields'] ?? null;
        if (! is_array($fields)) {
            $this->error("{$path}: form 缺少 fields（字段数组）");
        }

        $attr = $this->forwardedAttrs($n, ['action', 'method'], $path, true);
        $out = '<form action="' . $action . '" method="' . $method . '"' . $attr . '>';
        $lines = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                $this->error("{$path}.fields[{$i}]: 字段必须是对象");
            }
            $lines[] = $this->compileField($field, $path . '.fields[' . $i . ']');
        }
        $out .= "\n" . implode("\n", $lines) . "\n</form>";

        return $out;
    }

    private function compileField(array $n, string $path): string
    {
        $this->requireStructuralType($n, 'field', $path);
        $name = $this->literal($this->requireString($n, 'name', $path), $path, 'name');
        $label = $this->literal($this->requireString($n, 'label', $path), $path, 'label');
        $extra = $this->forwardedAttrs(
            $n,
            ['name', 'label', 'input', 'value', 'required', 'placeholder', 'checked', 'rows'],
            $path,
            true
        );
        $input = $n['input'] ?? 'text';
        if (! is_string($input) || ! in_array($input, self::INPUT_TYPES, true)) {
            $this->error("{$path}: 非法的 input 类型 \"" . (is_string($input) ? $input : gettype($input)) . '"');
        }
        if ($input === 'select' && array_key_exists('value', $n)) {
            $this->error("{$path}: select 字段不支持 value 绑定（选中态绑定不在当前范围）");
        }
        if ($input !== 'select' && array_key_exists('options', $n)) {
            $this->error("{$path}: options 仅用于 select 字段");
        }

        if ($input === 'submit') {
            return '  <input type="submit" value="' . $label . '"' . $extra . '>';
        }

        $out = '';
        if ($input !== 'hidden') {
            $out .= '  <label for="' . $name . '">' . $label . "</label>\n";
        }

        $value = $this->bindValue(array_key_exists('value', $n) ? $this->requireString($n, 'value', $path) : null, $path);

        if (in_array($input, ['text', 'password', 'email', 'number'], true)) {
            $out .= '  <input type="' . $input . '" name="' . $name . '" id="' . $name . '"';
            if ($value !== '') {
                $out .= ' value="' . $value . '"';
            }
            if (array_key_exists('placeholder', $n)) {
                $out .= ' placeholder="' . $this->interpolate($this->requireString($n, 'placeholder', $path), $path) . '"';
            }
            if (($n['required'] ?? false) === true) {
                $out .= ' required';
            }
            $out .= $extra . '>';

            return $out;
        }

        if ($input === 'hidden') {
            $out .= '  <input type="hidden" name="' . $name . '"';
            if ($value !== '') {
                $out .= ' value="' . $value . '"';
            }

            return $out . $extra . '>';
        }

        if ($input === 'textarea') {
            $rows = $n['rows'] ?? 4;
            if (! is_int($rows) || $rows < 1) {
                $this->error("{$path}: textarea 的 rows 必须是正整数");
            }
            $content = $value !== '' ? $value : '';

            return $out . '  <textarea name="' . $name . '" id="' . $name . '" rows="' . $rows . '"' . $extra . '>' . $content . '</textarea>';
        }

        if ($input === 'select') {
            $options = $n['options'] ?? null;
            if (! is_array($options)) {
                $this->error("{$path}: select 字段缺少 options 映射");
            }
            $out .= '  <select name="' . $name . '" id="' . $name . '"' . $extra . '>';
            foreach ($options as $optValue => $optLabel) {
                $optValue = $this->literal((string) $optValue, $path, 'option value');
                $optLabel = $this->literal((string) $optLabel, $path, 'option 文本');
                $out .= "\n    <option value=\"" . $optValue . '">' . $optLabel . '</option>';
            }

            return $out . "\n  </select>";
        }

        // checkbox
        $out .= '  <input type="checkbox" name="' . $name . '" id="' . $name . '"';
        if ($value !== '') {
            $out .= ' value="' . $value . '"';
        }
        if (array_key_exists('checked', $n)) {
            $checked = $this->compilePath($this->requireString($n, 'checked', $path), $path);
            $out .= "<?= ({$checked} ?? null) ? ' checked' : '' ?>";
        }

        return $out . $extra . '>';
    }

    private function compileTable(array $n, string $path): string
    {
        $items = $this->compilePath($this->requireString($n, 'items', $path), $path);
        $as = $n['as'] ?? 'row';
        if (! is_string($as) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
            $this->error("{$path}: table 的 as 必须是合法变量名");
        }
        $columns = $n['columns'] ?? null;
        if (! is_array($columns)) {
            $this->error("{$path}: table 缺少 columns（列数组）");
        }
        $empty = array_key_exists('empty', $n) ? $this->literal($this->requireString($n, 'empty', $path), $path, 'empty') : null;
        $attr = $this->forwardedAttrs($n, ['items', 'as', 'empty'], $path, true);

        $head = '<thead><tr>';
        $rows = [];
        foreach ($columns as $i => $column) {
            $columnPath = $path . '.columns[' . $i . ']';
            if (! is_array($column)) {
                $this->error("{$columnPath}: 列必须是对象");
            }
            $this->requireStructuralType($column, 'column', $columnPath);
            $label = $this->literal($this->requireString($column, 'label', $columnPath), $columnPath, 'label');
            $columnAttr = $this->forwardedAttrs($column, ['label', 'bind'], $columnPath, true);
            $head .= '<th>' . $label . '</th>';

            $hasBind = array_key_exists('bind', $column);
            $hasContent = array_key_exists('content', $column);
            if ($hasBind && $hasContent) {
                $this->error($columnPath . ': 列同时指定 bind 与 content');
            }
            if (! $hasBind && ! $hasContent) {
                $this->error($columnPath . ': 列缺少 bind 或 content');
            }

            if ($hasBind) {
                $bind = $this->requireString($column, 'bind', $columnPath);
                $rows[] = '<td' . $columnAttr . '>' . $this->bindValue($as . '.' . $bind, $columnPath) . '</td>';
            } else {
                $rows[] = '<td' . $columnAttr . '>' . $this->compileNodes($column['content'], $columnPath . '.content') . '</td>';
            }
        }
        $head .= '</tr></thead>';

        $colspan = count($columns);
        $out = "<table{$attr}>\n{$head}\n<tbody>\n";
        if ($empty !== null) {
            $out .= "<?php if (({$items} ?? []) === []): ?>\n<tr><td colspan=\"{$colspan}\">{$empty}</td></tr>\n<?php else: ?>\n";
        }
        $out .= "<?php foreach ({$items} ?? [] as \${$as}): ?>\n<tr>\n" . implode("\n", $rows) . "\n</tr>\n<?php endforeach ?>";
        if ($empty !== null) {
            $out .= "\n<?php endif ?>";
        }

        return $out . "\n</tbody>\n</table>";
    }

    /**
     * Generic element node. text / if / each emit no tag of their own, so this
     * is the only way to hang attributes on a wrapper — which is where front-end
     * state containers belong (Alpine's x-data, Vue's v-scope).
     */
    private function compileEl(array $n, string $path): string
    {
        $tag = strtolower($this->literal($this->requireString($n, 'tag', $path), $path, 'tag'));
        if (! preg_match(self::TAG_PATTERN, $tag)) {
            $this->error("{$path}: 非法的 tag \"{$tag}\"，需为小写 HTML 标签名");
        }

        $attrs = $this->forwardedAttrs($n, ['tag'], $path, true);

        $body = $n['body'] ?? null;
        if (! is_array($body)) {
            $this->error("{$path}: el 缺少子节点");
        }
        $inner = $this->compileNodes($body, $path . '.body');

        return $inner === ''
            ? "<{$tag}{$attrs}></{$tag}>"
            : "<{$tag}{$attrs}>\n{$inner}\n</{$tag}>";
    }

    private function compileComponent(array $n, string $path): string
    {
        $name = $this->literal($this->requireString($n, 'name', $path), $path, 'name');
        $this->forwardedAttrs($n, ['name'], $path, false);   // component emits its own markup

        if (! array_key_exists('data', $n)) {
            return "<?= \$this->component('" . $this->str($name) . "') ?>";
        }

        $data = $n['data'];
        if (! is_array($data)) {
            $this->error("{$path}: component 的 data 必须是对象");
        }

        $lines = [];
        foreach ($data as $key => $value) {
            if (! is_string($value)) {
                $this->error("{$path}: component data 的 \"{$key}\" 必须是字符串（值支持 {{ 路径 }} 插值）");
            }
            $lines[] = "    '" . $this->str((string) $key) . "' => " . $this->interpolatePhp($value, $path . '.data.' . $key);
        }

        return "<?= \$this->component('" . $this->str($name) . "', [\n" . implode(",\n", $lines) . ",\n]) ?>";
    }

    /**
     * HTML-context interpolation: {{ path }} -> ## $var['key'] ?? '' ## sugar.
     * The template engine escapes these at render time.
     */
    private function interpolate(string $text, string $path): string
    {
        $this->assertInterpolationBalanced($text, $path);

        return preg_replace_callback(
            self::INTERPOLATION,
            function (array $m) use ($path): string {
                $php = $this->compilePath(trim($m[1]), $path);

                return '## ' . $php . " ?? '' ##";
            },
            $text
        );
    }

    /**
     * PHP-context interpolation for component data arrays: builds a PHP
     * expression string, e.g. 'edit ' . ($user['name'] ?? '') .
     * Never emits ## ## sugar here — it would corrupt the PHP literal.
     *
     * Values stay unescaped: the component template owns that decision
     * ($this->e() for text, $this->raw() for markup), so escaping here would
     * double-encode every interpolated value that contains HTML.
     */
    private function interpolatePhp(string $text, string $path): string
    {
        $this->assertInterpolationBalanced($text, $path);

        $parts = preg_split(self::INTERPOLATION, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $exprs = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if ($part !== '') {
                    $exprs[] = "'" . addcslashes($part, "\\'") . "'";
                }
            } else {
                $php = $this->compilePath(trim($part), $path);
                $exprs[] = '(' . $php . " ?? '')";
            }
        }

        return $exprs === [] ? "''" : implode(' . ', $exprs);
    }

    private function compileCondition(string $expr, string $path): string
    {
        $negated = str_starts_with($expr, '!');
        if ($negated) {
            $expr = substr($expr, 1);
        }
        $php = $this->compilePath($expr, $path);

        return $negated ? "!({$php} ?? null)" : "{$php} ?? null";
    }

    /** Compile a dot path into PHP array access: user.name -> $user['name']. */
    private function compilePath(string $path, string $where): string
    {
        if (! preg_match(self::PATH_PATTERN, $path)) {
            $this->error("{$where}: 非法路径 \"{$path}\"，仅支持 a.b.c 形式的变量路径");
        }

        $segments = explode('.', $path);
        $php = '$' . array_shift($segments);
        foreach ($segments as $segment) {
            $php .= "['" . $this->str($segment) . "']";
        }

        return $php;
    }

    /** Compile a bound path into escaped attribute sugar: ## $user['name'] ?? '' ##. */
    private function bindValue(?string $path, string $where): string
    {
        if ($path === null) {
            return '';
        }

        return '## ' . $this->compilePath($path, $where) . " ?? '' ##";
    }

    private function assertInterpolationBalanced(string $text, string $path): void
    {
        // A third brace defeats the counting below: '{{{ a }}}' contains one
        // '{{' and one '}}', so it passes as balanced, and the regex then
        // matches only the inner '{{ a }}' — leaving stray braces wrapped around
        // the compiled sugar in the output.
        if (str_contains($text, '{{{') || str_contains($text, '}}}')) {
            $this->error("{$path}: 插值符号不能连续三个花括号（{{{ 或 }}}），请写 {{ path }}");
        }

        $open = substr_count($text, '{{');
        if ($open === 0) {
            return;
        }
        if ($open !== substr_count($text, '}}')) {
            $this->error("{$path}: 插值符号未配对（{{ 与 }} 数量不一致）");
        }
    }

    private function requireString(array $n, string $key, string $path): string
    {
        if (! isset($n[$key]) || ! is_string($n[$key])) {
            $this->error("{$path}: 缺少 string 字段 \"{$key}\"");
        }

        return $n[$key];
    }

    /**
     * Nested structures (field / column) are typed by their position — in this
     * variant that position is the element name — so a written type must match
     * it instead of being silently ignored. Keeps the node model identical to
     * the YAML variant.
     */
    private function requireStructuralType(array $n, string $expected, string $path): void
    {
        if (! array_key_exists('type', $n)) {
            return;
        }
        if ($n['type'] !== $expected) {
            $this->error("{$path}: type 必须是 \"{$expected}\"（{$expected} 是内嵌结构，位置已决定类型）");
        }
    }

    /**
     * Literal fields are compiled as-is — interpolation has no meaning there,
     * so {{ }} is a compile error rather than a silent no-op.
     */
    private function literal(string $value, string $path, string $field): string
    {
        if (str_contains($value, '{{') || str_contains($value, '}}')) {
            $this->error("{$path}: \"{$field}\" 是字面量字段，不支持 {{ }} 插值");
        }

        return $value;
    }

    /** Escape a value for a PHP single-quoted string. */
    private function str(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    private function error(string $message): never
    {
        throw new CompileException($message);
    }
}
