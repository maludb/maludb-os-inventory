<?php
declare(strict_types=1);

/**
 * The kinds of notice (returns-worker.md "Notifications"): every value of notifications.kind (db/013) with its label, its feather icon and its colour — the bell's
 * row, the outbox's e-mail and the settings screen read the one table. A kind the table does not know is a plain notice (never an error).
 */
const NOTIFY_KINDS = [
    'watch'         => ['label' => 'A watch fired',            'icon' => 'feather-eye',            'color' => 'info'],
    'line_at_risk'  => ['label' => 'A sold line is at risk',   'icon' => 'feather-alert-triangle', 'color' => 'danger'],
    'pull_failed'   => ['label' => 'A pull failed',            'icon' => 'feather-download-cloud', 'color' => 'warning'],
    'pull_blocked'  => ['label' => 'A source is blocked',      'icon' => 'feather-slash',          'color' => 'danger'],
    'po_ack'        => ['label' => 'A supplier acknowledged',  'icon' => 'feather-check',          'color' => 'success'],
    'po_decline'    => ['label' => 'A supplier declined a line', 'icon' => 'feather-x-circle',     'color' => 'danger'],
    'po_tracking'   => ['label' => 'A supplier added tracking', 'icon' => 'feather-truck',         'color' => 'info'],
    'return'        => ['label' => 'A return',                 'icon' => 'feather-rotate-ccw',     'color' => 'warning'],
    'morning_note'  => ['label' => 'The morning note',         'icon' => 'feather-sunrise',        'color' => 'secondary'],
    'mention'       => ['label' => 'A mention',                'icon' => 'feather-at-sign',        'color' => 'info'],
    'order'         => ['label' => 'An order',                 'icon' => 'feather-shopping-bag',   'color' => 'info'],
    'agent_drafted' => ['label' => 'An agent drafted',         'icon' => 'feather-cpu',            'color' => 'secondary'],
    'unmatched'     => ['label' => 'Unmatched listings',       'icon' => 'feather-link',           'color' => 'secondary'],
];

/** ['label', 'icon', 'color'] of a kind. */
function notification_kind(string $kind): array
{
    return NOTIFY_KINDS[$kind] ?? ['label' => ucfirst(str_replace('_', ' ', $kind)), 'icon' => 'feather-bell', 'color' => 'secondary'];
}
