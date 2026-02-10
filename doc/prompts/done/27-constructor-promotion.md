# Agent Prompt: Implement Todo #27 — Use Constructor Property Promotion

## Objective

Refactor all `src/` constructors to use PHP 8.0+ constructor property promotion. This eliminates the manual `$this->property = $argument;` pattern and the separate property declarations. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

Every service class and the event subscriber manually declare properties, then assign them in the constructor:

```php
protected EntityTypeManagerInterface $entityTypeManager;
protected LoggerChannelInterface $logger;
protected CivicrmHelper $civicrmHelper;

public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    CivicrmHelper $civicrm_helper
) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmHelper = $civicrm_helper;
}
```

PHP 8.0+ constructor promotion allows declaring and assigning properties in the constructor signature directly. However, there is a complication: several constructors derive a value from the injected argument (e.g., `$logger_factory->get('commerce_civicrm')` → stored as `$this->logger`). These **cannot** use simple promotion because the stored type differs from the injected type.

## What to implement

### Strategy

For each constructor, apply promotion where the injected argument is stored directly (no transformation). For arguments that are transformed (like `LoggerChannelFactoryInterface` → `LoggerChannelInterface`), keep them as regular parameters with assignment in the body.

### Per-file instructions

#### 1. `src/Service/CivicrmHelper.php`

Current constructor (L45-53):
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler,
    ?object $civicrm = NULL
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->moduleHandler = $module_handler;
    $this->civicrm = $civicrm;
}
```

**After:**
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ?object $civicrm = NULL,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

- `$moduleHandler` and `$civicrm`: promoted (stored directly)
- `$logger`: NOT promoted (derived from factory)
- Keep the `protected LoggerChannelInterface $logger;` property declaration for `$logger` only
- Remove property declarations for `$moduleHandler` and `$civicrm`
- Remove `@var` PHPDoc blocks for promoted properties

#### 2. `src/Service/ContactUpdater.php`

Current constructor (L52-57):
```php
public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, CivicrmHelper $civicrm_helper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmHelper = $civicrm_helper;
}
```

**After:**
```php
public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

- Remove property declarations for `$entityTypeManager` and `$civicrmHelper`
- Keep `protected LoggerChannelInterface $logger;` property declaration
- Note: promoted parameter names must match the desired property names (`$entityTypeManager`, not `$entity_type_manager`)

#### 3. `src/Service/ContributionUpdater.php`

Same pattern as ContactUpdater — identical signature.

**After:**
```php
public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

#### 4. `src/Service/MembershipUpdater.php`

Same pattern as ContactUpdater — identical signature.

**After:**
```php
public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

#### 5. `src/Service/MailingUpdater.php`

Same pattern as ContactUpdater — identical signature.

**After:**
```php
public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

#### 6. `src/Service/OrderCivicrmUpdater.php`

Current constructor (L85-101):
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    CivicrmHelper $civicrm_helper,
    ContactUpdater $contact_updater,
    ContributionUpdater $contribution_updater,
    MembershipUpdater $membership_updater,
    MailingUpdater $mailing_updater
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmHelper = $civicrm_helper;
    $this->contactUpdater = $contact_updater;
    $this->contributionUpdater = $contribution_updater;
    $this->membershipUpdater = $membership_updater;
    $this->mailingUpdater = $mailing_updater;
}
```

**After:**
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly ContactUpdater $contactUpdater,
    protected readonly ContributionUpdater $contributionUpdater,
    protected readonly MembershipUpdater $membershipUpdater,
    protected readonly MailingUpdater $mailingUpdater,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

- 5 of 6 parameters promoted; only `$logger` remains manually assigned

#### 7. `src/EventSubscriber/OrderCompleteSubscriber.php`

Current constructor (L45-52):
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    OrderCivicrmUpdater $order_civicrm_updater
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->orderCivicrmUpdater = $order_civicrm_updater;
}
```

**After:**
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly OrderCivicrmUpdater $orderCivicrmUpdater,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

#### 8. `src/Service/ProductFormHelper.php`

Current constructor (L58-68):
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    CivicrmHelper $civicrm_helper,
    MembershipUpdater $membership_updater,
    ContactUpdater $contact_updater
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmHelper = $civicrm_helper;
    $this->membershipUpdater = $membership_updater;
    $this->contactUpdater = $contact_updater;
}
```

**After:**
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly MembershipUpdater $membershipUpdater,
    protected readonly ContactUpdater $contactUpdater,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

### General rules

For each file:
1. **Remove** the separate `protected` property declarations (and their `@var` PHPDoc blocks) for promoted properties
2. **Keep** the `protected LoggerChannelInterface $logger;` property declaration and its `@var` PHPDoc (it's derived, not promoted)
3. **Add `readonly`** to all promoted properties — none of these services reassign their dependencies after construction
4. **Update PHPDoc**: Remove `@param` entries that become self-documenting via promotion, OR keep them for IDE support — either approach is acceptable. The important thing is that `@param` for `$logger_factory` remains since it's a non-promoted parameter.
5. **Trailing comma** after the last parameter in multi-line constructors
6. **Naming**: Promoted parameter names must use camelCase to match the existing property names (e.g., `$civicrmHelper` not `$civicrm_helper`)

## Files to modify

1. `src/Service/CivicrmHelper.php`
2. `src/Service/ContactUpdater.php`
3. `src/Service/ContributionUpdater.php`
4. `src/Service/MembershipUpdater.php`
5. `src/Service/MailingUpdater.php`
6. `src/Service/OrderCivicrmUpdater.php`
7. `src/EventSubscriber/OrderCompleteSubscriber.php`
8. `src/Service/ProductFormHelper.php`

## Files NOT to modify

- `commerce_civicrm.services.yml` — service definitions are unaffected by promotion
- `commerce_civicrm.install` — procedural, no constructors
- `commerce_civicrm.module` — procedural, no constructors

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `protected readonly` (not just `protected`) for all promoted properties — dependencies should be immutable
- The `$logger` property CANNOT be promoted because it's derived from `$logger_factory->get('commerce_civicrm')` — the injected type (`LoggerChannelFactoryInterface`) differs from the stored type (`LoggerChannelInterface`)
- Promoted parameter names must be camelCase to match the property names used throughout the class
- Do NOT change any method bodies — only constructors, property declarations, and their PHPDoc

## Verification

After implementation, confirm:
1. No `$this->property = $argument;` assignments remain for directly-stored dependencies (only `$this->logger` assignment remains in each constructor)
2. All promoted properties have `protected readonly` visibility
3. Separate property declarations are removed for promoted properties
4. The `$logger` property is still declared separately as `protected LoggerChannelInterface $logger;`
5. No syntax errors in any `src/` file
6. Run: `grep -rn '$this->.*= \$' src/ | grep -v 'logger'` — should return zero results from constructors (may return results from other methods)
