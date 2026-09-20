<?php

declare(strict_types=1);

namespace MiGears\XmlPages;

use MiGears\Pages\Compiler as PagesCompiler;
use MiGears\XmlPages\Exception\CompileException;
use SimpleXMLElement;

/**
 * XML frontend: parses XML page declarations into the array node model, then
 * hands them to the shared compiler in migears/pages.
 *
 * Everything after parsing — node compilation, interpolation, validation,
 * attribute forwarding — is inherited. All frontends therefore share one
 * compiler and one node model; what stays here is the XML surface syntax and
 * the spelling decisions XML forces on attribute names.
 *
 * XML is stricter than HTML about attribute *names*: '@' is not a legal
 * NameStartChar, and ':' is reserved for namespaces. Framework directives that
 * avoid both spellings (x-on:click, v-bind:href, wire:click, hx-get, data-*)
 * ride through as ordinary attributes; the '@event' shorthand needs a spelling
 * XML accepts, which is what mapAttributeName() provides.
 */
class Compiler extends PagesCompiler
{
    private const TRUE_VALUES = ['true', '1', 'yes', 'on'];

    /**
     * XML spells the '@event' shorthand as '__event', so that is the form the
     * hyphenated-directive hint should suggest. The base suggests '@event',
     * which is what the other frontends can write directly.
     *
     * @var array<string, list<string>>
     */
    protected const COLON_ONLY_DIRECTIVES = [
        'x-on-' => ['x-on:', '__'],
        'x-bind-' => ['x-bind:', ':'],
        'x-transition-' => ['x-transition:'],
    ];

    protected function parse(string $source): array
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

    /* ---------------------------------------------------------------- *
     * Hooks: XML spelling of attribute names
     * ---------------------------------------------------------------- */

    /**
     * Resolve the name an attribute is emitted under.
     *
     * '__click' is the readable spelling of Alpine's '@click': XML cannot put
     * '@' in an attribute name, so the shorthand arrives as a name the parser
     * can read and the compiler rewrites. The mapping is positional — '@' is
     * always the first character — so unlike guessing 'x-on-click' into
     * 'x-on:click' it can never split in the wrong place.
     */
    protected function mapAttributeName(string $name, string $path): string
    {
        if (str_starts_with($name, '__') && $name !== '__') {
            return '@' . substr($name, 2);
        }

        return parent::mapAttributeName($name, $path);
    }

    /**
     * XML keeps attributes apart from fields: the parser stores plain
     * attributes in '_attrs' and explicit <attr> children in '_extraAttrs'. The
     * latter skip the whitelist and the '__' mapping, so their name is emitted
     * exactly as written.
     *
     * @param list<string> $dslFields
     * @return list<array{name: string, value: mixed, explicit: bool}>
     */
    protected function attributeCandidates(array $n, array $dslFields): array
    {
        $candidates = [];

        foreach ($n['_attrs'] ?? [] as $name => $value) {
            $name = (string) $name;
            if ($name === 'type' || in_array($name, $dslFields, true)) {
                continue;          // consumed by the node itself; 'type' is decided by the element name
            }
            $candidates[] = ['name' => $name, 'value' => $value, 'explicit' => false];
        }

        foreach ($n['_extraAttrs'] ?? [] as $name => $value) {
            $candidates[] = ['name' => (string) $name, 'value' => $value, 'explicit' => true];
        }

        return $candidates;
    }

    protected function nodeRef(string $type): string
    {
        return "<{$type}>";
    }

    protected function containerRef(string $type): string
    {
        return "<{$type} tag=\"...\">";
    }

    protected function explicitAttrRef(string $name): string
    {
        return "<attr name=\"{$name}\">";
    }

    protected function newException(string $message): CompileException
    {
        return new CompileException($message);
    }
}
