# Hooks, Events & Extension Points

## Drupal hooks implemented

### `hook_help` (`commerce_civicrm_help`)

Provides the module help page at `/admin/help/commerce_civicrm`.

### `hook_runtime_requirements` (`commerce_civicrm_runtime_requirements`)

Reports CiviCRM availability on the status-report page. Uses `CivicrmHelper::isAvailable()`. Uses the `RequirementSeverity` enum (`::OK`, `::Warning`, `::Error`).

### `hook_install` (`commerce_civicrm_install`)

Calls `commerce_civicrm_add_field_to_all_product_types()` to add the `field_civicrm` field storage and field instances to every existing Commerce product type.

### `hook_uninstall` (`commerce_civicrm_uninstall`)

- Deletes config objects: `commerce_civicrm.settings`, `views.view.commerce_civicrm_my_orders`.
- Removes `field_civicrm` field instances and field storage from all product bundles.
- Removes CiviCRM `Commerce_Order` custom group and fields.
- Clears discovery, config, views, and field caches.

### `hook_commerce_product_type_insert` (`commerce_civicrm_commerce_product_type_insert`)

Fires when a new Commerce product type is created. Automatically adds `field_civicrm` to the new bundle.

### `hook_form_FORM_ID_alter` (`commerce_civicrm_form_commerce_product_form_alter`)

Augments the Commerce product edit form with a "CiviCRM Integration" details group containing:

| Element | Type | Description |
|---|---|---|
| `civicrm[enabled]` | checkbox | Master toggle |
| `civicrm[entity]` | select | Entity type: contribution, membership, event, mailing |
| `civicrm[membership_type]` | select | CiviCRM membership type (conditional on entity = membership) |
| `civicrm[contribution_type]` | select | CiviCRM financial type (conditional on entity = contribution) |
| `civicrm[event_id]` | select | CiviCRM event (conditional on entity = event) |
| `civicrm[participant_role_id]` | select | Participant role (conditional on entity = event) |
| `civicrm[mailing_group_id]` | select | Mailing group (conditional on entity = mailing) |
| `civicrm[mailing_preferences]` | checkboxes | Double opt-in, welcome message, update existing |

Options are populated live from CiviCRM via the `ProductFormHelper` service, which delegates to `ContactUpdater` and `MembershipUpdater` for option lists.

### `commerce_civicrm_product_form_submit`

Custom submit handler prepended to the product form. Delegates to `ProductFormHelper::submitProductForm()`, which serialises CiviCRM form values to JSON and stores them in `field_civicrm`.

---

## Event subscriber

### `OrderCompleteSubscriber`

**Service ID:** `commerce_civicrm.order_complete_subscriber`  
**Tag:** `event_subscriber`  
**Priority:** `-50` (runs late, after Commerce's own handlers)

| Event | Handler | Behaviour |
|---|---|---|
| `commerce_order.place.post_transition` | `onOrderPlace` | Only processes `draft → completed` transitions. Calls `processOrder()`. |
| `commerce_order.validate.post_transition` | `onOrderValidate` | Processes all transitions. Calls `processOrder()`. |
| `commerce_order.fulfill.post_transition` | `onOrderFulfill` | Processes all transitions. Calls `processOrder()`. |
| `commerce_order.cancel.post_transition` | `onOrderCancel` | Only processes transitions to `canceled`. Calls `processCancellation()`. |

> **Note:** With the default "Default" Commerce order workflow (`draft → completed`), only `onOrderPlace` fires. Workflows with validation or fulfillment steps trigger the corresponding additional handlers. See [FMO #31](../fmo/31-order-complete-subscriber-extensibility.md) for a detailed analysis of extensibility and potential duplicate processing concerns.

---

## Extension points for custom modules

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

### 4. Pre/post-processing via hook_entity_presave / hook_entity_update

React to CiviCRM-field changes on products or order-state changes on orders.
