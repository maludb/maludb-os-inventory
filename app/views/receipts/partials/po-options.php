<?php /** The purchase order select's options (Pattern A). Data: options, selected, supplier */ ?>
<option value="">— <?= $supplier ? ($options === [] ? 'no open purchase order' : 'none (a free receipt)') : 'choose a supplier first' ?> —</option>
<?php foreach ($options as $o): ?><option value="<?= (int) $o['purchase_order_id'] ?>" <?= (int) $selected === (int) $o['purchase_order_id'] ? 'selected' : '' ?>><?= e($o['number']) ?> · <?= e($o['status']) ?><?= $o['expected_on'] ? ' · expected ' . e($o['expected_on']) : '' ?></option><?php endforeach; ?>
