<?php
/* my-card —— 自定义组件示例，页面上用 <component name="my-card"> 引用。
 *
 * 组件就是普通的 miGears Template 文件，规则只有四条：
 *   1. data 里的键在组件内成为局部变量（这里是 title / body），页面上传什么就有什么；
 *   2. 转义由组件自己决定——文本用 $this->e()，信任的 HTML 用 $this->raw()。
 *      页面侧的插值以未转义形式送达，这里再转义一次才是正确的一次转义；
 *   3. 组件内可以继续调用 $this->component()；
 *   4. 无需注册：文件放在任一已注册的模板搜索路径下，按 name 命中同名文件。
 */ ?><div class="my-card">
    <h3 class="my-card-title"><?= $this->e($title ?? '') ?></h3>
    <div class="my-card-body"><?= $this->raw((string) ($body ?? '')) ?></div>
</div>
