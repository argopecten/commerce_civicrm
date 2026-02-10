# Agent Prompt: Implement Todo #3 — `custom_commerce_order_id` Silently Ignored

## Objective

Fix the broken order-to-contribution linking in `ContributionUpdater.php`. Currently, the module sets `$contribution_data['custom_commerce_order_id']` which CiviCRM API4 silently ignores because it's not valid custom field syntax. Implement proper custom field provisioning and use it for reliable contribution lookups, replacing the fragile `source` LIKE search (todo #7).

Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

### 3a. Custom field reference is silently ignored

In `src/Service/ContributionUpdater.php`, `extractContributionData()` (L153) sets:

```php
$contribution_data['custom_commerce_order_id'] = $order->id();
```

CiviCRM API4 does **not** recognize `custom_commerce_order_id` as a field name. Custom fields must use either:
- Numeric syntax: `custom_42` (where 42 is the field's numeric ID — brittle, varies per installation)
- Group.field syntax: `Commerce_Order.commerce_order_id` (stable, portable)

Since the field name is invalid, API4 silently drops it. No custom data is stored. The order-to-contribution link does not exist.

### 3b. The custom field/group doesn't exist in CiviCRM

Even with correct syntax, there is no `CustomGroup` or `CustomField` provisioned by this module. The field and group must be created in CiviCRM before they can be referenced.

### 3c. fallback lookup is fragile (todo #7)

Without a working custom field, `findExistingContribution()` (L173) falls back to:

```php
->addWhere('source', 'LIKE', '%Order #' . $order->id() . '%')
```

This matches falsely: order ID `1` matches "Order #1", "Order #10", "Order #100", etc. The `source` field is also manually editable in CiviCRM.

Additionally, the two creation methods use **different** `source` formats:
- `extractContributionData()` L133: `'Commerce Order #' . $order->id()`
- `createContributionFromOrderWithFinancialType()` L459: `'Drupal Commerce Order ' . $order->id()`

## What to implement

This fix has three parts: (A) provision the custom field, (B) write to it correctly, (C) use it for lookups.

### Part A: Provision the CiviCRM custom field group and field

Create the custom group and field during module install via `hook_install()`. The module already has a `commerce_civicrm_install()` function in `commerce_civicrm.install` (L118–L129) that provisions Drupal fields.

Add CiviCRM custom field provisioning to the install hook. The custom group/field should be created via API4 calls after CiviCRM is confirmed available.

**Custom Group definition:**

| Property | Value |
|---|---|
| `name` | `Commerce_Order` |
| `title` | `Commerce Order` |
| `extends` | `Contribution` |
| `style` | `Inline` |
| `is_active` | `TRUE` |
| `collapse_display` | `TRUE` |

**Custom Field definition:**

| Property | Value |
|---|---|
| `custom_group_id:name` | `Commerce_Order` |
| `name` | `commerce_order_id` |
| `label` | `Commerce Order ID` |
| `data_type` | `Int` |
| `html_type` | `Text` |
| `is_searchable` | `TRUE` |
| `is_active` | `TRUE` |
| `is_required` | `FALSE` |
| `is_view` | `TRUE` (read-only in CiviCRM UI — managed by the module) |

**Implementation in `commerce_civicrm.install`:**

Add a helper function `_commerce_civicrm_provision_custom_fields()` and call it from `commerce_civicrm_install()`. The function should:

1. Check if CiviCRM is available (via the `commerce_civicrm.civicrm_helper` service)
2. Check if the custom group already exists (`CustomGroup::get` where `name = 'Commerce_Order'`) — skip if present
3. Create the custom group via `\Civi\Api4\CustomGroup::create(FALSE)`
4. Create the custom field via `\Civi\Api4\CustomField::create(FALSE)`
5. Log success/failure
6. Wrap in try/catch — if CiviCRM is unavailable during install, log a warning but don't fail the install

```php
function _commerce_civicrm_provision_custom_fields() {
  try {
    if (!\Drupal::hasService('commerce_civicrm.civicrm_helper')) {
      \Drupal::logger('commerce_civicrm')->warning('CiviCRM helper not available during install — custom fields not provisioned. They will be created on first use.');
      return FALSE;
    }

    $helper = \Drupal::service('commerce_civicrm.civicrm_helper');
    if (!$helper->initialize()) {
      \Drupal::logger('commerce_civicrm')->warning('CiviCRM not available during install — custom fields not provisioned.');
      return FALSE;
    }

    // Check if custom group already exists
    $existing = \Civi\Api4\CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'Commerce_Order')
      ->setLimit(1)
      ->execute();

    if ($existing->count() > 0) {
      \Drupal::logger('commerce_civicrm')->info('Commerce_Order custom group already exists — skipping creation.');
      return TRUE;
    }

    // Create custom group
    $group_result = \Civi\Api4\CustomGroup::create(FALSE)
      ->addValue('name', 'Commerce_Order')
      ->addValue('title', 'Commerce Order')
      ->addValue('extends', 'Contribution')
      ->addValue('style', 'Inline')
      ->addValue('is_active', TRUE)
      ->addValue('collapse_display', TRUE)
      ->execute();

    // Create custom field
    \Civi\Api4\CustomField::create(FALSE)
      ->addValue('custom_group_id:name', 'Commerce_Order')
      ->addValue('name', 'commerce_order_id')
      ->addValue('label', 'Commerce Order ID')
      ->addValue('data_type', 'Int')
      ->addValue('html_type', 'Text')
      ->addValue('is_searchable', TRUE)
      ->addValue('is_active', TRUE)
      ->addValue('is_required', FALSE)
      ->addValue('is_view', TRUE)
      ->execute();

    \Drupal::logger('commerce_civicrm')->info('Commerce_Order custom group and field provisioned successfully.');
    return TRUE;
  }
  catch (\Exception $e) {
    \Drupal::logger('commerce_civicrm')->error('Failed to provision CiviCRM custom fields: @message', [
      '@message' => $e->getMessage(),
    ]);
    return FALSE;
  }
}
```

Then add to `commerce_civicrm_install()`:

```php
// Provision CiviCRM custom fields for order-to-contribution linking
_commerce_civicrm_provision_custom_fields();
```

Also add cleanup to `commerce_civicrm_uninstall()` — delete the custom group (which cascades to delete the field):

```php
// Remove CiviCRM custom fields
try {
  if (\Drupal::hasService('commerce_civicrm.civicrm_helper')) {
    $helper = \Drupal::service('commerce_civicrm.civicrm_helper');
    if ($helper->initialize()) {
      \Civi\Api4\CustomGroup::delete(FALSE)
        ->addWhere('name', '=', 'Commerce_Order')
        ->execute();
    }
  }
}
catch (\Exception $e) {
  \Drupal::logger('commerce_civicrm')->warning('Could not remove CiviCRM custom fields during uninstall: @message', [
    '@message' => $e->getMessage(),
  ]);
}
```

### Part B: Write to the custom field correctly in `ContributionUpdater`

#### B1. Fix `extractContributionData()` (L120–L157)

Replace:
```php
$contribution_data['custom_commerce_order_id'] = $order->id();
```

With:
```php
$contribution_data['Commerce_Order.commerce_order_id'] = (int) $order->id();
```

#### B2. Fix `createContributionFromOrderWithFinancialType()` (L438–L475)

This method does NOT set the custom field at all. Add it to the `$contribution_data` array:

```php
$contribution_data = [
  'contact_id' => $contact_id,
  'financial_type_id' => $financial_type_id,
  'total_amount' => $total_price->getNumber(),
  'currency' => $total_price->getCurrencyCode(),
  'source' => 'Commerce Order #' . $order->id(),  // Also standardize source format
  'contribution_status_id' => $this->getContributionStatusIdByName($contribution_status),
  'receive_date' => date('Y-m-d H:i:s'),  // Also standardize date format
  'non_deductible_amount' => 0,
  'fee_amount' => 0,
  'net_amount' => $total_price->getNumber(),
  'Commerce_Order.commerce_order_id' => (int) $order->id(),  // ADD THIS
];
```

Also standardize the `source` format (currently `'Drupal Commerce Order ' . $order->id()` — change to `'Commerce Order #' . $order->id()` to match `extractContributionData()`).

Also standardize the date format (currently `date('YmdHis')` — change to `date('Y-m-d H:i:s')` to match `extractContributionData()` and CiviCRM 6.x conventions).

### Part C: Fix `findExistingContribution()` to use the custom field

Replace the fragile LIKE search with an exact custom field lookup, falling back to the `source` field for backward compatibility with contributions created before the custom field existed.

**Current code** (L163–L188):
```php
protected function findExistingContribution(OrderInterface $order) {
  try {
    if (!$this->initializeCivicrm()) {
      return NULL;
    }
    $result = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('source', 'LIKE', '%Order #' . $order->id() . '%')
      ->setLimit(1)
      ->execute();
    if ($result->count() > 0) {
      return $result->first()['id'];
    }
  } catch (\Exception $e) { ... }
  return NULL;
}
```

**Replace with:**
```php
protected function findExistingContribution(OrderInterface $order) {
  try {
    if (!$this->initializeCivicrm()) {
      return NULL;
    }

    $order_id = (int) $order->id();

    // Primary lookup: exact match on custom field
    $result = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('Commerce_Order.commerce_order_id', '=', $order_id)
      ->setLimit(1)
      ->execute();

    if ($result->count() > 0) {
      return $result->first()['id'];
    }

    // Fallback: exact source match for pre-existing contributions
    // created before the custom field was provisioned
    $result = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('source', '=', 'Commerce Order #' . $order_id)
      ->setLimit(1)
      ->execute();

    if ($result->count() > 0) {
      $contribution_id = $result->first()['id'];
      $this->logger->info('Found contribution @cid via source fallback for order @oid — backfilling custom field', [
        '@cid' => $contribution_id,
        '@oid' => $order_id,
      ]);

      // Backfill the custom field on the legacy contribution
      try {
        \Civi\Api4\Contribution::update(FALSE)
          ->addWhere('id', '=', $contribution_id)
          ->addValue('Commerce_Order.commerce_order_id', $order_id)
          ->execute();
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not backfill custom field on contribution @cid: @error', [
          '@cid' => $contribution_id,
          '@error' => $e->getMessage(),
        ]);
      }

      return $contribution_id;
    }
  }
  catch (\Exception $e) {
    $this->logger->error('Error finding existing CiviCRM contribution: @error', [
      '@error' => $e->getMessage(),
    ]);
  }

  return NULL;
}
```

Key improvements:
- **Primary lookup** uses `Commerce_Order.commerce_order_id` with exact `=` match (no LIKE)
- **Fallback** uses exact `=` match on `source` (not LIKE) for backward compatibility
- **Backfill**: when a contribution is found via `source` fallback, the custom field is automatically populated for future lookups
- The fallback can eventually be removed once all legacy contributions have been backfilled

### Part D: Add `ensureCustomFieldExists()` to `ContributionUpdater`

Because CiviCRM may not be available during module install, add a safety method that ensures the custom field exists before writing to it. Call it from `createContribution()`:

```php
/**
 * Ensures the Commerce_Order custom group and field exist in CiviCRM.
 *
 * Creates them if missing. This handles the case where CiviCRM was
 * unavailable during module install.
 *
 * @return bool
 *   TRUE if the custom field exists or was created, FALSE on failure.
 */
protected function ensureCustomFieldExists() {
  try {
    $existing = \Civi\Api4\CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'Commerce_Order')
      ->setLimit(1)
      ->execute();

    if ($existing->count() > 0) {
      return TRUE;
    }

    // Custom group doesn't exist — provision it now
    $this->logger->info('Commerce_Order custom group not found — provisioning now.');

    \Civi\Api4\CustomGroup::create(FALSE)
      ->addValue('name', 'Commerce_Order')
      ->addValue('title', 'Commerce Order')
      ->addValue('extends', 'Contribution')
      ->addValue('style', 'Inline')
      ->addValue('is_active', TRUE)
      ->addValue('collapse_display', TRUE)
      ->execute();

    \Civi\Api4\CustomField::create(FALSE)
      ->addValue('custom_group_id:name', 'Commerce_Order')
      ->addValue('name', 'commerce_order_id')
      ->addValue('label', 'Commerce Order ID')
      ->addValue('data_type', 'Int')
      ->addValue('html_type', 'Text')
      ->addValue('is_searchable', TRUE)
      ->addValue('is_active', TRUE)
      ->addValue('is_required', FALSE)
      ->addValue('is_view', TRUE)
      ->execute();

    $this->logger->info('Commerce_Order custom group and field provisioned successfully.');
    return TRUE;
  }
  catch (\Exception $e) {
    $this->logger->error('Failed to ensure Commerce_Order custom field exists: @error', [
      '@error' => $e->getMessage(),
    ]);
    return FALSE;
  }
}
```

Call `ensureCustomFieldExists()` from `createContribution()` before the API4 create call:

```php
protected function createContribution(array $contribution_data) {
  try {
    if (!$this->initializeCivicrm()) {
      return NULL;
    }

    // Ensure the custom field exists before writing to it
    $this->ensureCustomFieldExists();

    $result = \Civi\Api4\Contribution::create(FALSE)
      ->setValues($contribution_data)
      ->execute();
    // ... rest unchanged
```

## Files to modify

1. **`src/Service/ContributionUpdater.php`** — Four changes:
   - `extractContributionData()`: replace `custom_commerce_order_id` with `Commerce_Order.commerce_order_id`
   - `createContributionFromOrderWithFinancialType()`: add `Commerce_Order.commerce_order_id`, standardize `source` format and date format
   - `findExistingContribution()`: replace LIKE search with custom field lookup + exact source fallback with backfill
   - `createContribution()`: add `ensureCustomFieldExists()` call
   - Add new `ensureCustomFieldExists()` method

2. **`commerce_civicrm.install`** — Three changes:
   - Add `_commerce_civicrm_provision_custom_fields()` helper function
   - Call it from `commerce_civicrm_install()`
   - Add custom group cleanup to `commerce_civicrm_uninstall()`

## Files NOT to modify

- `commerce_civicrm.module` — no changes
- `commerce_civicrm.services.yml` — no changes
- Other service files — not affected

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use OOP API4 style with `checkPermissions: FALSE` (matching existing `ContributionUpdater` convention)
- Custom group name must be `Commerce_Order` and field name must be `commerce_order_id` — resulting in the API4 reference `Commerce_Order.commerce_order_id`
- The install provisioning must be resilient to CiviCRM being unavailable (log warning, don't fail)
- The runtime `ensureCustomFieldExists()` handles the case where provisioning was deferred
- Standardize the `source` field format to `'Commerce Order #' . $order->id()` across both creation methods
- Cast order ID to `(int)` when writing to the custom field (it's defined as `data_type: Int`)
- Maintain existing PHPDoc blocks and add new ones for new methods

## Verification

After implementation, confirm:
1. `ContributionUpdater.php` has no syntax errors
2. `commerce_civicrm.install` has no syntax errors
3. No references to `custom_commerce_order_id` remain in the codebase
4. `extractContributionData()` sets `Commerce_Order.commerce_order_id`
5. `createContributionFromOrderWithFinancialType()` sets `Commerce_Order.commerce_order_id`
6. `findExistingContribution()` queries `Commerce_Order.commerce_order_id` first, falls back to exact `source` match
7. `source` format is consistent across both creation methods (`'Commerce Order #' . $order->id()`)
8. `commerce_civicrm_install()` calls `_commerce_civicrm_provision_custom_fields()`
9. `commerce_civicrm_uninstall()` deletes the `Commerce_Order` custom group
10. `ensureCustomFieldExists()` is called from `createContribution()` before the API4 create
