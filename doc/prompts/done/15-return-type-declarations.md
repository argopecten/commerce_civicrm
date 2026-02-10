# Agent Prompt: Implement Todo #15 — Add PHP Return-Type Declarations

## Objective

Add return-type declarations to all public and protected methods across all `src/` classes. The codebase currently has zero return types. The module targets PHP 8.3+ where return types are standard. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

All service classes and the event subscriber in `src/` have no return-type declarations on any method. This is a D7/early-D8 pattern. PHP 8.3+ and Drupal 11 coding standards expect return types on all methods.

## Files to modify

All files in `src/`:

1. **`src/Service/CivicrmHelper.php`** (~113 lines, 3 public methods)
2. **`src/Service/ContactUpdater.php`** (~651 lines, ~15 methods)
3. **`src/Service/ContributionUpdater.php`** (~658 lines, ~13 methods)
4. **`src/Service/MembershipUpdater.php`** (~800 lines, ~15 methods)
5. **`src/Service/OrderCivicrmUpdater.php`** (~420 lines, ~4 methods)
6. **`src/EventSubscriber/OrderCompleteSubscriber.php`** (~231 lines, ~7 methods)

## What to implement

For every public and protected method, add the appropriate return type based on what the method actually returns. Common mappings:

| Return pattern | Type |
|---------------|------|
| Returns `TRUE`/`FALSE` | `: bool` |
| Returns an integer or `NULL` | `: ?int` |
| Returns an array | `: array` |
| Returns nothing | `: void` |
| Returns an integer | `: int` |
| Returns a string | `: string` |
| Returns a string or `NULL` | `: ?string` |
| Returns an array or `NULL` | `: ?array` |

### Specific method signatures to update

#### `CivicrmHelper.php`
- `initialize()` → `: bool`
- `isAvailable()` → `: bool`
- `getSystemInfo()` → `: array`

#### `ContactUpdater.php`
- `updateOrCreateContact(...)` → `: ?int`
- `extractContactData(...)` → `: array`
- `findExistingContact(...)` → `: ?int`
- `updateExistingContact(...)` → `: bool`
- `createNewContact(...)` → `: ?int`
- `updateContactEmail(...)` → `: void`
- `getContactIdByUser(...)` → `: ?int`
- `getFinancialTypes()` → `: array`
- `getEvents()` → `: array`
- `getParticipantRoles()` → `: array`
- `getMailingGroups()` → `: array`
- `initializeCivicrm()` → `: bool` (if still present — may be removed by #12)

#### `ContributionUpdater.php`
- `createContributionFromOrder(...)` → `: ?int`
- `extractContributionData(...)` → `: array`
- `findExistingContribution(...)` → `: ?int`
- `createContribution(...)` → `: ?int`
- `getFinancialTypeId()` → `: ?int`
- `getContributionStatusId(...)` → `: ?int`
- `getPaymentInfo(...)` → `: ?array`
- `getPaymentInstrumentId(...)` → `: ?int`
- `createContributionFromOrderWithFinancialType(...)` → `: ?int`
- `cancelContributionFromOrder(...)` → `: ?int`
- `getContributionStatusIdByName(...)` → `: ?int`
- `ensureCustomFieldExists()` → `: bool`
- `initializeCivicrm()` → `: bool` (if still present)

#### `MembershipUpdater.php`
- `createMembershipFromOrder(...)` → `: ?int`
- `findExistingMembership(...)` → `: ?array`
- `updateMembership(...)` → `: ?int`
- `getMembershipTypeDetails(...)` → `: ?array`
- `calculateMembershipDates(...)` → `: array`
- `addCustomFieldsToMembership(...)` → `: array`
- `updateMembershipStatus(...)` → `: void` or `: bool`
- `renewMembership(...)` → `: ?int`
- `getMembershipTypes()` → `: array`
- `cancelMembership(...)` → `: bool`
- `getMembershipStatuses()` → `: array`
- `createPendingMembershipFromOrder(...)` → `: ?int`
- `updateMembershipToPending(...)` → `: ?int`
- `cancelMembershipFromOrder(...)` → `: ?int`
- `getMembershipStatusId(...)` → `: ?int`

#### `OrderCivicrmUpdater.php`
- `processOrder(...)` → `: array`
- `processOrderItem(...)` → `: array`
- `getCivicrmProductSettings(...)` → `: array`
- `processCancellation(...)` → `: void` (or `: array` if changed by #4)

#### `OrderCompleteSubscriber.php`
- `create(...)` → `: static` (if still present — may be removed by #17)
- `getSubscribedEvents()` → `: array`
- `onOrderPlace(...)` → `: void`
- `onOrderValidate(...)` → `: void`
- `onOrderFulfill(...)` → `: void`
- `onOrderCancel(...)` → `: void`

### Guidelines

- **Read each method** to determine the actual return type — don't guess
- For methods that return `$result` or `NULL`, use nullable types (e.g., `: ?int`)
- For methods that sometimes return early with `NULL` and otherwise return an `int`, use `: ?int`
- Constructors do NOT get return types
- Static factory methods like `create()` return `: static`
- `getSubscribedEvents()` returns `: array`
- Update PHPDoc `@return` tags to match the new return types

## Files NOT to modify

- `commerce_civicrm.module` — procedural functions, not subject to this change
- `commerce_civicrm.install` — procedural, no update hooks
- `commerce_civicrm.services.yml` — no changes

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Add return types to **all** public and protected methods (and private methods too)
- Constructors do NOT get return types
- Use union types only when truly necessary (PHP 8.0+ supports `int|null` but `?int` is preferred for single-nullable)
- Update `@return` PHPDoc annotations to match the declared types
- If a method's PHPDoc says it returns something but the code can also return `NULL` (via early returns or catch blocks), use the nullable type

## Verification

After implementation, confirm:
1. Every method in `src/` (except constructors) has a return type declaration
2. PHPDoc `@return` annotations are consistent with declared return types
3. No syntax errors in any `src/` file
4. Run `grep -rn 'function ' src/ | grep -v '__construct' | grep -v ': '` — should return zero results (all functions have return types)
