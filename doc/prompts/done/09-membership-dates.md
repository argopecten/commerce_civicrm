# Agent Prompt: Implement Todo #9 — Membership Dates Bypass CiviCRM Native Logic

## Objective

Replace the manual date calculation in `MembershipUpdater::calculateMembershipDates()` with CiviCRM's native date-handling logic. The current implementation hardcodes December 31 for fixed-period memberships and ignores rolling periods, rollover days, and grace periods. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `src/Service/MembershipUpdater.php`, `calculateMembershipDates()` (L258–L296) manually computes membership dates:

```php
protected function calculateMembershipDates($membership_type) {
    $today = new \DateTime();
    $join_date = $today->format('Y-m-d');
    $start_date = $today->format('Y-m-d');
    
    $end_date = clone $today;
    $duration_unit = $membership_type['duration_unit'] ?? 'year';
    $duration_interval = $membership_type['duration_interval'] ?? 1;
    
    switch ($duration_unit) {
      case 'day':
        $end_date->add(new \DateInterval('P' . $duration_interval . 'D'));
        break;
      case 'month':
        $end_date->add(new \DateInterval('P' . $duration_interval . 'M'));
        break;
      case 'year':
      default:
        $end_date->add(new \DateInterval('P' . $duration_interval . 'Y'));
        break;
    }
    
    // Handle fixed period memberships (e.g., calendar year)
    if ($membership_type['period_type'] === 'fixed') {
      $end_date = new \DateTime($today->format('Y') . '-12-31');
    }
    
    return [
      'join_date' => $join_date,
      'start_date' => $start_date,
      'end_date' => $end_date->format('Y-m-d'),
    ];
}
```

Issues:
1. **Fixed period always uses December 31** — wrong for non-calendar fiscal years (e.g. July 1 – June 30, April 1 – March 31)
2. **No rollover day handling** — CiviCRM membership types have `fixed_period_rollover_day` and `fixed_period_start_day` that determine when a fixed membership starts/ends
3. **No grace period** — CiviCRM has built-in grace period handling for expired memberships
4. **Ignores `MembershipType.fixed_period_start_day`** — determines the actual start of a fixed period
5. **Rolling period is simplistic** — doesn't account for CiviCRM's configurable behavior

## What to implement

### Approach: Let CiviCRM calculate the dates

The simplest and most correct fix is to **not specify `end_date`** when creating a membership. CiviCRM's `Membership.create` API automatically calculates the correct `end_date` (and `start_date` for fixed periods) based on the membership type's configuration — including `period_type`, `duration_unit`, `duration_interval`, `fixed_period_start_day`, and `fixed_period_rollover_day`.

### 1. Simplify `calculateMembershipDates()`

Replace the entire method with a minimal version that only sets `join_date` and `start_date`:

```php
/**
 * Calculates membership dates based on membership type.
 *
 * Only sets join_date and start_date. The end_date is intentionally
 * omitted to let CiviCRM's native logic calculate it based on the
 * membership type's duration, period type, and rollover settings.
 *
 * @param array $membership_type
 *   The membership type data.
 *
 * @return array
 *   Array with join_date, start_date. No end_date — CiviCRM calculates it.
 */
protected function calculateMembershipDates(array $membership_type): array {
    $today = new \DateTimeImmutable();
    
    return [
        'join_date' => $today->format('Y-m-d'),
        'start_date' => $today->format('Y-m-d'),
    ];
}
```

### 2. Update callers to not pass `end_date`

The return value of `calculateMembershipDates()` is used in `createMembershipFromOrder()` (L76–L143) and `createPendingMembershipFromOrder()` (around L580–L660). Both build `$membership_data` with:

```php
$dates = $this->calculateMembershipDates($membership_type);
$membership_data = [
    'join_date' => $dates['join_date'],
    'start_date' => $dates['start_date'],
    'end_date' => $dates['end_date'],  // ← remove this line
    // ...
];
```

Remove the `'end_date' => $dates['end_date']` line from both callers. When `Membership::create()` receives no `end_date`, CiviCRM computes it automatically.

### 3. Update `renewMembership()` (L371–L434)

The `renewMembership()` method also manually extends the end date:

```php
// Current: manually extends end_date
$end_date->add(new \DateInterval('P' . $membership_type['duration_interval'] . ...));
```

Replace this with an approach that lets CiviCRM handle renewal dates. When renewing, pass only the fields that trigger CiviCRM's internal renewal logic (e.g., updating `status_id` back to `New` or `Current`, which triggers date recalculation), or omit `end_date` and let CiviCRM extend it.

Alternatively, use `\Civi\Api4\Membership::save()` without specifying `end_date`, and CiviCRM will extend the membership based on its type configuration.

### 4. Use `\DateTimeImmutable` instead of `\DateTime`

Replace `new \DateTime()` with `new \DateTimeImmutable()` throughout — this prevents accidental mutation of date objects (the current code clones `$today` to avoid this, but immutable objects eliminate the need).

## Files to modify

1. **`src/Service/MembershipUpdater.php`** — Simplify `calculateMembershipDates()`, remove `end_date` from callers, update `renewMembership()`, use `\DateTimeImmutable`

## Files NOT to modify

- All other files — this is a single-file fix
- `commerce_civicrm.services.yml` — no changes needed
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- The `join_date` and `start_date` should still be set (they indicate when the member joined and when the current period starts)
- Do not pass `end_date` to `Membership::create()` or `Membership::update()` unless there's a specific business reason to override CiviCRM's calculation
- Use `\DateTimeImmutable` instead of `\DateTime`
- The existing error handling (try/catch blocks) must remain unchanged
- Include updated PHPDoc explaining why `end_date` is omitted
- Do not change the method signature of `calculateMembershipDates()` (still takes `$membership_type` array, still returns an array)

## Verification

After implementation, confirm:
1. `calculateMembershipDates()` no longer computes `end_date`
2. No hardcoded `'-12-31'` exists in `MembershipUpdater.php`
3. `createMembershipFromOrder()` does not pass `end_date` to the API
4. `createPendingMembershipFromOrder()` does not pass `end_date` to the API
5. `renewMembership()` does not manually compute a new `end_date`
6. `\DateTime` is replaced with `\DateTimeImmutable` where applicable
7. No syntax errors in `MembershipUpdater.php`
