# Architecture

## Module structure

```
commerce_civicrm/
├── commerce_civicrm.info.yml          # Module metadata & dependencies
├── commerce_civicrm.install           # hook_install, hook_uninstall, hook_runtime_requirements
├── commerce_civicrm.module            # Hooks: help, form alter, product‐type insert
├── commerce_civicrm.services.yml      # Service definitions & DI wiring
├── composer.json                      # Composer metadata
├── config/install/
│   └── views.view.commerce_civicrm_my_orders.yml  # "My orders" view
└── src/
    ├── EventSubscriber/
    │   └── OrderCompleteSubscriber.php # Listens to Commerce workflow transitions
    └── Service/
        ├── CivicrmHelper.php           # CiviCRM bootstrap, availability & maintenance mode
        ├── ContactUpdater.php          # Contact CRUD, dedup, option lists via API4
        ├── ContributionUpdater.php     # Contribution CRUD via API4
        ├── MailingUpdater.php          # Mailing group subscriptions via API4
        ├── MembershipUpdater.php       # Membership CRUD via API4
        ├── OrderCivicrmUpdater.php     # Orchestrator: reads product config, delegates
        └── ProductFormHelper.php       # Product form alter/submit for CiviCRM settings
```

## Service dependency graph

```
                             ┌───────────────────────────┐
                             │ OrderCompleteSubscriber    │
                             │ (event_subscriber tag)     │
                             └────────────┬──────────────┘
                                          │ commerce_order.*.post_transition
                                          ▼
                             ┌───────────────────────────┐
                             │ OrderCivicrmUpdater        │
                             │ (orchestrator)             │
                             └──┬──────┬─────┬──────┬────┬─┘
                                │      │     │      │    │
                    ┌───────────┘      │     │      │    └────────┐
                    ▼                  ▼     ▼      ▼             ▼
          ┌──────────────┐  ┌──────────────┐ ┌──────────────┐  ┌──────────────┐ ┌──────────────┐
          │CivicrmHelper │  │ContactUpdater│ │Contribution  │  │Membership    │ │Mailing       │
          │              │  │              │ │Updater       │  │Updater       │ │Updater       │
          └──────────────┘  └──────────────┘ └──────────────┘  └──────────────┘ └──────────────┘
                 ▲                 │                │                  │              │
                 │ initialize()    │                │                  │              │
                 └─────────────────┴────────────────┴──────────────────┴──────────────┘
```

All five leaf services depend on `CivicrmHelper` for CiviCRM bootstrap.

## Data flow — happy path (order placement)

1. **Customer completes checkout** — Commerce fires `commerce_order.place.post_transition`.
2. **OrderCompleteSubscriber::onOrderPlace()** — validates the transition is `draft → completed`, then calls `OrderCivicrmUpdater::processOrder()`.
3. **OrderCivicrmUpdater::processOrder()**
   - Checks CiviCRM availability via `CivicrmHelper::isAvailable()`.
   - Resolves the Drupal user → CiviCRM contact via `ContactUpdater::getContactIdByUser()` (UFMatch lookup).
   - Iterates over every order item.
4. **OrderCivicrmUpdater::processOrderItem()** (for each item)
   - Loads the product variation → product.
   - Reads the `field_civicrm` JSON blob via `getCivicrmProductSettings()`.
   - If `membership_type_id` is set → `MembershipUpdater::createMembershipFromOrder()`.
   - If both `membership_type_id` and `financial_type_id` are set → `createLinkedMembershipContribution()` (uses `\Civi\Api4\Order::create` for atomic Contribution+Membership+LineItem).
   - If `financial_type_id` is set → `ContributionUpdater::createContributionFromOrderWithFinancialType()`.
   - If entity type is `mailing` → `MailingUpdater::processMailingSubscriptionFromOrder()`.
5. **MembershipUpdater / ContributionUpdater / MailingUpdater** — call CiviCRM API4 to create records; return entity IDs.
6. **processOrder()** returns an array like `['memberships' => [42], 'contributions' => [99]]`.

## Data flow — cancellation

1. Commerce fires `commerce_order.cancel.post_transition`.
2. **OrderCompleteSubscriber::onOrderCancel()** — calls `OrderCivicrmUpdater::processCancellation()`.
3. **processCancellation()** iterates order items:
   - For each membership item → `MembershipUpdater::cancelMembershipFromOrder()` sets status to `Cancelled`.
   - For the order → `ContributionUpdater::cancelContributionFromOrder()` sets contribution status to `Cancelled`.
   - Each cancellation is wrapped in an independent try/catch — one failure doesn’t block others.
4. Returns a structured results array with per-item success/failure tracking.

## Product configuration storage

Each Commerce product carries a `field_civicrm` (type `text_long`) containing a JSON object:

```json
{
  "enabled": true,
  "entity": "membership",
  "entity_id": 3,
  "membership_type_id": 3,
  "financial_type_id": null
}
```

The JSON is written by `ProductFormHelper::submitProductForm()` and read by `OrderCivicrmUpdater::getCivicrmProductSettings()`.

See [product-field-schema.md](product-field-schema.md) for the full schema.

## Logging

All services log to the `commerce_civicrm` logger channel. Granularity:

| Level | Used for |
|---|---|
| `debug` | Per-item processing steps, raw settings dumps |
| `info` | Successful record creation, order processing start/end |
| `warning` | Missing data (no customer, no billing profile, no CiviCRM config) |
| `error` | CiviCRM unavailable, API exceptions, initialization failures |

View logs: `drush ws --severity=debug --filter=commerce_civicrm` or at `/admin/reports/dblog`.

## Procedural code in `.module` / `.install`

The module uses a service-based architecture. The `.module` file contains thin hook stubs that delegate to services:

| Function | Concern | Delegates to |
|---|---|---|
| `commerce_civicrm_form_commerce_product_form_alter()` | Product form widget | `ProductFormHelper::alterProductForm()` |
| `commerce_civicrm_product_form_submit()` | Form submit → JSON | `ProductFormHelper::submitProductForm()` |
| `commerce_civicrm_add_field_to_product_type()` | Field provisioning | Direct (procedural, appropriate for hook context) |
| `commerce_civicrm_add_field_to_all_product_types()` | Field provisioning | Direct (procedural, appropriate for hook context) |
| `commerce_civicrm_help()` | Help page HTML | Direct (procedural, standard Drupal pattern) |

All CiviCRM API calls are in `src/Service/` classes. The `.module` file contains zero CiviCRM calls.

All services use OOP API4 (`\Civi\Api4\Entity::action(FALSE)`). No procedural `civicrm_api4()` or static `\Drupal::` calls remain in `src/Service/` (except two `\Drupal::time()` calls — see [todo.md #35](todo.md)).
