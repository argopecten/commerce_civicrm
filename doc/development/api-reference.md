# CiviCRM API4 Usage Patterns

All CiviCRM operations in this module use **API4** with the **OOP calling convention** (`\Civi\Api4\Entity::action(FALSE)`).

| Service | API Calls | Style |
|---|---|---|
| `CivicrmHelper` | 2 | OOP `\Civi\Api4\*` |
| `ContactUpdater` | 13 | OOP `\Civi\Api4\*` |
| `ContributionUpdater` | 13 | OOP `\Civi\Api4\*` |
| `MembershipUpdater` | 13 | OOP `\Civi\Api4\*` |
| `MailingUpdater` | 4 | OOP `\Civi\Api4\*` |
| `OrderCivicrmUpdater` | 9 | OOP `\Civi\Api4\*` |

No procedural `civicrm_api4()` calls remain. No legacy `CRM_*` class usage in source code.

All calls pass `checkPermissions = FALSE` (the first argument to every action), meaning they run with full access regardless of the logged-in user's CiviCRM permissions.

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
2. Checks `$this->civicrm` service is available (injected as `@?civicrm`).
3. Calls `$this->civicrm->initialize()` (CiviCRM bootstrap).
4. Checks `isInMaintenanceMode()` — returns FALSE during CiviCRM upgrades or maintenance.

---

## Entities used

### Contact

```php
// Find by UFMatch (Drupal user → CiviCRM contact)
\Civi\Api4\UFMatch::get(FALSE)
  ->addWhere('uf_id', '=', $drupal_uid)
  ->execute();

// Create
\Civi\Api4\Contact::create(FALSE)
  ->setValues([
    'contact_type' => 'Individual',
    'first_name'   => $first,
    'last_name'    => $last,
    ...
  ])
  ->execute();

// Update
\Civi\Api4\Contact::update(FALSE)
  ->setValues(['id' => $id, ...])
  ->execute();
```

### Email

```php
// Find primary email for contact
\Civi\Api4\Email::get(FALSE)
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('is_primary', '=', TRUE)
  ->execute();

// Create
\Civi\Api4\Email::create(FALSE)
  ->addValue('contact_id', $contact_id)
  ->addValue('email', $email)
  ->addValue('is_primary', TRUE)
  ->execute();
```

### Contribution

```php
// Find by custom field (primary lookup)
\Civi\Api4\Contribution::get(FALSE)
  ->addWhere('Commerce_Order.commerce_order_id', '=', $order->id())
  ->execute();

// Fallback: find by source (exact match, for legacy contributions)
\Civi\Api4\Contribution::get(FALSE)
  ->addWhere('source', '=', 'Commerce Order #' . $order->id())
  ->execute();

// Create
\Civi\Api4\Contribution::create(FALSE)
  ->setValues([
    'contact_id'                       => $contact_id,
    'financial_type_id'                => $financial_type_id,
    'total_amount'                     => $amount,
    'currency'                         => $currency,
    'contribution_status_id'           => $status_id,
    'receive_date'                     => DrupalDateTime::createFromTimestamp($timestamp)->format('Y-m-d H:i:s'),
    'source'                           => 'Commerce Order #' . $order->id(),
    'Commerce_Order.commerce_order_id' => $order->id(),
  ])
  ->execute();

// Cancel
\Civi\Api4\Contribution::update(FALSE)
  ->addWhere('id', '=', $contribution_id)
  ->addValue('contribution_status_id', $cancelled_status_id)
  ->execute();
```

### FinancialType

```php
\Civi\Api4\FinancialType::get(FALSE)
  ->addSelect('id', 'name', 'label')
  ->addWhere('is_active', '=', TRUE)
  ->addOrderBy('label', 'ASC')
  ->execute();
```

### OptionValue (status lookups)

```php
// Contribution status
\Civi\Api4\OptionValue::get(FALSE)
  ->addSelect('value')
  ->addWhere('option_group_id:name', '=', 'contribution_status')
  ->addWhere('name', '=', 'Completed')
  ->execute();

// Payment instrument
\Civi\Api4\OptionValue::get(FALSE)
  ->addSelect('value')
  ->addWhere('option_group_id:name', '=', 'payment_instrument')
  ->addWhere('name', '=', 'Credit Card')
  ->execute();
```

### Membership (OOP style)

```php
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\MembershipStatus;

// Create — only join_date and start_date; CiviCRM calculates end_date
Membership::create(FALSE)
  ->addValue('contact_id', $contact_id)
  ->addValue('membership_type_id', $type_id)
  ->addValue('join_date', $today->format('Y-m-d'))
  ->addValue('start_date', $today->format('Y-m-d'))
  ->addValue('source', 'Commerce Order #42')
  ->addValue('status_id', $status_id)
  ->execute();

// Find existing (New / Current / Grace)
Membership::get(FALSE)
  ->addSelect('id', 'status_id', 'end_date')
  ->addWhere('contact_id', '=', $contact_id)
  ->addWhere('membership_type_id', '=', $type_id)
  ->addWhere('status_id:name', 'IN', ['New', 'Current', 'Grace'])
  ->setLimit(1)
  ->execute();

// Cancel
Membership::update(FALSE)
  ->addWhere('id', '=', $membership_id)
  ->addValue('status_id', $cancelled_status_id)
  ->execute();
```

### MembershipType / MembershipStatus

```php
MembershipType::get(FALSE)
  ->addSelect('id', 'name', 'label', 'duration_unit', 'duration_interval', 'period_type')
  ->addWhere('id', '=', $type_id)
  ->setLimit(1)
  ->execute();

MembershipStatus::get(FALSE)
  ->addWhere('name', '=', 'Current')
  ->addWhere('is_active', '=', TRUE)
  ->execute();
```

---

## Error handling pattern

Every API call is wrapped in try/catch:

```php
try {
  if (!$this->civicrmHelper->initialize()) {
    return NULL;
  }
  $result = \Civi\Api4\Entity::action(FALSE)
    ->...
    ->execute();
  // process result
} catch (\CRM_Core_Exception $e) {
  $this->logger->error('Context message: @error', [
    '@error' => $e->getMessage(),
  ]);
  return NULL; // or FALSE
}
```

All catch blocks use `\CRM_Core_Exception` (not generic `\Exception`). The only exception is `CivicrmHelper::initialize()` which intentionally catches `\Exception` because CiviCRM initialization can throw various exception types depending on installation state.

No CiviCRM exception is allowed to bubble up to the caller.
