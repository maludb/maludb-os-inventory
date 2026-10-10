<?php
/**
 * Home (screen `home`, reports-admin.md): the regions of design §9 as cards `#home-{region}`, each a count that opens its list; a region the person has no right to is not shown; an empty one says what will appear.
 * The order on a phone: the note, at risk, my orders or the warehouse block, today, sources, unmatched, purchase orders, the admin, then what is unread. Data: s (home_summary), tz, seesCost
 */
$may = $s['may'];
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <div class="row g-3">
        <div class="col-12 col-lg-6"><?= view('home/partials/note.php', ['s' => $s]) ?></div>
        <div class="col-12 col-lg-6"><?= view('home/partials/at-risk.php', ['s' => $s]) ?></div>
        <?php if ($may['sales']): ?><div class="col-12 col-lg-6"><?= view('home/partials/my-orders.php', ['s' => $s]) ?></div><?php endif; ?>
        <?php if ($may['warehouse']): ?><div class="col-12 col-lg-6"><?= view('home/partials/warehouse.php', ['s' => $s]) ?></div><?php endif; ?>
        <div class="col-12 col-lg-6"><?= view('home/partials/today.php', ['s' => $s]) ?></div>
        <div class="col-12 col-lg-6"><?= view('home/partials/sources.php', ['s' => $s, 'tz' => $tz]) ?></div>
        <?php if ($may['match']): ?><div class="col-12 col-lg-6"><?= view('home/partials/unmatched.php', ['s' => $s]) ?></div><?php endif; ?>
        <div class="col-12 col-lg-6"><?= view('home/partials/po-ack.php', ['s' => $s]) ?></div>
        <?php if ($may['admin'] && $s['admin'] !== null): ?><div class="col-12 col-lg-6"><?= view('home/partials/admin.php', ['s' => $s]) ?></div><?php endif; ?>
        <div class="col-12 col-lg-6"><?= view('home/partials/bell.php', ['s' => $s, 'tz' => $tz]) ?></div>
    </div>
</div>
