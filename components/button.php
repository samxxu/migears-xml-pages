<?php if (! empty($href)): ?>
<a href="<?= $this->e($href) ?>" class="btn btn-<?= $this->e($type ?? 'default') ?>"><?= $this->e($text ?? '') ?></a>
<?php else: ?>
<button type="button" class="btn btn-<?= $this->e($type ?? 'default') ?>"><?= $this->e($text ?? '') ?></button>
<?php endif ?>
