# Agent Prompt: Implement Todo #28 — FinancialType `name` vs `label` Display

## Objective

Change `getFinancialTypes()` to display the translatable `label` field instead of the machine `name` field for financial type options. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `src/Service/ContactUpdater.php`, the `getFinancialTypes()` method (L506) returns `$type['name']` as the display text for each financial type option:

```php
$result = \Civi\Api4\FinancialType::get(FALSE)
    ->addWhere('is_active', '=', TRUE)
    ->addOrderBy('name', 'ASC')
    ->setLimit(0)
    ->execute();

foreach ($result as $type) {
    $options[$type['id']] = $type['name'];
}
```

CiviCRM 6.0 explicitly distinguishes `name` (machine identifier, non-translatable) from `label` (human-readable, translatable) — see [civicrm-core#32189](https://github.com/civicrm/civicrm-core/pull/32189). For user-facing form selects, `label` should be used.

Examples:
- `name`: `"Donation"` / `label`: `"Donation"` (often identical for English)
- `name`: `"Member Dues"` / `label`: `"Member Dues"` (identical in default installs)
- But in multi-language CiviCRM installations, `label` is translated while `name` never is

## What to implement

### 1. Add `addSelect()` to explicitly request needed fields

**Before:**
```php
$result = \Civi\Api4\FinancialType::get(FALSE)
    ->addWhere('is_active', '=', TRUE)
    ->addOrderBy('name', 'ASC')
    ->setLimit(0)
    ->execute();
```

**After:**
```php
$result = \Civi\Api4\FinancialType::get(FALSE)
    ->addSelect('id', 'name', 'label')
    ->addWhere('is_active', '=', TRUE)
    ->addOrderBy('label', 'ASC')
    ->setLimit(0)
    ->execute();
```

Note: also change the `addOrderBy` from `'name'` to `'label'` so options are sorted by the displayed text.

### 2. Use `label` for display

**Before:**
```php
foreach ($result as $type) {
    $options[$type['id']] = $type['name'];
}
```

**After:**
```php
foreach ($result as $type) {
    $options[$type['id']] = $type['label'];
}
```

### 3. Check for similar patterns in other methods

Scan `ContactUpdater.php` and other service files for similar issues where CiviCRM entity `name` is used for display instead of `label`:

- `getEvents()` — uses `$event['title']` — this is correct (events use `title`, not `name`/`label`)
- `getParticipantRoles()` — uses `$role['label']` — this is correct
- `getMailingGroups()` — uses `$group['title']` — this is correct
- `getMembershipTypes()` in `MembershipUpdater.php` — uses `$membership_type['name']` — this **should also be checked**

If `MembershipUpdater::getMembershipTypes()` uses `name` for display, apply the same fix there.

## Files to modify

1. **`src/Service/ContactUpdater.php`** — Change `getFinancialTypes()` to use `label`

## Files to check and possibly modify

2. **`src/Service/MembershipUpdater.php`** — Check `getMembershipTypes()` for the same pattern

## Files NOT to modify

- All other files

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `label` for display text, keep `name` in the `addSelect()` for debugging/logging if needed
- Sort by `label` instead of `name`
- The `id` field is used as the options key — that must NOT change

## Verification

After implementation, confirm:
1. `getFinancialTypes()` uses `$type['label']` not `$type['name']` as the display value
2. The API query includes `->addSelect('id', 'name', 'label')`
3. `addOrderBy` sorts by `'label'`
4. If `getMembershipTypes()` had the same issue, it's also fixed
5. No syntax errors in any modified file
