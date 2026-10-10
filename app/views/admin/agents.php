<?php /** The agents here (screen `agent-list`): read-only cards. Data: agents, osLinks, tz */ ?>
<?= view('shared/header.php', ['id' => 'agent-list', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Agents', null]], 'back' => back_link()]) ?>
<div class="main-content" id="agent-list-content">
    <div class="alert alert-info fs-12" id="agent-list-note"><i class="feather-info me-1"></i>Hiring, grants, duties and approvals are the kernel's — this page only shows what the agents have done here.
        <?php foreach ($osLinks as $l): ?> <a href="<?= e($l['url']) ?>" class="alert-link" id="agent-list-os-link" rel="noopener"><?= e($l['label']) ?></a><?php endforeach; ?>
        <?php if ($osLinks === []): ?><span id="agent-list-os-link" class="text-muted">The operating system's pages open from the launcher.</span><?php endif; ?></div>
    <div class="row g-3" id="agent-list-cards">
    <?php foreach ($agents as $a): ?>
        <div class="col-12 col-lg-6"><?= view('admin/partials/agent-card.php', ['a' => $a, 'tz' => $tz]) ?></div>
    <?php endforeach; ?>
    </div>
</div>
