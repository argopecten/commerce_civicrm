# Agent Prompt: Implement Todo #14 — Replace `print_r()` in Production Logging

## Objective

Replace the remaining `print_r($contact_data, TRUE)` calls in `ContactUpdater::findExistingContact()` with `json_encode()`. A third call site was already fixed by prompt #2. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `src/Service/ContactUpdater.php`, `findExistingContact()` has two remaining calls using `print_r()` for debug logging:

**L193:**
```php
$this->logger->debug('Searching for existing CiviCRM contact with data: @contact_data', [
    '@contact_data' => print_r($contact_data, TRUE),
]);
```

**L301** (in the fallback path after dedupe search, near the end of `findExistingContact()`):
```php
$this->logger->info('No existing CiviCRM contact found for provided data: @contact_data', [
    '@contact_data' => print_r($contact_data, TRUE),
]);
```

Issues:
- `print_r()` output is not machine-parseable (can't be processed by log aggregators)
- Can leak sensitive PII (email, name, address) in production logs in a verbose, hard-to-redact format
- Inconsistent with the rest of the codebase which now uses `json_encode()` (prompt #2 fixed `extractContactData()`)

## What to implement

Replace both `print_r($contact_data, TRUE)` calls with `json_encode($contact_data)`:

**Before:**
```php
'@contact_data' => print_r($contact_data, TRUE),
```

**After:**
```php
'@contact_data' => json_encode($contact_data),
```

Do this for both occurrences in `findExistingContact()`.

Additionally, search the entire `src/` directory for any other `print_r()` calls and replace those too.

## Files to modify

1. **`src/Service/ContactUpdater.php`** — Replace 2 `print_r()` calls in `findExistingContact()`

## Files NOT to modify

- All other files (unless `print_r()` is found elsewhere in `src/`)
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `json_encode()` (not `var_export()` or other alternatives)
- Keep the log level (`debug` / `info`) unchanged
- Keep the log message text unchanged — only change the placeholder value

## Verification

After implementation, confirm:
1. Zero occurrences of `print_r(` in `src/Service/ContactUpdater.php`
2. Zero occurrences of `print_r(` anywhere in `src/`
3. Both call sites now use `json_encode($contact_data)`
4. No syntax errors in `ContactUpdater.php`
