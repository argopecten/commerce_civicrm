# Hooks, Events & Extension Points

## Drupal hooks implemented

### `hook_help` (`commerce_civicrm_help`)

Provides the module help page at `/admin/help/commerce_civicrm`.

### `hook_runtime_requirements` (`commerce_civicrm_runtime_requirements`)

Reports CiviCRM availability on the status-report page. Uses
`CivicrmHelper::isAvailable()`.

### `hook_install` (`commerce_civicrm_install`)

Adds the `field_civicrm` field to every existing Commerce product type and
provisions the CiviCRM `Commerce_Order` custom group (fields
`commerce_order_id`, `commerce_payment_id`).

### `hook_uninstall` (`commerce_civicrm_uninstall`)

Deletes the module config, removes `field_civicrm` from all product bundles,
and removes the CiviCRM `Commerce_Order` custom group. CiviCRM
contributions/memberships created by the module are left in place.

### `hook_commerce_product_type_insert` / `hook_form_commerce_product_form_alter`

Auto-adds `field_civicrm` to new product types and renders the "CiviCRM
Integration" section on the product form (see
[product-field-schema.md](product-field-schema.md)).

---

## Order processing trigger

### `OrderCompleteSubscriber`

**Service ID:** `commerce_civicrm.order_complete_subscriber` — priority `-50`.

Subscribes to the **group-level** `commerce_order.post_transition` event,
which state_machine dispatches for *every* transition of every order
workflow. The transition ID is matched against `commerce_civicrm.settings`:

| Setting | Effect |
|---|---|
| `order.create_transitions` | Transition IDs that call `OrderCivicrmUpdater::processOrder()` |
| `order.cancel_transitions` | Transition IDs that call `processCancellation()` |
| `order.workflows` | Optional workflow-ID allowlist (empty = all) |

Because processing is idempotent per order (the
`Commerce_Order.commerce_order_id` custom field), it is safe to list several
transitions (e.g. `[paid, completed]`) to cover workflows that chain
transitions inside a single order save — state_machine only dispatches an
event for the **last** transition applied in a save.

---

## Dispatched events (`\Drupal\commerce_civicrm\Event\CommerceCivicrmEvents`)

| Constant | Event class | When | Typical use |
|---|---|---|---|
| `ORDER_ITEM_DIRECTIVES` | `OrderItemDirectivesEvent` | While resolving each order item into directives | Replace/expand directives: bundle-component splitting, per-variation membership types |
| `MEMBERSHIP_DATES` | `MembershipDatesEvent` | While membership line items are built, when `membership.date_mode: dispatch` | Supply authoritative join/start/end dates (e.g. from a Drupal-side expiry field) |
| `CONTRIBUTION_PARAMS` | `ContributionParamsEvent` | Immediately before `\Civi\Api4\Order::create` | Alter contribution values or line items |
| `ORDER_PROCESSED` | `OrderProcessedEvent` | After records were created for an order | Follow-up records (activities, notes), notifications |
| `ORDER_CANCELLED` | `OrderProcessedEvent` | After a cancellation was processed | Follow-up cleanup |
| `RENEWAL_RECORDED` | `RenewalRecordedEvent` | After `RenewalProcessor::recordRenewalPayment()` created a renewal contribution | Notifications, reconciliation |

A *directive* is an associative array; the canonical key list is documented
on `OrderItemDirectivesEvent`. All events carry the processing `$context`
(`phase` = `create` | `cancel` | `renewal`, plus transition info when
triggered by a workflow transition).

`ContributionParamsEvent::getLineItems()` returns the **flat** Order-API line
item arrays (related-entity values as `entity_id.FIELD` keys) exactly as they
will be passed to `Order::create` — see
[api-reference.md](api-reference.md).

---

## Renewal API

`commerce_civicrm.renewal_processor` →
`RenewalProcessor::recordRenewalPayment(OrderInterface $order, PaymentInterface $payment): array`

Records an additional **completed** payment on an already-processed order as
a renewal contribution (idempotent per payment via
`Commerce_Order.commerce_payment_id` / `trxn_id`). Membership line items
reference the existing memberships (numeric `entity_id`), which extends them;
directive amounts are scaled proportionally to the payment amount
(`Util\AmountSplitter`).

Recurring charges usually happen without an order-state transition, so site
code must call this — e.g. from a `PaymentEvents::PAYMENT_INSERT` subscriber
that runs after the site's own payment handling:

```php
public static function getSubscribedEvents(): array {
  return [PaymentEvents::PAYMENT_INSERT => ['onPaymentInsert', 50]];
}

public function onPaymentInsert(PaymentEvent $event): void {
  $payment = $event->getPayment();
  if ($payment->getState()->getId() === 'completed') {
    $this->renewalProcessor->recordRenewalPayment($payment->getOrder(), $payment);
  }
}
```

---

## Drush command

`drush commerce-civicrm:process-order <order_ids>` (alias `ccv-process`)
replays one or more orders through `processOrder()` with
`context = ['phase' => 'create', 'trigger' => 'drush']`. Idempotent — orders
that already have a linked contribution are skipped. Useful for orders paid
while the module was disabled or during a deployment window.

---

## Other extension mechanisms

1. **Service decoration** — `decorates: commerce_civicrm.contact_updater` (or
   any module service) to override behavior while keeping the inner service.
2. **Additional subscribers** on `commerce_order.post_transition` at a
   priority after `-50`.
3. **A second `hook_form_commerce_product_form_alter`** to extend the product
   form section.
