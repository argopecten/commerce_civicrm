# Agent Prompt: Implement Todo #16 — Add Typed Properties

## Objective

Add PHP type declarations to all class properties across all `src/` classes. The codebase currently uses untyped `protected $property;` style. PHP 8.3+ and Drupal 11 standards require typed properties. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

Every service class and the event subscriber declares properties without types:

```php
protected $entityTypeManager;
protected $logger;
protected $civicrmHelper;
```

Should be:

```php
protected EntityTypeManagerInterface $entityTypeManager;
protected LoggerChannelInterface $logger;
protected CivicrmHelper $civicrmHelper;
```

The PHPDoc `@var` annotations already document the correct types — the types just need to be added to the declarations.

## Files to modify

All files in `src/`:

### 1. `src/Service/CivicrmHelper.php`
```php
// Before:
protected $logger;
// After:
protected \Drupal\Core\Logger\LoggerChannelInterface $logger;
```

### 2. `src/Service/ContactUpdater.php`
```php
// Before:
protected $entityTypeManager;
protected $logger;
protected $civicrmHelper;
// After:
protected EntityTypeManagerInterface $entityTypeManager;
protected LoggerChannelInterface $logger;
protected CivicrmHelper $civicrmHelper;
```

### 3. `src/Service/ContributionUpdater.php`
```php
// Before:
protected $entityTypeManager;
protected $logger;
protected $civicrmHelper;
// After:
protected EntityTypeManagerInterface $entityTypeManager;
protected LoggerChannelInterface $logger;
protected CivicrmHelper $civicrmHelper;
```

### 4. `src/Service/MembershipUpdater.php`
```php
// Before:
protected $entityTypeManager;
protected $logger;
protected $civicrmHelper;
// After:
protected EntityTypeManagerInterface $entityTypeManager;
protected LoggerChannelInterface $logger;
protected CivicrmHelper $civicrmHelper;
```

### 5. `src/Service/OrderCivicrmUpdater.php`
```php
// Before:
protected $logger;
protected $civicrmHelper;
protected $contactUpdater;
protected $contributionUpdater;
protected $membershipUpdater;
// After:
protected \Psr\Log\LoggerInterface $logger;
protected CivicrmHelper $civicrmHelper;
protected ContactUpdater $contactUpdater;
protected ContributionUpdater $contributionUpdater;
protected MembershipUpdater $membershipUpdater;
```

### 6. `src/EventSubscriber/OrderCompleteSubscriber.php`
```php
// Before:
protected $logger;
protected $orderCivicrmUpdater;
// After:
protected \Drupal\Core\Logger\LoggerChannelInterface $logger;
protected OrderCivicrmUpdater $orderCivicrmUpdater;
```

## Guidelines

- Use the type from the `@var` PHPDoc annotation — that's the authoritative type
- Use the imported short name if a `use` statement already exists; otherwise import the class or use the FQCN
- For `$logger`, note that some classes type it as `LoggerChannelInterface` and others as `LoggerInterface` — use whichever type is in the existing `@var` annotation. Ensure the corresponding `use` statement exists.
- Keep the `@var` PHPDoc but it becomes optional once the type is declared — you may keep it for IDE discoverability
- Also check for any properties without `@var` annotations and determine the type from the constructor assignment

## Files NOT to modify

- `commerce_civicrm.module` — procedural
- `commerce_civicrm.install` — no update hooks
- `commerce_civicrm.services.yml` — no changes

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Only add types to existing property declarations — do not change visibility or names
- Ensure `use` statements are present for all types referenced in property declarations
- Remove unused `use` statements if found (e.g., `ProductInterface` in ContactUpdater, `PaymentInterface` in ContributionUpdater — these are unused imports)

## Verification

After implementation, confirm:
1. Every property in every `src/` class has a PHP type declaration
2. No `protected $foo;` or `public $foo;` without types remain in `src/`
3. All referenced types have corresponding `use` statements
4. No syntax errors in any `src/` file
5. Run: `grep -rn 'protected \$\|public \$\|private \$' src/ | grep -v ': '` — should return zero results (temporary files or false positives excepted)
