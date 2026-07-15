# Developer Guide

> Internal reference for the `commerce_civicrm` Drupal module.
> Targets: Drupal 11+, Commerce 3+, PHP 8.3+, CiviCRM ≥ 6.16.

---

## Quick Overview

The module listens to Commerce order workflow transitions via a config-routed
event subscriber and converts each order into **one CiviCRM contribution**
created through the CiviCRM Order API, with one line item per CiviCRM-enabled
order item. Support services handle contact resolution, lookups and
cancellation; a renewal processor records recurring charges.

```
commerce_order.post_transition (create/cancel transitions from config)
  └─> OrderCompleteSubscriber
        └─> OrderCivicrmUpdater
              ├─ directives per order item   (OrderItemDirectivesEvent)
              ├─ Order::create + Payment     (ContributionParamsEvent)
              │    ├─ membership line items  ─> Membership create/renew
              │    ├─ participant line items ─> Participant
              │    └─ contribution lines
              ├─> MailingUpdater             ─> GroupContact
              └─ OrderProcessedEvent / ORDER_CANCELLED

site payment handling ──> RenewalProcessor ──> renewal contribution
                                               (RenewalRecordedEvent)
```

All CiviCRM interaction is gated by `CivicrmHelper` — write pipelines use
`isReadyForOperations()` (bootstrap + not in maintenance mode); if CiviCRM is
not available, no API calls are attempted and checkout is never disturbed.

## Documentation Index

| Document | Description |
|---|---|
| [architecture.md](architecture.md) | Module structure, service graph, and data flow |
| [services.md](services.md) | Detailed service reference with public API |
| [hooks-and-events.md](hooks-and-events.md) | Drupal hooks, dispatched events, and extension points |
| [product-field-schema.md](product-field-schema.md) | `field_civicrm` JSON schema and product configuration |
| [field-automation.md](field-automation.md) | Automatic `field_civicrm` provisioning to product types |
| [api-reference.md](api-reference.md) | CiviCRM API4 patterns used in the module |
| [testing.md](testing.md) | Unit tests and manual test procedures |
| [todo.md](todo.md) | Open issues and development backlog |
| [../user-guide/configuration.md](../user-guide/configuration.md) | `commerce_civicrm.settings` reference |

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.3 |
| Drupal core | ^11 |
| drupal/commerce | ^3.0 |
| civicrm/civicrm-core + civicrm/civicrm-drupal-8 | ^6.16 |
| drupal/profile | ^1.2 |
| drupal/state_machine | ^1.5 |

The CiviCRM floor is functional, not cosmetic: the module relies on the
current API4 `Order`/`Payment` contract (flat line items with
`entity_id.FIELD` keys, Pending-then-Payment completion).

## Extension Points

In order of preference:

### 1. Module events

Six dispatched events cover the whole pipeline — altering directives,
membership dates and API parameters, and reacting to processed/cancelled
orders and renewals. See [hooks-and-events.md](hooks-and-events.md). This is
the intended way to implement site-specific behaviour such as bundle
splitting or site-authoritative membership expiry.

### 2. Renewal API

`commerce_civicrm.renewal_processor::recordRenewalPayment($order, $payment)`
— call from site code (e.g. a `PaymentEvents` subscriber) to record recurring
charges as renewal contributions.

### 3. Service decoration

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

### 4. Additional event subscribers

Subscribe to `commerce_order.post_transition` at a priority after `-50` to
run after the module's processing.

### 5. Altering the product form

Use a second `hook_form_commerce_product_form_alter` to add fields or modify
the CiviCRM details group.

## CiviCRM API4 Usage

All CiviCRM operations use API4 with the OOP calling convention
(`\Civi\Api4\Entity::action(FALSE)`); every call passes
`checkPermissions = FALSE`, running with full access regardless of the
logged-in user's CiviCRM permissions.

Before any API call, a service gates on the helper:

```php
if (!$this->civicrmHelper->initialize()) {
  return NULL;
}
```

and wraps the call in try/catch (`\CRM_Core_Exception`), logging with context
and returning a neutral value — CiviCRM failures never bubble into checkout.

See [api-reference.md](api-reference.md) for the entity-by-entity patterns,
including the Order/Payment flow and line item shapes.

## Debugging

| What to check | How |
|---|---|
| Service definitions | `drush devel:services \| grep commerce_civicrm` |
| Event subscriber registration | `drush devel:event commerce_order.post_transition` |
| Effective settings | `drush config:get commerce_civicrm.settings` |
| CiviCRM availability | `drush eval "var_dump(\Drupal::service('commerce_civicrm.civicrm_helper')->isAvailable());"` |
| Product field JSON | `drush sqlq "SELECT entity_id, field_civicrm_value FROM commerce_product__field_civicrm"` |
| Replay an order | `drush commerce-civicrm:process-order <id>` (idempotent) |
| Recent log messages | `drush ws --count=50 --filter=commerce_civicrm` |

## Logging

All services log to the `commerce_civicrm` logger channel:

| Level | Used for |
|---|---|
| `debug` | Per-item processing steps, raw settings dumps |
| `info` | Successful record creation, processing start/end, idempotent skips |
| `warning` | Missing data, unresolvable type references, unprocessable directives |
| `error` | CiviCRM unavailable, API exceptions, initialization failures |

## Contributing

1. **Follow Standards**: Drupal coding standards, PHP 8.3+ features
2. **Write Tests**: See [testing.md](testing.md)
3. **Document Changes**: Update the relevant docs in this folder
4. **Use Services**: Implement functionality as services with DI
5. **Check the Backlog**: See [todo.md](todo.md) for open issues

## Resources

- [CiviCRM API4 Documentation](https://docs.civicrm.org/dev/en/latest/api/v4/)
- [Commerce Order Events](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-events)
- [State Machine Workflows](https://docs.drupalcommerce.org/commerce2/developer-guide/orders/order-workflows)
