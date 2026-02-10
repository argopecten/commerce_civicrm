# Agent Prompt: Implement Todo #1 — Missing Helper Functions (Fatal Errors)

## Objective

Implement the three missing procedural helper functions in `commerce_civicrm.module` that currently cause fatal PHP errors when called: `_commerce_civicrm_get_events()`, `_commerce_civicrm_get_participant_roles()`, and `_commerce_civicrm_get_mailing_groups()`. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `commerce_civicrm.module`, the `commerce_civicrm_form_commerce_product_form_alter()` function (starting at L92) builds a product edit form with four entity-type options: `contribution`, `membership`, `event`, and `mailing`. The `membership` and `contribution` options work because their helper functions exist:

- `_commerce_civicrm_get_membership_types()` (L351) — delegates to `commerce_civicrm.membership_updater` service → `getMembershipTypes()`
- `_commerce_civicrm_get_financial_types()` (L372) — delegates to `commerce_civicrm.contact_updater` service → `getFinancialTypes()`

But three functions called on L177, L193, and L209 **do not exist anywhere**:

| Line | Missing function | Used by form element | CiviCRM entity |
|------|-----------------|---------------------|----------------|
| 177 | `_commerce_civicrm_get_events()` | `civicrm[event_id]` select | Event |
| 193 | `_commerce_civicrm_get_participant_roles()` | `civicrm[participant_role_id]` select | OptionValue (participant_role) |
| 209 | `_commerce_civicrm_get_mailing_groups()` | `civicrm[mailing_group_id]` select | Group (mailing type) |

This causes a **fatal PHP error** on any site where CiviCRM is available and a product edit form is rendered with `entity` set to `event` or `mailing`.

## What to implement

### 1. Add three methods to existing services

Follow the exact pattern used by `ContactUpdater::getFinancialTypes()` (L441–L473 of `src/Service/ContactUpdater.php`) and `MembershipUpdater::getMembershipTypes()` (L443–L476 of `src/Service/MembershipUpdater.php`). The pattern is:

1. Initialize CiviCRM via `$this->civicrmHelper->initialize()` (or `$this->initializeCivicrm()` if the class has the wrapper)
2. Call CiviCRM API4 to fetch active entities
3. Return an options array keyed by ID with a display label value
4. Catch `\Exception`, log the error, return a fallback error array

**Place the new methods on `ContactUpdater`** (since it already holds `getFinancialTypes()` and has the `civicrmHelper` dependency injected). Add these three public methods:

#### `getEvents(): array`
```php
public function getEvents() {
  // Initialize CiviCRM, return fallback on failure
  // Query: \Civi\Api4\Event::get(FALSE)
  //   ->addSelect('id', 'title', 'start_date')
  //   ->addWhere('is_active', '=', TRUE)
  //   ->addOrderBy('start_date', 'DESC')
  //   ->setLimit(0)
  //   ->execute();
  // Build options: $options[$event['id']] = $event['title']
  // Add empty prompt: '' => t('- Select an event -')
  // Catch exceptions, log, return error fallback
}
```

#### `getParticipantRoles(): array`
```php
public function getParticipantRoles() {
  // Initialize CiviCRM, return fallback on failure
  // Query: \Civi\Api4\OptionValue::get(FALSE)
  //   ->addSelect('value', 'label')
  //   ->addWhere('option_group_id:name', '=', 'participant_role')
  //   ->addWhere('is_active', '=', TRUE)
  //   ->addOrderBy('weight', 'ASC')
  //   ->setLimit(0)
  //   ->execute();
  // Build options: $options[$role['value']] = $role['label']
  // Add empty prompt: '' => t('- Select a participant role -')
  // Catch exceptions, log, return error fallback
}
```

#### `getMailingGroups(): array`
```php
public function getMailingGroups() {
  // Initialize CiviCRM, return fallback on failure
  // Query: \Civi\Api4\Group::get(FALSE)
  //   ->addSelect('id', 'title')
  //   ->addWhere('is_active', '=', TRUE)
  //   ->addWhere('group_type:name', 'CONTAINS', 'Mailing List')
  //   ->addOrderBy('title', 'ASC')
  //   ->setLimit(0)
  //   ->execute();
  // Build options: $options[$group['id']] = $group['title']
  // Add empty prompt: '' => t('- Select a mailing group -')
  // Catch exceptions, log, return error fallback
}
```

Use OOP API4 style (`\Civi\Api4\Entity::action(FALSE)`) — **not** the procedural `civicrm_api4()` wrapper — to match `ContactUpdater`'s existing convention.

### 2. Add three procedural wrapper functions in `commerce_civicrm.module`

Place them after `_commerce_civicrm_get_financial_types()` (which ends around L390), following the identical delegation pattern. Each function:

1. Guard with `_commerce_civicrm_is_available()` check, returning `['' => t('CiviCRM not available')]` if false
2. Get the `commerce_civicrm.contact_updater` service via `\Drupal::service()`
3. Call the corresponding new method
4. Catch `\Exception`, log the error, return error fallback

```php
function _commerce_civicrm_get_events() {
  if (!_commerce_civicrm_is_available()) {
    return ['' => t('CiviCRM not available')];
  }
  try {
    $contact_updater = \Drupal::service('commerce_civicrm.contact_updater');
    return $contact_updater->getEvents();
  } catch (\Exception $e) {
    \Drupal::logger('commerce_civicrm')->error('Failed to get events: @message', ['@message' => $e->getMessage()]);
    return ['' => t('Error loading events')];
  }
}
```

Same pattern for `_commerce_civicrm_get_participant_roles()` and `_commerce_civicrm_get_mailing_groups()`.

## Files to modify

1. **`src/Service/ContactUpdater.php`** — Add three new public methods (`getEvents()`, `getParticipantRoles()`, `getMailingGroups()`) before the existing `private function initializeCivicrm()` at the end of the class.

2. **`commerce_civicrm.module`** — Add three new procedural functions (`_commerce_civicrm_get_events()`, `_commerce_civicrm_get_participant_roles()`, `_commerce_civicrm_get_mailing_groups()`) after `_commerce_civicrm_get_financial_types()`.

## Files NOT to modify

- `commerce_civicrm.services.yml` — no new services needed; `ContactUpdater` already exists
- `commerce_civicrm.install` — no update hooks needed
- No new files needed

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use OOP API4 style with `checkPermissions: FALSE` (passed as `FALSE` to the static factory, e.g. `Event::get(FALSE)`)
- Include proper PHPDoc blocks on all new methods and functions
- Use `$this->logger->error()` for service-level logging (matching existing pattern)
- Use `\Drupal::logger('commerce_civicrm')->error()` for procedural function logging (matching existing pattern)
- Use `t()` for user-facing option strings in the procedural functions (matching existing pattern in the `.module` file)
- All API4 queries must use `->setLimit(0)` to return all results (matching existing pattern)
- Return `['' => t('...')]` on error (matching `getFinancialTypes()` and `getMembershipTypes()` patterns)

## Verification

After implementation, confirm:
1. No undefined function errors exist in `commerce_civicrm.module`
2. `ContactUpdater.php` has no syntax errors
3. The three new procedural functions follow the same signature and pattern as `_commerce_civicrm_get_membership_types()` and `_commerce_civicrm_get_financial_types()`
4. The three new service methods follow the same pattern as `getFinancialTypes()` in `ContactUpdater`
