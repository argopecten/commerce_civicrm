# Agent Prompt: Implement Todo #12 — Remove Redundant `initializeCivicrm()` Wrappers

## Objective

Remove the private `initializeCivicrm()` wrapper methods in `ContactUpdater` and `ContributionUpdater` that do nothing but call `$this->civicrmHelper->initialize()`. Replace all call sites with direct calls to the helper. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

Two services define private `initializeCivicrm()` methods that are trivial wrappers:

**`src/Service/ContributionUpdater.php` L61–L63:**
```php
private function initializeCivicrm() {
    return $this->civicrmHelper->initialize();
}
```

**`src/Service/ContactUpdater.php` L478 (similar):**
```php
private function initializeCivicrm() {
    return $this->civicrmHelper->initialize();
}
```

Meanwhile, `MembershipUpdater.php` calls `$this->civicrmHelper->initialize()` directly — which is the correct, consistent pattern. The wrappers are a D7 leftover that added an unnecessary indirection layer.

Additionally, both wrappers are called at the **top of every method** that touches CiviCRM, even methods that are only called by other methods that already initialized. This is a D7 pattern where bootstrap state was uncertain. In D11 with the service container, CiviCRM only needs to be initialized once per request.

## What to implement

### 1. Remove `initializeCivicrm()` from `ContributionUpdater.php`

Delete the entire private method:

```php
// DELETE this method (around L61-L63):
private function initializeCivicrm() {
    return $this->civicrmHelper->initialize();
}
```

### 2. Replace all `$this->initializeCivicrm()` calls with `$this->civicrmHelper->initialize()` in `ContributionUpdater.php`

Search for every occurrence of `$this->initializeCivicrm()` and replace with `$this->civicrmHelper->initialize()`.

Expected occurrences in `ContributionUpdater.php` (check the file for exact locations):
- `findExistingContribution()`
- `createContribution()`
- `getFinancialTypeId()`
- `getContributionStatusId()`
- `getPaymentInstrumentId()`
- `createContributionFromOrderWithFinancialType()`
- `cancelContributionFromOrder()`
- `getContributionStatusIdByName()`

### 3. Remove `initializeCivicrm()` from `ContactUpdater.php`

Delete the entire private method:

```php
// DELETE this method (around L478):
private function initializeCivicrm() {
    return $this->civicrmHelper->initialize();
}
```

### 4. Replace all `$this->initializeCivicrm()` calls with `$this->civicrmHelper->initialize()` in `ContactUpdater.php`

Search for every occurrence and replace. Expected occurrences:
- `updateOrCreateContact()`
- `findExistingContact()`
- `createNewContact()`
- `updateExistingContact()`
- `updateContactEmail()`
- `getContactIdByUser()`
- `getFinancialTypes()`

### 5. (Optional) Reduce redundant initialization calls

After the direct replacement, consider whether every method truly needs its own `initialize()` guard. For example, `createNewContact()` is only called from `updateOrCreateContact()`, which already initializes. In such cases, the inner `initialize()` is redundant.

However, since `initialize()` in `CivicrmHelper` is a lightweight no-op after the first call (it just calls the CiviCRM bootstrap which is already loaded), the redundant calls are harmless. Removing them would be a further optimization but is **optional** for this task.

If you do reduce initialization calls, keep them in all **public** methods (they're the entry points) and remove them from **private/protected** methods that are only called by public methods that already initialize.

## Files to modify

1. **`src/Service/ContributionUpdater.php`** — Delete `initializeCivicrm()` method, replace all call sites
2. **`src/Service/ContactUpdater.php`** — Delete `initializeCivicrm()` method, replace all call sites

## Files NOT to modify

- `src/Service/MembershipUpdater.php` — already uses the correct pattern
- `src/Service/CivicrmHelper.php` — no changes
- `commerce_civicrm.services.yml` — no changes
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Every `$this->initializeCivicrm()` must become `$this->civicrmHelper->initialize()`
- Zero `initializeCivicrm` methods or calls should remain in either file after the change
- Error handling and return values must remain identical — `if (!$this->civicrmHelper->initialize()) { return ...; }`
- This is a purely mechanical find-and-replace refactor — no functional changes

## Verification

After implementation, confirm:
1. Zero occurrences of `initializeCivicrm` in `ContributionUpdater.php` (neither method nor calls)
2. Zero occurrences of `initializeCivicrm` in `ContactUpdater.php` (neither method nor calls)
3. All former call sites now use `$this->civicrmHelper->initialize()`
4. `MembershipUpdater.php` is unchanged (already correct)
5. No syntax errors in either modified file
6. Grep the entire `src/` directory: zero results for `initializeCivicrm`
