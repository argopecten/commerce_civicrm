# Agent Prompt: Implement Todo #19 — Rename `hook_requirements` to `hook_runtime_requirements`

## Objective

Rename `commerce_civicrm_requirements()` to `commerce_civicrm_runtime_requirements()` and remove the now-unnecessary `$phase` parameter and check. In Drupal 11.1+, runtime requirements have their own dedicated hook. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`commerce_civicrm.install` uses the legacy `hook_requirements($phase)` pattern with a `$phase === 'runtime'` check:

```php
/**
 * Implements hook_requirements().
 */
function commerce_civicrm_requirements($phase) {
  $requirements = [];
  
  if ($phase === 'runtime') {
    // All requirement checks are inside this block...
  }
  
  return $requirements;
}
```

Since Drupal 11.1, `hook_runtime_requirements()` exists specifically for runtime checks. It takes no `$phase` parameter, so the `if ($phase === 'runtime')` wrapper is eliminated.

## What to implement

### 1. Rename the function

**Before:**
```php
/**
 * Implements hook_requirements().
 */
function commerce_civicrm_requirements($phase) {
```

**After:**
```php
/**
 * Implements hook_runtime_requirements().
 */
function commerce_civicrm_runtime_requirements() {
```

### 2. Remove the `$phase` check and un-indent

The entire function body is wrapped in `if ($phase === 'runtime') { ... }`. Remove the `if` wrapper and un-indent the code by one level.

**Before (abbreviated):**
```php
function commerce_civicrm_requirements($phase) {
  $requirements = [];
  
  if ($phase === 'runtime') {
    // Check if CiviCRM module is enabled
    if (!\Drupal::hasService('civicrm')) {
      $requirements['commerce_civicrm_civicrm'] = [
        // ...
      ];
    }
    
    // Check if CiviCRM is properly configured
    if (\Drupal::hasService('commerce_civicrm.civicrm_helper')) {
      // ...
    }
  }
  
  return $requirements;
}
```

**After (abbreviated):**
```php
function commerce_civicrm_runtime_requirements() {
  $requirements = [];
  
  // Check if CiviCRM module is enabled
  if (!\Drupal::hasService('civicrm')) {
    $requirements['commerce_civicrm_civicrm'] = [
      // ...
    ];
  }
  
  // Check if CiviCRM is properly configured
  if (\Drupal::hasService('commerce_civicrm.civicrm_helper')) {
    // ...
  }
  
  return $requirements;
}
```

### Important

- Do NOT change anything inside the requirement check logic — only the function name, PHPDoc, parameter, and the `if ($phase)` wrapper
- The individual `$requirements[...]` arrays should remain the same (except that prompt #18 may have changed `REQUIREMENT_*` constants — that's fine, keep whatever is there)

## Files to modify

1. **`commerce_civicrm.install`** — Rename function, remove `$phase` parameter and check

## Files NOT to modify

- All other files

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- This hook is available since Drupal 11.1.0 — the module's `composer.json` already requires `drupal/core: ^11.0`
- Do NOT create a `hook_requirements()` fallback — that's unnecessary since the module requires D11+
- The body content (requirement checks, severity values, messages) must remain unchanged

## Verification

After implementation, confirm:
1. Function is named `commerce_civicrm_runtime_requirements`
2. Function takes zero parameters
3. No `$phase` variable appears in the function
4. The PHPDoc says `Implements hook_runtime_requirements().`
5. No `if ($phase` block exists
6. Requirement check logic is preserved but un-indented one level
7. No syntax errors in the file
