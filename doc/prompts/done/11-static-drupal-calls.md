# Agent Prompt: Implement Todo #11 — Static `\Drupal::` Calls in CivicrmHelper

## Objective

Replace the three static `\Drupal::` calls in `CivicrmHelper` with proper dependency injection via the constructor and `commerce_civicrm.services.yml`. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

`src/Service/CivicrmHelper.php` is registered as a DI service but internally uses three static `\Drupal::` calls — a D7-era bridge pattern:

```php
// L38:
if (!\Drupal::moduleHandler()->moduleExists('civicrm')) {

// L44:
if (!\Drupal::hasService('civicrm')) {

// L50:
\Drupal::service('civicrm')->initialize();
```

Services should receive all dependencies via constructor injection, not by reaching into the global container.

### Current constructor and services.yml

**`CivicrmHelper.php` constructor (L28):**
```php
public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('commerce_civicrm');
}
```

**`commerce_civicrm.services.yml`:**
```yaml
commerce_civicrm.civicrm_helper:
    class: Drupal\commerce_civicrm\Service\CivicrmHelper
    arguments:
        - '@logger.factory'
```

## What to implement

### 1. Inject `ModuleHandlerInterface`

Replace `\Drupal::moduleHandler()` with an injected `ModuleHandlerInterface`:

```php
use Drupal\Core\Extension\ModuleHandlerInterface;

// In constructor:
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->moduleHandler = $module_handler;
}

// In initialize():
if (!$this->moduleHandler->moduleExists('civicrm')) {
```

### 2. Inject the CiviCRM service (nullable)

The `civicrm` service only exists when the CiviCRM module is enabled. It cannot be a hard dependency — if CiviCRM is not installed, the container won't have the service and the module won't install.

Use `ContainerInterface` to do a conditional injection, or use the `@?` nullable reference in `services.yml` (Drupal 10.3+ / Symfony 6.4+):

**Option A — Nullable service reference (recommended for D11):**
```yaml
commerce_civicrm.civicrm_helper:
    class: Drupal\commerce_civicrm\Service\CivicrmHelper
    arguments:
        - '@logger.factory'
        - '@module_handler'
        - '@?civicrm'
```

In the constructor, accept a nullable parameter:
```php
public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler,
    ?object $civicrm = NULL,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->moduleHandler = $module_handler;
    $this->civicrm = $civicrm;
}
```

Then in `initialize()`:
```php
public function initialize() {
    try {
        if (!$this->moduleHandler->moduleExists('civicrm')) {
            $this->logger->error('CiviCRM module not enabled');
            return FALSE;
        }

        if ($this->civicrm === NULL) {
            $this->logger->error('CiviCRM service not available');
            return FALSE;
        }

        $this->civicrm->initialize();
        return TRUE;
    } catch (\Exception $e) {
        $this->logger->error('Exception while initializing CiviCRM: @message', [
            '@message' => $e->getMessage(),
        ]);
        return FALSE;
    }
}
```

This eliminates all three static calls. The `\Drupal::hasService('civicrm')` check becomes a simple null check on the injected property.

**Option B — Use `ContainerAwareInterface` / service locator (fallback if `@?` is not supported):**

If the `@?` syntax is not available, inject `ContainerInterface` and resolve the service dynamically:

```yaml
commerce_civicrm.civicrm_helper:
    class: Drupal\commerce_civicrm\Service\CivicrmHelper
    arguments:
        - '@logger.factory'
        - '@module_handler'
        - '@service_container'
```

```php
use Symfony\Component\DependencyInjection\ContainerInterface;

public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler,
    ContainerInterface $container,
) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->moduleHandler = $module_handler;
    $this->civicrm = $container->has('civicrm') ? $container->get('civicrm') : NULL;
}
```

Option A is preferred since the module targets Drupal 11+ which fully supports nullable service references.

### 3. Update `isAvailable()` and `getSystemInfo()`

These methods don't use static calls directly (they call `$this->initialize()` or the OOP API4 classes), so they need no changes. Just verify they still work with the refactored `initialize()`.

### 4. Add property declarations

Add typed property declarations for the new dependencies:

```php
protected ModuleHandlerInterface $moduleHandler;
protected ?object $civicrm;
```

Or if typing the CiviCRM service specifically:
```php
protected ?\Drupal\civicrm\Civicrm $civicrm;
```

Using `?object` is safer since it doesn't create a hard class dependency on the `civicrm` module.

## Files to modify

1. **`src/Service/CivicrmHelper.php`** — Inject dependencies, remove static calls, add properties
2. **`commerce_civicrm.services.yml`** — Add `@module_handler` and `@?civicrm` arguments to `civicrm_helper`

## Files NOT to modify

- All other service classes — they use `CivicrmHelper` via injection and are unaffected
- `commerce_civicrm.module` — the `_commerce_civicrm_is_available()` function calls the service, not the other way around
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Zero static `\Drupal::` calls should remain in `CivicrmHelper.php`
- The `civicrm` service must be nullable — the module must not crash if CiviCRM is not installed
- Use `@?civicrm` syntax in `services.yml` (Drupal 11+ / Symfony 6.4+ nullable reference)
- Keep the public API of `CivicrmHelper` unchanged — `initialize()`, `isAvailable()`, `getSystemInfo()` must have the same signatures and behavior
- Include proper PHPDoc on new properties and updated constructor parameters
- Use `?object` for the CiviCRM service type to avoid a hard dependency on `\Drupal\civicrm\Civicrm`

## Verification

After implementation, confirm:
1. Zero occurrences of `\Drupal::` in `CivicrmHelper.php`
2. `services.yml` has `@module_handler` and `@?civicrm` in `civicrm_helper` arguments
3. `initialize()` uses `$this->moduleHandler` and `$this->civicrm`
4. The `civicrm` property is nullable (`?object` or similar)
5. `isAvailable()` and `getSystemInfo()` still work (they call `$this->initialize()`)
6. No syntax errors in either modified file
