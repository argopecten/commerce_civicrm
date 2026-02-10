# Agent Prompt: Implement Todo #5 — MembershipUpdater Procedural API4 → OOP

## Objective

Migrate all 13 procedural `civicrm_api4()` calls in `MembershipUpdater.php` to OOP `\Civi\Api4\Entity::action(FALSE)` style, matching the convention already used by `CivicrmHelper`, `ContactUpdater`, and `ContributionUpdater`. This is a purely mechanical calling-convention refactor with no functional change. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`src/Service/MembershipUpdater.php` is the **only** service in the module that still uses the procedural `civicrm_api4()` wrapper. Every other service uses OOP API4:

| Service | API Style |
|---------|-----------|
| `CivicrmHelper.php` | OOP `\Civi\Api4\*` |
| `ContactUpdater.php` | OOP `\Civi\Api4\*` |
| `ContributionUpdater.php` | OOP `\Civi\Api4\*` |
| **`MembershipUpdater.php`** | **Procedural `civicrm_api4()`** |

OOP style provides IDE autocompletion, static analysis support, and makes the `checkPermissions: FALSE` intent explicit at construction.

## What to implement

### Migration table

Convert all 13 calls using this mapping:

| # | Line | Current (procedural) | Target (OOP) |
|---|------|---------------------|--------------|
| 1 | ~L115 | `civicrm_api4('Membership', 'create', ['values' => $membership_data, 'checkPermissions' => FALSE])` | `\Civi\Api4\Membership::create(FALSE)->setValues($membership_data)->execute()` |
| 2 | ~L157 | `civicrm_api4('Membership', 'get', ['select' => [...], 'where' => [...], 'limit' => 1, 'checkPermissions' => FALSE])` | `\Civi\Api4\Membership::get(FALSE)->addSelect(...)->addWhere(...)->setLimit(1)->execute()` |
| 3 | ~L199 | `civicrm_api4('Membership', 'update', ['where' => [...], 'values' => $update_data, 'checkPermissions' => FALSE])` | `\Civi\Api4\Membership::update(FALSE)->addWhere('id', '=', $membership_id)->setValues($update_data)->execute()` |
| 4 | ~L232 | `civicrm_api4('MembershipType', 'get', ['select' => [...], 'where' => [...], 'limit' => 1, 'checkPermissions' => FALSE])` | `\Civi\Api4\MembershipType::get(FALSE)->addSelect(...)->addWhere(...)->setLimit(1)->execute()` |
| 5 | ~L342 | `civicrm_api4('Membership', 'update', ...)` in `updateMembershipStatus()` | `\Civi\Api4\Membership::update(FALSE)->...->execute()` |
| 6 | ~L412 | `civicrm_api4('Membership', 'update', ...)` in `renewMembership()` | `\Civi\Api4\Membership::update(FALSE)->...->execute()` |
| 7 | ~L450 | `civicrm_api4('MembershipType', 'get', ...)` in `getMembershipTypes()` | `\Civi\Api4\MembershipType::get(FALSE)->...->execute()` |
| 8 | ~L497 | `civicrm_api4('Membership', 'update', ...)` in `cancelMembership()` | `\Civi\Api4\Membership::update(FALSE)->...->execute()` |
| 9 | ~L535 | `civicrm_api4('MembershipStatus', 'get', ...)` in `getMembershipStatuses()` | `\Civi\Api4\MembershipStatus::get(FALSE)->...->execute()` |
| 10 | ~L633 | `civicrm_api4('Membership', 'create', ...)` in `createPendingMembershipFromOrder()` | `\Civi\Api4\Membership::create(FALSE)->...->execute()` |
| 11 | ~L693 | `civicrm_api4('Membership', 'update', ...)` in `updateMembershipToPending()` | `\Civi\Api4\Membership::update(FALSE)->...->execute()` |
| 12 | ~L751 | `civicrm_api4('Membership', 'update', ...)` in `cancelMembershipFromOrder()` | `\Civi\Api4\Membership::update(FALSE)->...->execute()` |
| 13 | ~L788 | `civicrm_api4('MembershipStatus', 'get', ...)` in `getMembershipStatusId()` | `\Civi\Api4\MembershipStatus::get(FALSE)->...->execute()` |

