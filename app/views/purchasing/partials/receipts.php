<?php /** The goods receipts against the order (`po-receipts`). Data: o, here, may */ ?>
<div class="card mb-3" id="po-receipts"><div class="card-header fw-semibold">Receipts</div><div class="card-body fs-12">
    <?php if ($o['receipts'] === []): ?><span class="text-muted" id="po-receipts-empty"><?= $o['kind'] === 'dropship' ? 'A drop-ship is received when the customer\'s line is delivered.' : 'Nothing received yet.' ?></span><?php endif; ?>
    <?php foreach ($o['receipts'] as $r): ?><div id="po-receipt-<?= (int) $r['goods_receipt_id'] ?>"><?= $may['receive'] ? hx_link(with_back('/receipts/' . (int) $r['goods_receipt_id'], $here), e($r['number']), 'fw-semibold') : e($r['number']) ?> · <?= e($r['status']) ?> · <?= e(format_date($r['received_on'])) ?></div><?php endforeach; ?>
</div></div>
