<?php
/* my-card — a custom component example, referenced in the page with
 * <component name="my-card">.
 *
 * A component is an ordinary miGears Template file; the rules are only four:
 *   1. Each data key becomes a local variable in the component (here title /
 *      body) — whatever the page passes is what you get;
 *   2. Escaping is decided by the component — use $this->e() for text and
 *      $this->raw() for trusted HTML. Interpolations arrive unescaped from the
 *      page side, so escaping once more here is the single correct escape;
 *   3. A component may call $this->component() in turn;
 *   4. No registration is needed: put the file under any registered template
 *      search path and it is resolved by name.
 */ ?><div class="my-card">
    <h3 class="my-card-title"><?= $this->e($title ?? '') ?></h3>
    <div class="my-card-body"><?= $this->raw((string) ($body ?? '')) ?></div>
</div>