### Conversion pattern

**Before (procedural):**
```php
$result = civicrm_api4('Membership', 'get', [
    'select' => ['id', 'status_id', 'end_date'],
    'where' => [
        ['contact_id', '=', $contact_id],
        ['membership_type_id', '=', $membership_type_id],
        ['status_id:name', 'IN', ['New', 'Current', 'Grace']],
    ],
    'limit' => 1,
    'checkPermissions' => FALSE,
]);
```

**After (OOP):**
```php
$result = \Civi\Api4\Membership::get(FALSE)
    ->addSelect('id', 'status_id', 'end_date')
    ->addWhere('contact_id', '=', $contact_id)
    ->addWhere('membership_type_id', '=', $membership_type_id)
    ->addWhere('status_id:name', 'IN', ['New', 'Current', 'Grace'])
    ->setLimit(1)
    ->execute();
```

**Before (procedural create):**
```php
$result = civicrm_api4('Membership', 'create', [
    'values' => $membership_data,
    'checkPermissions' => FALSE,
]);
```

**After (OOP create):**
```php
$result = \Civi\Api4\Membership::create(FALSE)
    ->setValues($membership_data)
    ->execute();
```

**Before (procedural update):**
```php
$result = civicrm_api4('Membership', 'update', [
    'where' => [['id', '=', $membership_id]],
    'values' => ['status_id' => $status_id, 'source' => $source],
    'checkPermissions' => FALSE,
]);
```

**After (OOP update):**
```php
$result = \Civi\Api4\Membership::update(FALSE)
    ->addWhere('id', '=', $membership_id)
    ->addValue('status_id', $status_id)
    ->addValue('source', $source)
    ->execute();
```

### Result handling

The `$result` object returned by OOP `->execute()` behaves identically to what `civicrm_api4()` returns — both are `\Civi\Api4\Generic\Result` objects. Existing checks like `!empty($result[0]['id'])` and `$result->count()` work unchanged. **No result-handling code needs to change.**

### Use statements to add

Add these `use` statements at the top of the file (after the existing ones):

```php
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\MembershipStatus;
```

Then use the short class names in the code (e.g. `Membership::get(FALSE)` instead of `\Civi\Api4\Membership::get(FALSE)`).

## Files to modify

1. **`src/Service/MembershipUpdater.php`** — Convert all 13 `civicrm_api4()` calls to OOP style. Add `use` statements.

## Files NOT to modify

- All other files — this is a single-file refactor
- `commerce_civicrm.services.yml` — no dependency changes
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- **No functional change** — this is purely a calling-convention migration
- Pass `FALSE` to the static factory method (not in an array) — e.g. `Membership::get(FALSE)` not `Membership::get(['checkPermissions' => FALSE])`
- Error handling (try/catch blocks) must remain unchanged
- All result-handling `$result[0]['id']`, `$result->count()`, etc. must stay as-is
- Use `addSelect()` with multiple arguments (not an array) — e.g. `->addSelect('id', 'name', 'description')`
- Use `addWhere()` per clause (not `setWhere()` with an array of arrays)
- For `create` and `update` with a `$data` array, use `->setValues($data)` instead of individual `->addValue()` calls (keeps the code concise)
- Add `use` imports for `Civi\Api4\Membership`, `Civi\Api4\MembershipType`, `Civi\Api4\MembershipStatus`

## Verification

After implementation, confirm:
1. Zero occurrences of `civicrm_api4(` remain in `MembershipUpdater.php`
2. All 13 calls now use OOP style (`Membership::`, `MembershipType::`, `MembershipStatus::`)
3. `use Civi\Api4\Membership;`, `use Civi\Api4\MembershipType;`, `use Civi\Api4\MembershipStatus;` are present
4. No syntax errors in `MembershipUpdater.php`
5. Result handling (`$result[0]`, `$result->count()`, etc.) is unchanged
6. All try/catch blocks are preserved
