# Agent Prompt: Implement Todo #23 — Replace `t()` with `$this->t()` in Service Classes

## Objective

Replace procedural `t()` function calls with `$this->t()` via `StringTranslationTrait` in all service classes that use `t()`. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

Service classes in `src/Service/` call the global `t()` function directly, which is a procedural pattern from D7. In Drupal 11 OOP code, the proper pattern is:

1. `use Drupal\Core\StringTranslation\StringTranslationTrait;`
2. Add `use StringTranslationTrait;` inside the class body
3. Call `$this->t()` instead of `t()`

The global `t()` still works but is deprecated for use in OOP service code because:
- It creates a hidden dependency on the string translation service
- It cannot be overridden or mocked in tests
- It violates dependency injection principles

## Affected files and locations

### `src/Service/ContactUpdater.php`

Multiple `t()` calls in these methods:

**`getFinancialTypes()` (~L520-560):**
```php
t('CiviCRM not available')
t('No active financial types found')
t('Error loading financial types')
```

**`getEvents()` (~L570-610):**
```php
t('- Select an event -')
t('CiviCRM not available')
t('Error loading events')
```

**`getParticipantRoles()` (~L615-645):**
```php
t('- Select a participant role -')
t('CiviCRM not available')
t('Error loading participant roles')
```

**`getMailingGroups()` (near end of file):**
```php
t('- Select a mailing group -')    // or similar
t('CiviCRM not available')
t('Error loading mailing groups')
```

### `src/Service/MembershipUpdater.php`

**`getMembershipTypes()` (~L450-470):**
```php
t('- Select a membership type -')
t('Error loading membership types')
```

## What to implement

### For each affected file:

#### 1. Add the `use` statement for the trait

At the top of the file, add:
```php
use Drupal\Core\StringTranslation\StringTranslationTrait;
```

#### 2. Add the trait to the class body

Inside the class, before the first property declaration:
```php
class ContactUpdater {

  use StringTranslationTrait;

  // ... existing properties
```

#### 3. Replace all `t()` calls with `$this->t()`

Search and replace every `t(` call (making sure to match only the function call, not partial words like `return` or `count`):

**Before:**
```php
return ['_none' => t('- Select an event -')];
```

**After:**
```php
return ['_none' => $this->t('- Select an event -')];
```

### Also scan for `t()` usage in other `src/` files

Check `ContributionUpdater.php`, `OrderCivicrmUpdater.php`, `CivicrmHelper.php`, and `OrderCompleteSubscriber.php` for any `t()` calls. If found, apply the same treatment.

**Do NOT modify:**
- `commerce_civicrm.install` — procedural code, `t()` is correct there
- `commerce_civicrm.module` — procedural code, `t()` is correct there

## Files to modify

1. **`src/Service/ContactUpdater.php`** — Add trait, replace ~12 `t()` calls
2. **`src/Service/MembershipUpdater.php`** — Add trait, replace ~2 `t()` calls
3. **Any other `src/` file** that uses `t()` — apply the same pattern

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `StringTranslationTrait`, NOT manual constructor injection of `TranslationInterface`
- The trait provides `$this->t()` and `$this->formatPlural()` automatically
- Do NOT add `string_translation` to the service definitions in `services.yml` — the trait uses `\Drupal::translation()` as a fallback when no setter injection is configured, which is fine for this module
- Only replace `t()` calls in `src/` classes, NOT in `.module` or `.install` files
- Be careful with regex: `t(` can match inside other words — match `\bt(` or look for standalone function calls

## Verification

After implementation, confirm:
1. Run `grep -rn '[^>]t(' src/ | grep -v '$this->t(' | grep -v '// ' | grep -v 'count(' | grep -v 'list(' | grep -v 'print(' | grep -v 'sort('` — should return zero results (no bare `t()` calls)
2. Each affected class has `use StringTranslationTrait;` inside the class body
3. Each affected file has `use Drupal\Core\StringTranslation\StringTranslationTrait;` at the top
4. All former `t()` calls now use `$this->t()`
5. No syntax errors in any modified file
