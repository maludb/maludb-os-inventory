<?php /** The e-mail of a notice to a member (returns-worker.md "The sender"). Data: title, body, url (absolute, or null), business. Nothing of another person's details. */
$paras = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $body) ?: []), static fn (string $p): bool => $p !== ''));
?>
<div style="font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; color: #283c50">
  <h2 style="margin: 0 0 12px; font-size: 18px"><?= e($title) ?></h2>
<?php foreach ($paras as $p): ?>
  <p style="white-space: pre-line; margin: 0 0 12px"><?= e($p) ?></p>
<?php endforeach; ?>
<?php if ($url !== null && $url !== ''): ?>
  <p style="margin: 16px 0"><a href="<?= e($url) ?>" style="background: #3454d1; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px">Open it</a></p>
  <p style="color: #6b7885; font-size: 12px; margin: 0 0 12px"><?= e($url) ?></p>
<?php endif; ?>
  <p style="color: #6b7885; font-size: 12px; margin: 16px 0 0">Sent by <?= e($business) ?> — you are told about what you chose in your settings.</p>
</div>
