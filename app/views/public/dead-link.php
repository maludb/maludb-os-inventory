<?php /** The one dead page (404) and the slow-down page (429). Data: settings, title, message */
$content = '<div class="card mt-4"><div class="card-body text-center p-4"><h4 class="mb-2" id="public-order-dead-title">' . e($title) . '</h4><p class="text-muted mb-0" id="public-order-dead-message">' . e($message) . '</p>'
    . (!empty($settings['business_contact_email']) ? '<p class="mt-3 mb-0"><a href="mailto:' . e($settings['business_contact_email']) . '">' . e($settings['business_contact_email']) . '</a></p>' : '') . '</div></div>';
echo view('public/layout.php', ['title' => $title, 'content' => $content, 'settings' => $settings]);
