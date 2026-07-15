# CiviCRM API4 Usage Patterns

All CiviCRM operations in this module use **API4** with the **OOP calling
convention** (`\Civi\Api4\Entity::action(FALSE)`). There are no procedural
`civicrm_api4()` calls and no legacy `CRM_*` class usage.

All calls pass `checkPermissions = FALSE` (the first argument to every
action), meaning they run with full access regardless of the logged-in user's
CiviCRM permissions.

The module requires **CiviCRM ≥ 6.16** — it relies on the current API4
`Order` / `Payment` contract (flat line items with `entity_id.FIELD` keys,
Pending-then-Payment completion flow).

---

## Bootstrap sequence

Before any API call, a service must:

```php
if (!$this->civicrmHelper->initialize()) {
  return NULL;
}
```

`initialize()` does:
1. Checks `$this->moduleHandler->moduleExists('civicrm')` (injected `ModuleHandlerInterface`).
2. Checks the `civicrm` service is available (injected as `@?civicrm`).
3. Calls `$this->civicrm->initialize()` (CiviCRM bootstrap).
4. Rejects maintenance mode (`CIVICRM_UPGRADE_ACTIVE` constant, or the
   `environment` setting being `Maintenance`).

Write pipelines use the stronger `isReadyForOperations()` gate.

---

## Order — one contribution per Commerce order

The central write happens in `OrderCivicrmUpdater::createCiviOrder()`:

```php
$api = \Civi\Api4\Order::create(FALSE)
  ->setContributionValues([
    'contact_id'        => $contact_id,
    'financial_type_id' => $header_financial_type_id,
    'currency'          => $currency,
    'receive_date'      => $receive_date,               // 'Y-m-d H:i:s'
    'source'            => 'Commerce Order #' . $order->id(),
    'Commerce_Order.commerce_order_id' => (int) $order->id(),
    // when a Commerce payment exists:
    'Commerce_Order.commerce_payment_id' => (int) $payment->id(),
    'trxn_id'                => $payment->getRemoteId(),
    'payment_instrument_id'  => $instrument_id,
  ]);
foreach ($line_items as $line) {
  $api->addLineItem($line);
}
$result = $api->execute();
```

Line items are **flat** `LineItem` arrays. Values for the related entity are
passed as `entity_id.FIELD` keys; a numeric `entity_id` makes the Order API
**update** that entity instead of creating one (membership renewal):

```php
// Membership line (creates — or, with entity_id, renews — a membership):
[
  'membership_type_id' => $membership_type_id,
  'financial_type_id'  => $financial_type_id,
  'label'              => $label,
  'qty'                => $qty,
  'unit_price'         => $unit_price,
  'line_total'         => $line_total,
  'entity_id'          => $existing_membership_id,      // renewal only
  'entity_id.join_date'  => '2026-07-14',               // date_mode-dependent
  'entity_id.start_date' => '2026-07-14',
  'entity_id.end_date'   => '2027-07-13',               // dispatch mode only
  'entity_id.source'     => 'Commerce Order #42',
]

// Participant line (event product):
[
  'entity_table'            => 'civicrm_participant',
  'financial_type_id'       => $financial_type_id,      // default: Event Fee
  'label'                   => $label,
  'qty'                     => $qty,
  'unit_price'              => $unit_price,
  'line_total'              => $line_total,
  'entity_id.event_id'      => $event_id,
  'entity_id.status_id:name'=> 'Registered',
  'entity_id.role_id'       => $participant_role_id,    // optional
  'entity_id.source'        => 'Commerce Order #42',
]

// Plain contribution line:
[
  'financial_type_id' => $financial_type_id,
  'label'             => $label,
  'qty'               => $qty,
  'unit_price'        => $unit_price,
  'line_total'        => $line_total,
]
```

The Order API always creates the contribution as **Pending** and computes the
total from the line items.

## Payment — completing the contribution

When the order is paid, a follow-up Payment records the money and flips the
contribution (and its memberships/participants) to **Completed** with proper
financial transactions:

```php
\Civi\Api4\Payment::create(FALSE)
  ->setValues([
    'contribution_id'       => $contribution_id,
    'total_amount'          => $total,
    'trxn_date'             => $receive_date,
    'trxn_id'               => $payment?->getRemoteId(),
    'payment_instrument_id' => $instrument_id,
  ])
  ->execute();
```

Caveat handled by the module: CiviCRM's payment-completion actions renew
memberships by one term, overriding explicitly supplied end dates. When dates
were dispatched (`membership.date_mode: dispatch`), the module re-asserts
them afterwards with `Membership::update`.

## LineItem — reading back what was created

```php
\Civi\Api4\LineItem::get(FALSE)
  ->addSelect('entity_table', 'entity_id')
  ->addWhere('contribution_id', '=', $contribution_id)
  ->execute();
```

Used to collect created membership/participant IDs and to cancel exactly what
an order created.

---

## Contribution — lookups and cancellation

```php
// Idempotency: find by custom field (primary lookup)
\Civi\Api4\Contribution::get(FALSE)
  ->addSelect('id')
  ->addWhere('Commerce_Order.commerce_order_id', '=', $order_id)
  ->addOrderBy('id', 'ASC')
  ->execute();

// Legacy fallback: exact source match (the custom field is backfilled on hit)
\Civi\Api4\Contribution::get(FALSE)
  ->addWhere('source', '=', 'Commerce Order #' . $order_id)
  ->execute();

// Per-payment idempotency (renewals)
\Civi\Api4\Contribution::get(FALSE)
  ->addWhere('Commerce_Order.commerce_payment_id', '=', $payment_id)
  ->execute();
// …falling back to ->addWhere('trxn_id', '=', $remote_id)

// Cancel
\Civi\Api4\Contribution::update(FALSE)
  ->addWhere('id', '=', $contribution_id)
  ->addValue('contribution_status_id:name', 'Cancelled')
  ->execute();
```

