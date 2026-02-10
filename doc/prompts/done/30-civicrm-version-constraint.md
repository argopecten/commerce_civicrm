# Agent Prompt: Implement Todo #30 — Tighten `composer.json` CiviCRM Version Constraint

## Objective

Tighten the `drupal/civicrm` version constraint in `composer.json` and document the tested version range. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`composer.json` currently declares:

```json
"drupal/civicrm": "^6.0"
```

This allows any 6.x version, but:
- CiviCRM has schema and API changes in minor releases
- The module uses API4 features (like `\Civi\Api4\Order::create()` and `Contact::getDuplicates()`) that require specific minimum versions
- The maintenance mode check (todo #29) requires CiviCRM 6.1+ for the `environment` setting
- Without a documented tested range, users don't know which versions are safe

## What to implement

### 1. Tighten the version constraint

**Before:**
```json
"drupal/civicrm": "^6.0"
```

**After:**
```json
"drupal/civicrm": "^6.1"
```

Rationale for `^6.1`:
- CiviCRM 6.0 introduced API4 `Order::create()` for contribution-membership linking
- CiviCRM 6.1 added maintenance mode support (`environment` setting) used by todo #29
- `^6.1` allows 6.1.0+, 6.2.x, 6.3.x, etc. but not 7.0
- This is the minimum that supports all features the module now uses

### 2. Add a version comment in `extra.drupal`

Add a `tested-versions` entry to document what's been verified:

**Before:**
```json
"extra": {
    "drupal": {
        "version": "2.0.0-dev",
        "datestamp": "1755292800",
        "security-coverage": {
            "status": "not-covered",
            "message": "Dev releases are not covered by Drupal security advisories."
        }
    },
```

**After:**
```json
"extra": {
    "drupal": {
        "version": "2.0.0-dev",
        "datestamp": "1755292800",
        "security-coverage": {
            "status": "not-covered",
            "message": "Dev releases are not covered by Drupal security advisories."
        },
        "tested-civicrm-versions": "6.1.0 — 6.2.0"
    },
```

### 3. No other changes

Do NOT modify any other fields in `composer.json`.

## Files to modify

1. **`composer.json`** — Update `require.drupal/civicrm` constraint and add tested-versions note

## Files NOT to modify

- All other files

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `^6.1` (not `>=6.1 <7.0` or other verbose forms — `^` is preferred Composer convention)
- The tested versions note is informational only — it doesn't affect Composer resolution
- Preserve JSON formatting (4-space indentation)
- Ensure valid JSON after editing

## Verification

After implementation, confirm:
1. `require.drupal/civicrm` is `^6.1`
2. `extra.drupal.tested-civicrm-versions` field exists
3. No other fields were modified
4. JSON is valid: `python3 -c "import json; json.load(open('composer.json'))"`
