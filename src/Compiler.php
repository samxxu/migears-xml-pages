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

    private const FALSE_VALUES = ['false', '0', 'no', 'off'];

    /**
     * Node types that emit a tag of their own, and can therefore mount an
     * attribute. Everything else (text / if / each / component) has nowhere to
     * put one — the rule the shared compiler enforces with "emits no tag".
     *
     * The child whitelists are kept in step with that rule on purpose: a
     * misplaced `<attr>` is then refused here, by the layer that owns the
     * decision, instead of being admitted and failing later under another
     * problem's wording.
     */
    private const TAG_EMITTING_NODES = ['heading', 'link', 'el', 'form', 'table', 'field', 'column'];

    /**
     * An <attr> name is emitted verbatim, so it is the one attribute name this
     * package never checks against a whitelist. It still has to be a name a tag
     * can carry: whitespace, quotes, angle brackets, '/' and '=' all end the
     * name early and turn the emitted tag into markup no browser can read.
     */
    private const ATTR_NAME_PATTERN = '/^[^\s"\'<>\/=]+$/';

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
        // composer checks "ext-*" when installing, not when running, and both of
        // these can be compiled out. Naming them here turns what would be an
        // uncaught Error into a compile error the caller can report like any
        // other: simplexml_load_string() parses this very call, and every
        // wrapper element is inspected through DOM below.
        foreach (['simplexml_load_string' => 'simplexml', 'dom_import_simplexml' => 'dom'] as $function => $extension) {
            if (! function_exists($function)) {
                throw new CompileException("ext-{$extension} is not loaded; {$function}() is required to read a page declaration");
            }
        }

        // An entity declaration is refused rather than parsed. LIBXML_NONET stops
        // the parser fetching anything, so a reference to an external entity
        // expands to nothing: the value disappears from the page and nothing says
        // so, which is the loss this module exists to refuse. Internal entities
        // would expand, so admitting declarations would make two references of the
        // same shape behave differently. A page declaration is data, and has no use
        // for either kind.
        //
        // A comment and a CDATA section can only hold text, never a declaration —
        // both are removed before the scan, or a page that merely talks about entity
        // declarations would be refused for writing them down. Anything else is
        // scanned as written, unterminated markers included: there the shapes are
        // still reachable, and refusing is the safe direction.
        $scanned = preg_replace(['~<!--.*?-->~s', '~<!\[CDATA\[.*?\]\]>~s'], '', $source);
        if ($scanned !== null && preg_match('/<!\s*ENTITY\s+([A-Za-z_:][-\w.:]*)/i', $scanned, $entity)) {
            throw new CompileException(
                "entity declarations are not supported: a reference to <!ENTITY {$entity[1]}> would either expand to "
                . 'nothing (nothing external is ever fetched, so the value is dropped in silence) or pull a document '
                . 'into the page; write the value inline instead'
            );
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($source, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        if ($xml === false) {
            throw new CompileException('XML syntax error' . $this->formatLibxmlErrors($errors) . $this->atSignHint($source));
        }
        if ($xml->getName() !== 'page') {
            throw new CompileException('XML root element must be <page>');
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

        return '. XML attribute names cannot contain "@": write @click as __click (equivalent to x-on:click)';
    }

    /**
     * Every error libxml recorded, not just the first: a document with three typos
     * should not take three runs to fix, and the YAML frontend reports its whole
     * list the same way. libxml knows the line, so it is included.
     *
     * @param list<\LibXMLError> $errors
     */
    private function formatLibxmlErrors(array $errors): string
    {
        $parts = [];
        foreach ($errors as $error) {
            $message = trim($error->message);
            $parts[] = $error->line > 0 ? "line {$error->line}: {$message}" : $message;
        }

        return $parts === [] ? '' : ': ' . implode('; ', $parts);
    }

    /** @return array<string, mixed> */
    private function pageFromElement(SimpleXMLElement $page): array
    {
        $out = [];
        foreach ($this->readAttributes($page) as $name => $value) {
            if (! in_array($name, ['title', 'layout'], true)) {
                $this->error("page: unknown attribute \"{$name}\"; only title and layout are supported (the page root does not emit a tag and cannot carry forwarded attributes)");
            }
            $out[$name] = $value;
        }
        $this->assertChildren($page, ['body', 'sections'], 'page');
        $this->assertSingleChild($page, 'body', 'page');
        $this->assertSingleChild($page, 'sections', 'page');

        if (isset($page->body)) {
            $this->assertNoAttributes($page->body, [], 'page.body');
            $out['body'] = $this->nodesFromElement($page->body, 'body');
        }
        if (isset($page->sections)) {
            $this->assertNoAttributes($page->sections, [], 'page.sections');
            $this->assertChildren($page->sections, ['section'], 'sections');
            $sections = [];
            foreach ($this->childList($page->sections, 'section') as $i => $section) {
                if (! isset($section['name'])) {
                    $this->error("sections[{$i}]: section is missing its name attribute");
                }
                $this->assertNoAttributes($section, ['name'], "sections[{$i}]");
                // A name that is there but blank is not a missing attribute: it is the
                // empty name the shared layer refuses, and the parity corpus compares
                // the wording of a shared rule, so the value is handed on as written
                // and the shared check speaks. Only a name with content is trimmed
                // here, where the trimming decides which layout section gets filled.
                $raw = (string) $section['name'];
                $name = trim($raw);
                if ($name === '') {
                    $sections[$raw] = $this->nodesFromElement($section, 'sections.' . $name);

                    continue;
                }
                if (array_key_exists($name, $sections)) {
                    $this->error("sections[{$i}]: <section name=\"{$name}\"> is defined more than once; a section name may only appear once");
                }
                $sections[$name] = $this->nodesFromElement($section, 'sections.' . $name);
            }
            $out['sections'] = $sections;
        }

        return $out;
    }

    /**
     * The repeated children of an element, as a plain list.
     *
     * Iterating a SimpleXML repetition (`$el->fields->field`) yields the element
     * *name* as the key, not a position, so `foreach (… as $i => …)` would build
     * paths like "fields[field]" instead of the "fields[0]" spelling every path
     * in this project uses. Going through a list restores real indexes.
     *
     * @return list<SimpleXMLElement>
     */
    private function childList(SimpleXMLElement $parent, string $name): array
    {
        $list = [];
        foreach ($parent->{$name} as $child) {
            $list[] = $child;
        }

        return $list;
    }

    /** @return list<array<string, mixed>> */
    private function nodesFromElement(SimpleXMLElement $parent, string $path, bool $allowAttr = false): array
    {
        if ($this->hasBareText($parent)) {
            $this->error("{$path}: cannot write text or CDATA directly (it would be dropped); wrap it in <text>");
        }

        $nodes = [];
        $i = 0;
        foreach ($parent->children() as $child) {
            if ($child->getName() === 'attr') {
                // <attr> decorates the parent tag; it is not a content node.
                if (! $allowAttr) {
                    $this->errorAttrMisplaced($path);
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
            $this->error("{$path}: cannot write text or CDATA directly (it would be dropped); allowed child elements: " . implode(' / ', $allowed));
        }

        foreach ($parent->children() as $child) {
            $name = $child->getName();
            if (in_array($name, $allowed, true)) {
                continue;
            }
            // <attr> is not a content node but a decoration for the parent's tag,
            // so it gets its own message where it is not allowed — the whitelist it
            // just missed may be empty (text) or unrelated (then / else).
            if ($name === 'attr') {
                $this->errorAttrMisplaced($path);
            }
            $hint = $allowed === [] ? '' : ' (allowed: ' . implode(' / ', $allowed) . ')';
            $this->error("{$path}: disallowed child element <{$name}>{$hint}");
        }
    }

    /**
     * The one wording for a misplaced <attr>, used by every site that can meet
     * one: it decorates the parent's tag rather than joining the content, so the
     * useful hint is where it may live, not which names this container accepts.
     */
    private function errorAttrMisplaced(string $path): never
    {
        $this->error("{$path}: <attr> may only be a child of a node that emits a tag ("
            . implode(' / ', self::TAG_EMITTING_NODES) . ')');
    }

    /**
     * The element's attributes, read through DOM so that namespaced names
     * (`xml:lang`, `foo:bar`) are visible as well. SimpleXML's attributes()
     * returns only unprefixed names, which left those in a blind spot: neither
     * forwarded nor refused, just dropped. The DSL has no use for them, so they
     * belong in front of the whitelist with every other unknown attribute.
     *
     * @return array<string, string>
     */
    private function readAttributes(SimpleXMLElement $el): array
    {
        $attrs = [];
        foreach (dom_import_simplexml($el)->attributes as $attr) {
            $attrs[$attr->nodeName] = $attr->nodeValue;
        }

        return $attrs;
    }

    /**
     * A container child may appear at most once.
     *
     * SimpleXML reads a repetition as a list, and `isset($el->body)` answers
     * about the first one only: a second <body> / <then> / <columns> / <data>
     * was dropped with the page still compiling. The container names are
     * singular by definition, so the second one is a mistake worth naming while
     * both are still visible.
     */
    private function assertSingleChild(SimpleXMLElement $parent, string $name, string $path): void
    {
        if (count($parent->{$name}) > 1) {
            $this->error("{$path}: <{$name}> is defined more than once; a container element may only appear once");
        }
    }

    /**
     * A wrapper element emits no tag of its own, so it has no attributes to
     * forward — anything written there would be dropped without a trace. Only
     * the names listed mean something (<section name>, <option value>), and the
     * rest are named as the typos they almost always are.
     *
     * Read through DOM rather than SimpleXML's attributes(), which returns only
     * unprefixed names: `xml:lang` or `foo:bar` would otherwise slip past.
     *
     * @param list<string> $allowed
     */
    private function assertNoAttributes(SimpleXMLElement $el, array $allowed, string $path): void
    {
        foreach (dom_import_simplexml($el)->attributes as $attr) {
            $name = $attr->nodeName;
            if (in_array($name, $allowed, true)) {
                continue;
            }
            $this->error("{$path}: unknown attribute \"{$name}\" on <{$el->getName()}>; "
                . 'a wrapper element cannot carry forwarded attributes'
                . ($allowed === [] ? '' : ' (allowed: ' . implode(' / ', $allowed) . ')'));
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

    /** @return array<string, mixed> */
    private function nodeFromElement(SimpleXMLElement $el, string $path): array
    {
        $type = $el->getName();

        $attrs = $this->readAttributes($el);
        if (isset($attrs['type']) && $attrs['type'] !== $type) {
            $this->error("{$path}: type must be \"{$type}\" (the element name decides the node type)");
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
            $this->assertChildren($el, ['then', 'else'], $path);
            $this->assertSingleChild($el, 'then', $path);
            $this->assertSingleChild($el, 'else', $path);
            if (isset($el->then)) {
                $this->assertNoAttributes($el->then, [], $path . '.then');
                $node['then'] = $this->nodesFromElement($el->then, $path . '.then');
            }
            if (isset($el->else)) {
                $this->assertNoAttributes($el->else, [], $path . '.else');
                $node['else'] = $this->nodesFromElement($el->else, $path . '.else');
            }
        } elseif ($type === 'each') {
            $this->assertChildren($el, ['body'], $path);
            $this->assertSingleChild($el, 'body', $path);
            if (isset($el->body)) {
                $this->assertNoAttributes($el->body, [], $path . '.body');
                $node['body'] = $this->nodesFromElement($el->body, $path . '.body');
            }
        } elseif ($type === 'form') {
            $this->assertChildren($el, ['fields', 'attr'], $path);
            $this->assertSingleChild($el, 'fields', $path);
            if (isset($el->fields)) {
                $this->assertNoAttributes($el->fields, [], $path . '.fields');
                $this->assertChildren($el->fields, ['field'], $path . '.fields');
                $fields = [];
                foreach ($this->childList($el->fields, 'field') as $i => $field) {
                    $fields[] = $this->fieldFromElement($field, $path . '.fields[' . $i . ']');
                }
                $node['fields'] = $fields;
            }
        } elseif ($type === 'table') {
            $this->assertChildren($el, ['columns', 'attr'], $path);
            $this->assertSingleChild($el, 'columns', $path);
            if (isset($el->columns)) {
                $this->assertNoAttributes($el->columns, [], $path . '.columns');
                $this->assertChildren($el->columns, ['column'], $path . '.columns');
                $columns = [];
                foreach ($this->childList($el->columns, 'column') as $i => $column) {
                    $columns[] = $this->columnFromElement($column, $path . '.columns[' . $i . ']');
                }
                $node['columns'] = $columns;
            }
        } elseif ($type === 'component') {
            $this->assertChildren($el, ['data'], $path);
            $this->assertSingleChild($el, 'data', $path);
            if (isset($el->data)) {
                $this->assertNoAttributes($el->data, [], $path . '.data');
                if ($this->hasBareText($el->data)) {
                    $this->error("{$path}.data: cannot write text directly (it would be dropped); child element names are the data keys");
                }
                $data = [];
                foreach ($el->data->children() as $key => $value) {
                    $key = (string) $key;
                    // A <data> child is a key/value pair: the name is the key, the
                    // text is the value. Attributes on it mean nothing and used to
                    // vanish — including the ones on <attr name="…"/>, which then
                    // read as a data key named "attr" with an empty value.
                    $stray = $this->readAttributes($value);
                    if ($stray !== []) {
                        $this->error("{$path}.data: \"{$key}\" takes no attributes (" . implode(' / ', array_keys($stray))
                            . '); the element name is the data key and its text is the value');
                    }
                    // A <data> value is text. Nested markup has no representation
                    // here, and reading it as text would fold 'Hi <b>Bob</b>' into
                    // 'Hi' — tags and content gone, with no complaint.
                    if ($value->children()->count() > 0) {
                        $this->error("{$path}.data: <data> values are text, but \"{$key}\" has child elements that would be dropped");
                    }
                    if (array_key_exists($key, $data)) {
                        $this->error("{$path}.data: \"{$key}\" is defined more than once; a data key may only appear once");
                    }
                    $data[$key] = trim((string) $value);
                }
                $node['data'] = $data;
            }
        } else {
            // Leaf nodes (text / heading / link / unknown): element text -> text field.
            // Their text IS the content, so text is allowed; nested markup would lose
            // its tags, so the only legal child is <attr> — and only where the node
            // emits a tag. <text> emits bare text, so it has nothing to mount on.
            $this->assertChildren(
                $el,
                in_array($type, self::TAG_EMITTING_NODES, true) ? ['attr'] : [],
                $path,
                true
            );
            // level is legal on heading alone, so it is read here rather than for
            // every node: converting it globally meant <link level="2"> was judged
            // as a number before anyone asked whether a link may carry one.
            if ($type === 'heading' && isset($node['level'])) {
                $node['level'] = $this->toInt($path, 'level', $node['level']);
            }
            $node['text'] = trim((string) $el);
        }

        // rows and required are converted where they are legal, in
        // fieldFromElement(). Same reason as level above: an attribute the element
        // cannot carry has to be named as an unknown attribute, not judged as a
        // number first — <heading rows="xx"> used to complain about "xx".

        return $node;
    }

    /** @return array<string, mixed> */
    private function fieldFromElement(SimpleXMLElement $el, string $path): array
    {
        $attrs = $this->readAttributes($el);
        if (isset($attrs['type']) && $attrs['type'] !== 'field') {
            $this->error("{$path}: type must be \"field\" (the element name decides the node type)");
        }
        $this->assertChildren($el, ['options', 'attr'], $path);
        $this->assertSingleChild($el, 'options', $path);

        $field = $attrs;
        $field['type'] = 'field';
        $field['_attrs'] = $attrs;
        $extra = $this->attrChildren($el, $path);
        if ($extra !== []) {
            $field['_extraAttrs'] = $extra;
        }

        if (isset($el->options)) {
            $this->assertNoAttributes($el->options, [], $path . '.options');
            $this->assertChildren($el->options, ['option'], $path . '.options');
            $options = [];
            foreach ($this->childList($el->options, 'option') as $i => $option) {
                if ($option->children()->count() > 0) {
                    $this->error("{$path}.options[{$i}]: <option> only accepts text content and a value attribute");
                }
                if (! isset($option['value'])) {
                    $this->error("{$path}.options[{$i}]: option is missing its value attribute");
                }
                $this->assertNoAttributes($option, ['value'], $path . ".options[{$i}]");
                $value = (string) $option['value'];
                // Keyed by value, so a repeated value would collapse the two
                // entries into one and the first would disappear.
                if (array_key_exists($value, $options)) {
                    $this->error("{$path}.options[{$i}]: <option value=\"{$value}\"> is defined more than once; an option value may only appear once");
                }
                $options[$value] = trim((string) $option);
            }
            $field['options'] = $options;
        }
        if (isset($field['required'])) {
            $field['required'] = $this->toBool($path, 'required', $field['required']);
        }
        if (isset($field['rows'])) {
            $field['rows'] = $this->toInt($path, 'rows', $field['rows']);
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private function columnFromElement(SimpleXMLElement $el, string $path): array
    {
        $attrs = $this->readAttributes($el);
        if (isset($attrs['type']) && $attrs['type'] !== 'column') {
            $this->error("{$path}: type must be \"column\" (the element name decides the node type)");
        }
        $this->assertChildren($el, ['content', 'attr'], $path);
        $this->assertSingleChild($el, 'content', $path);

        $column = $attrs;
        $column['type'] = 'column';
        $column['_attrs'] = $attrs;
        $extra = $this->attrChildren($el, $path);
        if ($extra !== []) {
            $column['_extraAttrs'] = $extra;
        }

        if (isset($el->content)) {
            $this->assertNoAttributes($el->content, [], $path . '.content');
            $column['content'] = $this->nodesFromElement($el->content, $path . '.content');
        }

        return $column;
    }

    /**
     * Read a boolean attribute the way HTML does: a known truthy or falsy word,
     * or one of the two spellings that mean "present" — an empty value
     * (`required=""`) or the attribute's own name (`required="required"`).
     *
     * Any other spelling is a mistake, and answering false to it would drop the
     * attribute without a trace — the silent loss this module refuses.
     */
    private function toBool(string $where, string $name, string $value): bool
    {
        $normalised = strtolower(trim($value));

        if ($normalised === '' || $normalised === $name) {
            return true;
        }
        if (in_array($normalised, self::TRUE_VALUES, true)) {
            return true;
        }
        if (in_array($normalised, self::FALSE_VALUES, true)) {
            return false;
        }

        $this->error("{$where}: the value \"{$value}\" for {$name} is not a boolean; truthy values may be "
            . implode(' / ', self::TRUE_VALUES) . ' / ' . $name . ' / empty, and falsy values may be '
            . implode(' / ', self::FALSE_VALUES));
    }

    /**
     * Read an integer attribute.
     *
     * `level` and `rows` are counts and sizes, so the only spellable value is a
     * decimal number. Casting silently turned "two" into 0, and the failure then
     * arrived as a range complaint ("level must be an integer from 1 to 6, got 0") about a
     * value nobody wrote. Reading the syntax here leaves range and enum checks
     * to the shared compiler, which is where they belong.
     */
    private function toInt(string $where, string $name, string $value): int
    {
        $normalised = trim($value);
        if (! preg_match('/^[+-]?\d+$/', $normalised)) {
            $this->error("{$where}: the value \"{$value}\" for {$name} is not an integer; write a decimal number (e.g. 2)");
        }

        return (int) $normalised;
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
                $this->error("{$path}: <attr> only accepts name / value attributes and cannot carry child content");
            }
            if (! isset($attr['name'])) {
                $this->error("{$path}: <attr> is missing its name attribute");
            }
            $name = (string) $attr['name'];
            if (! preg_match(self::ATTR_NAME_PATTERN, $name)) {
                $this->error("{$path}: <attr name=\"{$name}\"> is not a legal attribute name; "
                    . 'it is emitted exactly as written, so it may not contain whitespace, quotes, "<", ">", "/" or "="');
            }
            if (! isset($attr['value'])) {
                $this->error("{$path}: <attr name=\"{$name}\"> is missing its value attribute");
            }
            $this->assertNoAttributes($attr, ['name', 'value'], $path);
            // Two <attr> with the same name would collapse into one key here and
            // the first would vanish silently, so the clash is named while both
            // are still visible. (A clash with a plain attribute of the same
            // name is caught later, by the duplicate check in the shared layer.)
            if (isset($extra[$name])) {
                $this->error("{$path}: <attr name=\"{$name}\"> is defined more than once; an attribute of the same name may only appear once");
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
     * @param array<string, mixed> $n
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
