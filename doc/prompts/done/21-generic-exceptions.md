# Agent Prompt: Implement Todo #21 — Replace Generic `\Exception` Catches with Specific Types

## Objective

Replace bare `catch (\Exception $e)` blocks across all service classes with specific CiviCRM exception types. This improves error handling by distinguishing between CiviCRM API errors, database errors, and unexpected failures. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

All service classes use generic `catch (\Exception $e)` which catches everything — including programming errors (TypeError, ValueError), out-of-memory, and other conditions that should NOT be silently logged and swallowed. This masks bugs and makes debugging difficult.

## What to implement

### 1. Identify all `catch (\Exception $e)` blocks

Search all files in `src/` for `catch (\Exception`. Each catch block should be evaluated:

**Primary CiviCRM exceptions to catch:**
- `\CRM_Core_Exception` — The main CiviCRM exception for API errors (thrown by CiviCRM API4 on failures)
- `\Civi\API\Exception\UnauthorizedException` — CiviCRM permission errors
- `\CRM_Extension_Exception` — Extension-level errors

In practice, CiviCRM API4 calls primarily throw `\CRM_Core_Exception`. For maximum compatibility, use this as the specific catch type.

### 2. Replacement pattern

For each try/catch block in the service classes, apply this pattern:

**Before:**
```php
try {
    // CiviCRM API call...
} catch (\Exception $e) {
    $this->logger->error('Error doing X: @error', ['@error' => $e->getMessage()]);
    return NULL; // or FALSE or []
}
```

**After:**
```php
try {
    // CiviCRM API call...
} catch (\CRM_Core_Exception $e) {
    $this->logger->error('CiviCRM API error doing X: @error', ['@error' => $e->getMessage()]);
    return NULL;
}
```

### 3. Special cases

Some try/catch blocks may also fail due to Drupal entity loading or other non-CiviCRM reasons. For those, use a two-catch pattern:

```php
try {
    // Mixed Drupal + CiviCRM code
} catch (\CRM_Core_Exception $e) {
    $this->logger->error('CiviCRM error: @error', ['@error' => $e->getMessage()]);
    return NULL;
} catch (\Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
    $this->logger->error('Entity type error: @error', ['@error' => $e->getMessage()]);
    return NULL;
}
```

However, if a try/catch block **only** contains CiviCRM API calls (which is the majority), just catch `\CRM_Core_Exception`.

### 4. Special handling for `commerce_civicrm.install`

The `commerce_civicrm_runtime_requirements()` function (or `commerce_civicrm_requirements()` if #19 hasn't been applied yet) has a catch block too. That block catches errors from `$helper->isAvailable()` which calls CiviCRM internally. Replace with `\CRM_Core_Exception` there as well.

### 5. Files to process

Grep all `src/` files and `commerce_civicrm.install` for `catch (\Exception`:

1. **`src/Service/CivicrmHelper.php`** — `initialize()` method
2. **`src/Service/ContactUpdater.php`** — Multiple methods (`findExistingContact`, `updateExistingContact`, `createNewContact`, `getFinancialTypes`, `getEvents`, `getParticipantRoles`, `getMailingGroups`, etc.)
3. **`src/Service/ContributionUpdater.php`** — Multiple methods (`createContribution`, `findExistingContribution`, `getPaymentInfo`, etc.)
4. **`src/Service/MembershipUpdater.php`** — Multiple methods (`createMembershipFromOrder`, `findExistingMembership`, `updateMembership`, etc.)
5. **`src/Service/OrderCivicrmUpdater.php`** — `processOrder()`, `processOrderItem()`, `getCivicrmProductSettings()`
6. **`commerce_civicrm.install`** — `hook_requirements()` / `hook_runtime_requirements()`

### 6. Edge case: `CivicrmHelper::initialize()`

This method catches `\Exception` because `\Drupal::service('civicrm')->initialize()` can throw various exceptions depending on CiviCRM's state. For this specific method, catching `\Exception` may be appropriate since the goal is to determine whether CiviCRM is available at all. If you decide to keep it as `\Exception` here, add a comment explaining why:

```php
// Catching \Exception broadly because CiviCRM initialization can fail
// with various exception types depending on the installation state.
```

## Files to modify

1. **`src/Service/CivicrmHelper.php`**
2. **`src/Service/ContactUpdater.php`**
3. **`src/Service/ContributionUpdater.php`**
4. **`src/Service/MembershipUpdater.php`**
5. **`src/Service/OrderCivicrmUpdater.php`**
6. **`commerce_civicrm.install`**

## Files NOT to modify

- `src/EventSubscriber/OrderCompleteSubscriber.php` — Check if it has any catch blocks; if not, skip
- `commerce_civicrm.module` — procedural, skip
- `commerce_civicrm.services.yml` — no changes

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Primary catch type: `\CRM_Core_Exception`
- Do NOT add `use` statements for `\CRM_Core_Exception` — it's a global class, always referenced with leading backslash
- Keep the existing log messages (log level, message text) — only change the exception type caught
- If a catch block re-throws or does something more complex than logging, preserve that behavior
- If unsure whether a block can throw non-CiviCRM exceptions, use the two-catch pattern to preserve safety

## Verification

After implementation, confirm:
1. Run `grep -rn 'catch (\\Exception' src/` — should return 0 or very few results (only justified cases like `CivicrmHelper::initialize()`)
2. Run `grep -rn 'CRM_Core_Exception' src/` — should show multiple results
3. Each CiviCRM API try/catch now catches `\CRM_Core_Exception`
4. No syntax errors in any modified file
5. Log messages and return values are unchanged
