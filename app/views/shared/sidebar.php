<?php
/**
 * The sidebar's menu (sso-shell.md "The shell"): the groups of nav_groups() in order, each item a link (hx-get into #page-content,
 * hx-push-url the item's own canonical URL — never `true`), the current one highlighted, a group with no visible item not rendered;
 * the Admin group only for a holder of one of its rights. Data: groups, activeNav, here.
 */
$navlink = static function (string $id, string $url, string $icon, string $label) use ($activeNav, $here): string {
    $active = $activeNav === $id || ($activeNav === '' && $here === $url) ? ' active' : '';
    return '<li class="nxl-item" id="nav-' . e($id) . '">'
        . '<a class="nxl-link' . $active . '" href="' . e($url) . '"'
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML"'
        . ' hx-push-url="' . e($url) . '">'
        . '<span class="nxl-micon"><i class="' . e($icon) . '"></i></span>'
        . '<span class="nxl-mtext">' . e($label) . '</span>'
        . '</a></li>';
};
?>
<ul class="nxl-navbar" id="shell-sidebar">
    <?php foreach ($groups as $groupLabel => $items): ?>
        <?php $shown = array_filter($items, static fn (array $i): bool => nav_has_right($i[4])); if ($shown === []) { continue; } ?>
        <li class="nxl-item nxl-caption" id="nav-group-<?= e(strtolower($groupLabel)) ?>"><label><?= e($groupLabel) ?></label></li>
        <?php foreach ($shown as $item) { echo $navlink($item[0], $item[1], $item[2], $item[3]); } ?>
    <?php endforeach; ?>
</ul>
