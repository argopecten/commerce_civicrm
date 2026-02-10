# Developer Guide

> Internal reference for the `commerce_civicrm` Drupal module.
> Targets: Drupal 11+, Commerce 3+, PHP 8.3+, CiviCRM 6.x.

---

## Quick Overview

The module listens to Commerce order workflow transitions (place, validate, fulfill, cancel) via an event subscriber and delegates work to a chain of services that create or update CiviCRM records through API4.

```
OrderCompleteSubscriber
  └─> OrderCivicrmUpdater
        ├─> ContactUpdater      ─> CiviCRM Contact / Email / UFMatch
        ├─> ContributionUpdater ─> CiviCRM Contribution
        ├─> MembershipUpdater   ─> CiviCRM Membership
        └─> MailingUpdater      ─> CiviCRM GroupContact
```

All CiviCRM interaction is gated by `CivicrmHelper::isAvailable()` — if CiviCRM is not bootstrapped, no API calls are attempted.

## Documentation Index

| Document | Description |
|---|---|
| [architecture.md](architecture.md) | Module structure, service graph, and data flow |
| [services.md](services.md) | Detailed service reference with public API |
| [hooks-and-events.md](hooks-and-events.md) | Drupal hooks, event subscribers, and extension points |
| [product-field-schema.md](product-field-schema.md) | `field_civicrm` JSON schema and product configuration |
| [field-automation.md](field-automation.md) | Automatic `field_civicrm` provisioning to product types |
| [api-reference.md](api-reference.md) | CiviCRM API4 patterns used in the module |
| [testing.md](testing.md) | Testing strategy and manual test procedures |
| [todo.md](todo.md) | Open issues and development backlog |

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.3 |
| Drupal core | ^11 |
| drupal/commerce | ^3.0 |
| drupal/civicrm | ^6.1 |
| drupal/profile | ^1.2 |
| drupal/state_machine | ^1.5 |

## Extension Points

### 1. Service decoration

Override any service while keeping the original available:

```yaml
# my_module.services.yml
services:
  my_module.enhanced_contact_updater:
    class: Drupal\my_module\Service\EnhancedContactUpdater
    decorates: commerce_civicrm.contact_updater
    arguments:
      - '@my_module.enhanced_contact_updater.inner'
      - '@entity_type.manager'
      - '@logger.factory'
      - '@commerce_civicrm.civicrm_helper'
```

### 2. Additional event subscribers

Subscribe to the same Commerce workflow events at a different priority:

```php
public static function getSubscribedEvents() {
  // Run after commerce_civicrm (-50)
  return [
    'commerce_order.place.post_transition' => ['onOrderPlace', -100],
  ];
}
```

### 3. Altering the product form

Use a second `hook_form_commerce_product_form_alter` to add fields or modify the CiviCRM details group.

### 4. Pre/post-processing via hooks

React to CiviCRM-field changes on products or order-state changes on orders via `hook_entity_presave` / `hook_entity_update`.

## CiviCRM API4 Usage

All CiviCRM operations use API4 with the OOP calling convention:

| Convention | Used by |
|---|---|
| OOP (`\Civi\Api4\Entity::action(FALSE)`) | All services |

All calls pass `checkPermissions = FALSE`, running with full access regardless of the logged-in user's CiviCRM permissions.

### Bootstrap sequence

Before any API call, a service must:

```php
if (!$this->civicrmHelper->initialize()) {
  return NULL;
}
```

### Error handling pattern

Every API call is wrapped in try/catch:

```php
try {
  if (!$this->civicrmHelper->initialize()) {
    return NULL;
  }
  $result = \Civi\Api4\Entity::action(FALSE)
    ->...
    ->execute();
} catch (\CRM_Core_Exception $e) {
  $this->logger->error('Context message: @error', [
    '@error' => $e->getMessage(),
  ]);
  return NULL;
}
```

See [api-reference.md](api-reference.md) for complete entity-by-entity API usage patterns.

## Debugging

| What to check | How |
|---|---|
| Service definitions | `drush devel:services \| grep commerce_civicrm` |
| Event subscriber registration | `drush devel:event commerce_order.place.post_transition` |
| CiviCRM availability | `drush eval "\Drupal::service('commerce_civicrm.civicrm_helper')->isAvailable();"` |
| Product field JSON | `drush sqlq "SELECT entity_id, field_civicrm_value FROM commerce_product__field_civicrm"` |
| Recent log messages | `drush ws --count=50 --filter=commerce_civicrm` |
| All errors | `drush ws --severity=3 --filter=commerce_civicrm` |

## Logging

All services log to the `commerce_civicrm` logger channel:

| Level | Used for |
|---|---|
| `debug` | Per-item processing steps, raw settings dumps |
| `info` | Successful record creation, order processing start/end |
| `warning` | Missing data (no customer, no billing profile, no CiviCRM config) |
| `error` | CiviCRM unavailable, API exceptions, initialization failures |

View logs: `drush ws --tail --filter="commerce_civicrm"` or at `/admin/reports/dblog`.

## Contributing

1. **Follow Standards**: Drupal coding standards, PHP 8.3+ features
2. **Write Tests**: See [testing.md](testing.md) for the testing strategy
3. **Document Changes**: Update relevant docs in this folder
4. **Use Services**: Implement functionality as services with DI
5. **Check the Backlog**: See [todo.md](todo.md) for open issues

## Resources

- [CiviCRM API4 Documentation](https://docs.civicrm.org/dev/en/latest/api/v4/)
- [Commerce Order Events](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-events)
- [State Machine Workflows](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-workflows)
