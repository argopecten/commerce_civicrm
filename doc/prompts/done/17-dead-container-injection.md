# Agent Prompt: Implement Todo #17 — Remove Dead `ContainerInjectionInterface`

## Objective

Remove the dead `ContainerInjectionInterface` implementation, the dead `create()` factory method, and the two unused `use` statements from `OrderCompleteSubscriber.php`. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`src/EventSubscriber/OrderCompleteSubscriber.php` implements `ContainerInjectionInterface` and has a `create()` factory method, but neither is used. The subscriber is instantiated via Symfony's service container (defined in `commerce_civicrm.services.yml`), not via `ContainerInjectionInterface::create()`. This is dead code.

**Dead imports (L10-11):**
```php
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
```

**Dead interface (L22):**
```php
class OrderCompleteSubscriber implements EventSubscriberInterface, ContainerInjectionInterface {
```

**Dead create() method (L58-63):**
```php
public static function create(ContainerInterface $container) {
    return new static(
        $container->get('logger.factory'),
        $container->get('commerce_civicrm.order_civicrm_updater')
    );
}
```

## What to implement

### 1. Remove the two `use` statements

Delete these lines:
```php
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
```

### 2. Remove `ContainerInjectionInterface` from the class declaration

**Before:**
```php
class OrderCompleteSubscriber implements EventSubscriberInterface, ContainerInjectionInterface {
```

**After:**
```php
class OrderCompleteSubscriber implements EventSubscriberInterface {
```

### 3. Remove the entire `create()` method

Delete this block (including its PHPDoc if present):
```php
/**
 * {@inheritdoc}
 */
public static function create(ContainerInterface $container) {
    return new static(
        $container->get('logger.factory'),
        $container->get('commerce_civicrm.order_civicrm_updater')
    );
}
```

## Files to modify

1. **`src/EventSubscriber/OrderCompleteSubscriber.php`** — Remove dead interface, method, and imports

## Files NOT to modify

- `commerce_civicrm.services.yml` — The subscriber is already configured via DI, no changes needed
- All other files

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Do NOT remove `EventSubscriberInterface` — that is actively used
- Do NOT remove the constructor — that is actively used for dependency injection
- Only remove the three items listed above (two `use` statements, interface from `implements`, and `create()` method)

## Verification

After implementation, confirm:
1. `ContainerInjectionInterface` does not appear anywhere in the file
2. `ContainerInterface` does not appear anywhere in the file
3. No `create()` method exists in the class
4. `EventSubscriberInterface` is still in the `implements` clause
5. Constructor still exists and accepts `LoggerChannelFactoryInterface` and `OrderCivicrmUpdater`
6. No syntax errors in the file
7. The class still has these methods: `__construct`, `getSubscribedEvents`, `onOrderPlace`, `onOrderValidate`, `onOrderFulfill`, `onOrderCancel`
