# Agent Prompt: Implement Todo #18 — Replace Deprecated `REQUIREMENT_*` Constants

## Objective

Replace the deprecated `REQUIREMENT_ERROR`, `REQUIREMENT_OK`, and `REQUIREMENT_WARNING` constants with the `Drupal\Core\Extension\Requirement\RequirementSeverity` enum in `commerce_civicrm.install`. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`commerce_civicrm.install` uses legacy `REQUIREMENT_*` constants that are deprecated in Drupal 11.1 and removed in Drupal 12:

```php
// L22 (approximate):
$requirements['civicrm_module']['severity'] = REQUIREMENT_ERROR;

// L28 (approximate):
$requirements['civicrm_module']['severity'] = REQUIREMENT_ERROR;

// L36 (approximate):
$requirements['civicrm_initialization']['severity'] = REQUIREMENT_OK;

// L39 (approximate):
$requirements['civicrm_initialization']['severity'] = REQUIREMENT_WARNING;

// L47 (approximate):
$requirements['civicrm_initialization']['severity'] = REQUIREMENT_ERROR;
```

These are inside the `commerce_civicrm_requirements()` function (Drupal's `hook_requirements`).

## What to implement

### 1. Add the `use` statement at the top of the file

Add after the opening `<?php` and any existing `use` statements:

```php
use Drupal\Core\Extension\Requirement\RequirementSeverity;
```

### 2. Replace all 5 constant references

| Before | After |
|--------|-------|
| `REQUIREMENT_ERROR` | `RequirementSeverity::Error` |
| `REQUIREMENT_OK` | `RequirementSeverity::OK` |
| `REQUIREMENT_WARNING` | `RequirementSeverity::Warning` |

Apply these replacements:

**All `REQUIREMENT_ERROR` occurrences (3 total):**
```php
// Before:
$requirements['...']['severity'] = REQUIREMENT_ERROR;
// After:
$requirements['...']['severity'] = RequirementSeverity::Error;
```

**The `REQUIREMENT_OK` occurrence (1 total):**
```php
// Before:
$requirements['...']['severity'] = REQUIREMENT_OK;
// After:
$requirements['...']['severity'] = RequirementSeverity::OK;
```

**The `REQUIREMENT_WARNING` occurrence (1 total):**
```php
// Before:
$requirements['...']['severity'] = REQUIREMENT_WARNING;
// After:
$requirements['...']['severity'] = RequirementSeverity::Warning;
```

## Files to modify

1. **`commerce_civicrm.install`** — Replace 5 constant references, add `use` statement

## Files NOT to modify

- All other files

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use the `RequirementSeverity` enum (available since Drupal 11.1)
- The exact casing matters: `RequirementSeverity::Error`, `RequirementSeverity::OK`, `RequirementSeverity::Warning`
- Note `OK` is all-caps in the enum
- Do NOT modify any other part of the `hook_requirements` function — that's handled by prompt #19 separately

## Verification

After implementation, confirm:
1. Zero occurrences of `REQUIREMENT_ERROR`, `REQUIREMENT_OK`, or `REQUIREMENT_WARNING` in the file
2. Exactly 5 occurrences of `RequirementSeverity::` in the file
3. The `use Drupal\Core\Extension\Requirement\RequirementSeverity;` statement is present
4. No syntax errors in the file
5. Run: `grep -c 'REQUIREMENT_' commerce_civicrm.install` — should return 0
