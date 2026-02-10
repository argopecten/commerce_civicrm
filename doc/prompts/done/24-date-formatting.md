# Agent Prompt: Implement Todo #24 — Replace Bare `date()` with `DrupalDateTime`

## Objective

Replace all three bare PHP `date()` calls with `DrupalDateTime` for proper timezone handling and Drupal integration. Two calls are in `ContributionUpdater.php` (L115, L474) and one is in `OrderCivicrmUpdater.php` (L374, added by prompt #6). Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

Two files use bare `date()` calls to format dates for CiviCRM:

**Location 1 — `ContributionUpdater::extractContributionData()` (L115):**
```php
$contribution_data['receive_date'] = date('Y-m-d H:i:s', $order->getCompletedTime() ?: time());
```

**Location 2 — `ContributionUpdater::createContributionFromOrderWithFinancialType()` (L474):**
```php
'receive_date' => date('Y-m-d H:i:s'),
```

**Location 3 — `OrderCivicrmUpdater::processLinkedMembershipContribution()` (L374):**
```php
'receive_date' => date('Y-m-d H:i:s', $order->getCompletedTime() ?: time()),
```

This third call site was introduced by prompt #6 (Contribution↔Membership linking) and uses the same bare `date()` pattern.

Issues:
- `date()` uses the server's default timezone, which may differ from the site's configured timezone
- `DrupalDateTime` is the standard Drupal way to handle dates, respecting site timezone settings
- CiviCRM expects dates in `Y-m-d H:i:s` format, which `DrupalDateTime` can provide

## What to implement

### 1. Add the `use` statement

At the top of `ContributionUpdater.php`, add:
```php
use Drupal\Core\Datetime\DrupalDateTime;
```

### 2. Replace Location 1 (~L125)

**Before:**
```php
$contribution_data['receive_date'] = date('Y-m-d H:i:s', $order->getCompletedTime() ?: time());
```

**After:**
```php
$timestamp = $order->getCompletedTime() ?: \Drupal::time()->getRequestTime();
$contribution_data['receive_date'] = DrupalDateTime::createFromTimestamp($timestamp)
    ->format('Y-m-d H:i:s');
```

Key changes:
- `time()` → `\Drupal::time()->getRequestTime()` (Drupal's time service, consistent within a request)
- `date()` → `DrupalDateTime::createFromTimestamp()->format()` (timezone-aware)

### 3. Replace Location 2 (~L484)

**Before:**
```php
'receive_date' => date('Y-m-d H:i:s'),
```

**After:**
```php
'receive_date' => (new DrupalDateTime())->format('Y-m-d H:i:s'),
```

### 4. Replace Location 3 — `OrderCivicrmUpdater.php` (L374)

**Before:**
```php
'receive_date' => date('Y-m-d H:i:s', $order->getCompletedTime() ?: time()),
```

**After:**
```php
'receive_date' => DrupalDateTime::createFromTimestamp(
    $order->getCompletedTime() ?: \Drupal::time()->getRequestTime()
)->format('Y-m-d H:i:s'),
```

Also add the `use` statement at the top of `OrderCivicrmUpdater.php`:
```php
use Drupal\Core\Datetime\DrupalDateTime;
```

### 5. Search for other `date()` calls

Grep the entire `src/` directory for any other bare `date(` calls. If found, apply the same `DrupalDateTime` replacement pattern.

Check for `strtotime()` calls too — those should use `DrupalDateTime::createFromFormat()` or the constructor instead.

## Files to modify

1. **`src/Service/ContributionUpdater.php`** — Replace 2 `date()` calls, add `use` statement
2. **`src/Service/OrderCivicrmUpdater.php`** — Replace 1 `date()` call (L374), add `use` statement

## Files to also check (grep for `date(` and `strtotime(`)

3. **`src/Service/MembershipUpdater.php`** — May have date formatting
4. **`src/Service/ContactUpdater.php`** — May have date formatting

## Files NOT to modify

- `commerce_civicrm.install` — procedural, and not date-related
- `commerce_civicrm.module` — procedural

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `DrupalDateTime` (not `\DateTimeImmutable` or `\DateTime`)
- CiviCRM expects dates in `Y-m-d H:i:s` format — ensure the format string is preserved
- Replace `time()` with `\Drupal::time()->getRequestTime()` where applicable
- If `DrupalDateTime` is already used elsewhere in the file, just reuse the import

## Verification

After implementation, confirm:
1. Zero occurrences of bare `date(` function calls in `src/` (grep for `[^>]date(` to exclude method calls like `$obj->date(`)
2. `DrupalDateTime` import is present in both `ContributionUpdater.php` and `OrderCivicrmUpdater.php`
3. All three `receive_date` values use `DrupalDateTime`
4. Format strings are still `Y-m-d H:i:s`
5. No syntax errors in any modified file
6. Run: `grep -rn '\bdate(' src/` — should return zero results
