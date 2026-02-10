# Agent Prompt: Implement Todo #29 — CiviCRM Maintenance Mode Awareness

## Objective

Add maintenance mode detection to `CivicrmHelper` so the module defers CiviCRM API operations when CiviCRM is in maintenance mode (e.g., during upgrades). Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`src/Service/CivicrmHelper.php` has `initialize()` and `isAvailable()` methods but neither checks whether CiviCRM is in maintenance mode. CiviCRM 6.1 added maintenance mode support ([civicrm-core#31893](https://github.com/civicrm/civicrm-core/pull/31893)). During upgrades or maintenance, API calls could fail or produce inconsistent results.

Currently, `initialize()` does:
1. Check if CiviCRM module is enabled
2. Check if CiviCRM service is available
3. Bootstrap CiviCRM
4. Return TRUE

It should additionally check if CiviCRM is in maintenance/upgrade mode and return FALSE with a warning log if so.

## What to implement

### 1. Add a maintenance mode check in `initialize()`

After the successful `$this->civicrm->initialize()` call (around L82), add a maintenance mode check before returning TRUE:

```php
// Initialize CiviCRM bootstrap
$this->civicrm->initialize();

// Check if CiviCRM is in maintenance mode (upgrades, etc.)
if ($this->isInMaintenanceMode()) {
    $this->logger->warning('CiviCRM is in maintenance mode — deferring API operations');
    return FALSE;
}

return TRUE;
```

### 2. Add the `isInMaintenanceMode()` method

Add a new protected method to `CivicrmHelper`:

```php
/**
 * Checks if CiviCRM is currently in maintenance mode.
 *
 * CiviCRM enters maintenance mode during database upgrades and
 * when the administrator explicitly enables it. During maintenance mode,
 * API calls may fail or produce inconsistent results.
 *
 * @return bool
 *   TRUE if CiviCRM is in maintenance mode, FALSE otherwise.
 */
protected function isInMaintenanceMode(): bool {
    try {
        // Check the CiviCRM upgrade status.
        // CRM_Utils_System::isCiviUpgradeActive() returns TRUE during upgrades.
        if (defined('CIVICRM_UPGRADE_ACTIVE') && CIVICRM_UPGRADE_ACTIVE) {
            return TRUE;
        }

        // Check CiviCRM's environment setting.
        // In CiviCRM 6.1+, the 'environment' setting can be 'Maintenance'.
        $environment = \Civi::settings()->get('environment');
        if ($environment === 'Maintenance') {
            return TRUE;
        }

        return FALSE;
    } catch (\Exception $e) {
        // If we can't determine maintenance mode, assume it's not active.
        // This handles cases where CiviCRM is partially initialized.
        $this->logger->debug('Unable to check CiviCRM maintenance mode: @error', [
            '@error' => $e->getMessage(),
        ]);
        return FALSE;
    }
}
```

### 3. Add a public method for external checks

Add a convenience method that other services can use to check maintenance mode without calling `initialize()`:

```php
/**
 * Checks if CiviCRM is available and NOT in maintenance mode.
 *
 * This is a stronger check than isAvailable() — it also rejects
 * maintenance mode. Use this before performing write operations.
 *
 * @return bool
 *   TRUE if CiviCRM is available for write operations, FALSE otherwise.
 */
public function isReadyForOperations(): bool {
    if (!$this->initialize()) {
        return FALSE;
    }
    return !$this->isInMaintenanceMode();
}
```

### 4. Important: Do NOT change the `isAvailable()` method

The existing `isAvailable()` method should remain as-is for backward compatibility. The new `isReadyForOperations()` method adds the stricter check. The `initialize()` method itself now also checks maintenance mode, so any code path going through `initialize()` is automatically protected.

## Files to modify

1. **`src/Service/CivicrmHelper.php`** — Add maintenance mode check to `initialize()`, add `isInMaintenanceMode()` method, add `isReadyForOperations()` method

## Files NOT to modify

- All other files — they all call `$this->civicrmHelper->initialize()` which now automatically checks maintenance mode
- `commerce_civicrm.services.yml` — no new dependencies needed
- `commerce_civicrm.install` — the `hook_runtime_requirements()` may still use `isAvailable()` which is fine

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- The maintenance mode check must be inside a try/catch because `\Civi::settings()` may not be available if CiviCRM is only partially bootstrapped
- Use `CIVICRM_UPGRADE_ACTIVE` constant check (available since CiviCRM 4.x) as a first-line check
- Use `\Civi::settings()->get('environment')` for the CiviCRM 6.1+ environment check
- The `'Maintenance'` value is case-sensitive — use exactly `'Maintenance'`
- Do NOT throw exceptions from `isInMaintenanceMode()` — always return a bool
- The `isInMaintenanceMode()` method should be `protected` (internal to the helper)
- The `isReadyForOperations()` method should be `public` (available to other services)

## Verification

After implementation, confirm:
1. `initialize()` returns FALSE when CiviCRM is in maintenance mode
2. `isInMaintenanceMode()` exists as a protected method
3. `isReadyForOperations()` exists as a public method
4. A warning log is emitted when maintenance mode is detected
5. The `isAvailable()` method is unchanged
6. No syntax errors in `CivicrmHelper.php`
7. The try/catch in `isInMaintenanceMode()` prevents exceptions from propagating
