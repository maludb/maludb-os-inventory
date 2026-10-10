<?php
declare(strict_types=1);
/** /customers/new and /customers/{id}/edit (screens `customer-add`, `customer-edit`). customers.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
require_right('customers.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('customer');
$cur = $id === null ? null : customer_or_404($pdo, $id);
$screen = $cur === null ? 'customer-add' : 'customer-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['customer' => $cur === null ? null : present_customer($cur), 'sources' => CUSTOMER_SOURCES, 'tax_rates' => tax_rates_live($pdo)]);
}
render_screen($cur === null ? 'New customer' : 'Change ' . $cur['name'], view('customers/form.php', ['cur' => $cur, 'rates' => tax_rates_live($pdo)]),
    ['activeNav' => 'customer-list', 'screen' => $screen, 'entity' => 'customer', 'recordId' => $id === null ? '' : (string) $id]);
