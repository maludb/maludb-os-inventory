<?php /** The evidence of a proposal in words. Data: evidence, id? */ ?>
<span class="fs-11 text-muted"<?= isset($id) ? ' id="' . e($id) . '"' : '' ?>><?= e(evidence_words($evidence)) ?></span>