## Contact / UFMatch / Email

```php
// Drupal user → CiviCRM contact
\Civi\Api4\UFMatch::get(FALSE)
  ->addWhere('uf_id', '=', $drupal_uid)
  ->execute();

// Dedupe-rule matching (match_or_create fallback)
\Civi\Api4\Contact::getDuplicates(FALSE)
  ->setValues(['first_name' => $first, 'last_name' => $last, 'email' => $email])
  ->setDedupeRule('Individual.Supervised')
  ->execute();

// Create (address fields use address_primary.* join paths)
\Civi\Api4\Contact::create(FALSE)
  ->setValues([
    'contact_type' => 'Individual',
    'first_name'   => $first,
    'last_name'    => $last,
    'address_primary.street_address'         => $street,
    'address_primary.city'                   => $city,
    'address_primary.postal_code'            => $zip,
    'address_primary.state_province_id:abbr' => $state,
    'address_primary.country_id:name'        => $country_code,
  ])
  ->execute();

// Primary email is maintained separately
\Civi\Api4\Email::create(FALSE)
  ->addValue('contact_id', $contact_id)
  ->addValue('email', $email)
  ->addValue('is_primary', TRUE)
  ->execute();
```

## Membership / MembershipType / MembershipStatus

Memberships are created/renewed through Order API line items (above); direct
Membership calls are lookups, cancellation and the dispatched-dates
re-assertion:

```php
// Renewable membership lookup
\Civi\Api4\Membership::get(FALSE)
  ->addSelect('id', 'status_id', 'join_date', 'start_date', 'end_date')
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('membership_type_id', '=', $type_id)
  ->addWhere('status_id:name', 'IN', ['New', 'Current', 'Grace'])
  ->setLimit(1)
  ->execute();

// Cancel (status override so CiviCRM's status rules don't flip it back)
\Civi\Api4\Membership::update(FALSE)
  ->addWhere('id', '=', $membership_id)
  ->setValues([
    'status_id'   => $cancelled_status_id,
    'is_override' => TRUE,
    'source'      => 'Commerce Order #42 (Cancelled)',
  ])
  ->execute();

// Type details for line building / date logic
\Civi\Api4\MembershipType::get(FALSE)
  ->addSelect('id', 'name', 'financial_type_id', 'duration_unit',
              'duration_interval', 'period_type')
  ->addWhere('id', '=', $type_id)
  ->execute();
```

## Participant

```php
\Civi\Api4\Participant::update(FALSE)
  ->addWhere('id', '=', $participant_id)
  ->addValue('status_id:name', 'Cancelled')
  ->addValue('source', 'Commerce Order #42 (Cancelled)')
  ->execute();
```

## GroupContact (mailing subscriptions)

```php
\Civi\Api4\GroupContact::create(FALSE)   // or ::update on an existing record
  ->addValue('contact_id', $contact_id)
  ->addValue('group_id', $group_id)
  ->addValue('status', $double_opt_in ? 'Pending' : 'Added')
  ->execute();
```

Removal sets `status` to `Removed`.

## Name → ID resolution and option values

Product configuration stores type references by **name**;
`CivicrmHelper::resolveEntityId()` resolves them generically (per-request
cached):

```php
\Civi\Api4\MembershipType::get(FALSE)    // FinancialType / Group analogous
  ->addSelect('id')
  ->addWhere('name', '=', $reference)
  ->setLimit(1)
  ->execute();
// Groups additionally fall back to a title match.

\Civi\Api4\OptionValue::get(FALSE)
  ->addSelect('value')
  ->addWhere('option_group_id:name', '=', 'payment_instrument')
  ->addWhere('name', '=', $instrument_name)
  ->execute();
```

## CustomGroup / CustomField provisioning

`CivicrmHelper::ensureCommerceOrderCustomFields()` (run at install and before
each write pipeline):

```php
\Civi\Api4\CustomGroup::create(FALSE)
  ->addValue('name', 'Commerce_Order')
  ->addValue('title', 'Commerce Order')
  ->addValue('extends', 'Contribution')
  ->execute();

\Civi\Api4\CustomField::create(FALSE)
  ->addValue('custom_group_id:name', 'Commerce_Order')
  ->addValue('name', 'commerce_order_id')       // and commerce_payment_id
  ->addValue('data_type', 'Int')
  ->addValue('html_type', 'Text')
  ->addValue('is_searchable', TRUE)
  ->addValue('is_view', TRUE)
  ->execute();
```

## Option lists for the product form

Active financial types, membership types (`[name => label]`), events
(`[id => title]`), participant roles (`[value => label]`) and mailing groups
(`[name => title]`, `group_type` contains *Mailing List*) — see
`ContactUpdater` / `MembershipUpdater` in [services.md](services.md).

---

## Error handling pattern

Every API call site is wrapped in try/catch:

```php
try {
  if (!$this->civicrmHelper->initialize()) {
    return NULL;
  }
  $result = \Civi\Api4\Entity::action(FALSE)
    ->…
    ->execute();
} catch (\CRM_Core_Exception $e) {
  $this->logger->error('Context message: @error', ['@error' => $e->getMessage()]);
  return NULL; // or FALSE / []
}
```

Catch blocks use `\CRM_Core_Exception`, with two deliberate exceptions:
`CivicrmHelper::initialize()` catches `\Exception` (bootstrap can throw
various types), and the per-line cancellation loop in
`OrderCivicrmUpdater::processCancellation()` catches `\Throwable` so one
failed cancellation cannot block the others.

No CiviCRM exception is allowed to bubble up into checkout.
