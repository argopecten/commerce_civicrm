# Architecture

## Module structure

```
commerce_civicrm/
├── commerce_civicrm.info.yml          # Module metadata & dependencies
├── commerce_civicrm.install           # hook_install, hook_uninstall, hook_runtime_requirements
├── commerce_civicrm.module            # Hooks: help, form alter, product-type insert, field provisioning
├── commerce_civicrm.services.yml      # Service definitions & DI wiring
├── composer.json                      # Composer metadata
├── config/
│   ├── install/
│   │   ├── commerce_civicrm.settings.yml              # Default module settings
│   │   └── views.view.commerce_civicrm_my_orders.yml  # "My orders" view
│   └── schema/
│       └── commerce_civicrm.schema.yml                # Config schema for the settings
├── src/
│   ├── Drush/Commands/
│   │   └── CommerceCivicrmCommands.php # drush commerce-civicrm:process-order (replay)
│   ├── Event/
│   │   ├── CommerceCivicrmEvents.php   # Event name constants (6 events)
│   │   ├── OrderItemDirectivesEvent.php
│   │   ├── MembershipDatesEvent.php
│   │   ├── ContributionParamsEvent.php
│   │   ├── OrderProcessedEvent.php
│   │   └── RenewalRecordedEvent.php
│   ├── EventSubscriber/
│   │   └── OrderCompleteSubscriber.php # Config-routed workflow transition handling
│   ├── Service/
│   │   ├── CivicrmHelper.php           # Bootstrap, availability/maintenance checks,
│   │   │                               #   custom-field provisioning, name→ID resolution
│   │   ├── ContactUpdater.php          # UFMatch lookup, match-or-create fallback, option lists
│   │   ├── ContributionUpdater.php     # Contribution lookups, payment instrument, cancellation
│   │   ├── MailingUpdater.php          # Mailing group subscriptions (GroupContact)
│   │   ├── MembershipUpdater.php       # Membership lookups & cancellation
│   │   ├── OrderCivicrmUpdater.php     # Orchestrator: directives → Order API contribution
│   │   ├── ParticipantUpdater.php      # Participant cancellation
│   │   ├── ProductFormHelper.php       # Product form alter/submit for CiviCRM settings
│   │   └── RenewalProcessor.php        # Renewal payments on already-processed orders
│   └── Util/
│       └── AmountSplitter.php          # Proportional bcmath amount splitting
└── tests/src/Unit/
    ├── AmountSplitterTest.php
    └── OrderCompleteSubscriberTest.php
```

## Service dependency graph

```
 commerce_order.post_transition          site code (e.g. payment subscriber)
              │                                        │
              ▼                                        ▼
┌─────────────────────────┐            ┌─────────────────────────┐
│ OrderCompleteSubscriber │            │ RenewalProcessor        │
│ (config-routed)         │            │ recordRenewalPayment()  │
└───────────┬─────────────┘            └───────────┬─────────────┘
            │ processOrder() / processCancellation │ buildOrderDirectives()
            ▼                                      │ createCiviOrder()
┌──────────────────────────────────────────────────▼─┐
│ OrderCivicrmUpdater  (orchestrator)                │──── event_dispatcher,
└──┬──────────┬─────────────┬────────────┬────────┬──┘     config.factory,
   │          │             │            │        │        datetime.time
   ▼          ▼             ▼            ▼        ▼
┌────────┐ ┌────────────┐ ┌──────────┐ ┌───────┐ ┌───────────┐
│Contact │ │Contribution│ │Membership│ │Mailing│ │Participant│
│Updater │ │Updater     │ │Updater   │ │Updater│ │Updater    │
└───┬────┘ └─────┬──────┘ └────┬─────┘ └───┬───┘ └─────┬─────┘
    │            │             │           │           │
    └────────────┴──────┬──────┴───────────┴───────────┘
                        ▼
                ┌───────────────┐        ┌───────────────────┐
                │ CivicrmHelper │◄───────│ ProductFormHelper │
                └───────────────┘        │ (product forms)   │
                                         └───────────────────┘
```

All leaf services depend on `CivicrmHelper` for CiviCRM bootstrap and
name→ID resolution. `ProductFormHelper` uses `MembershipUpdater` and
`ContactUpdater` for its option lists.

## Data flow — order processing

1. **Workflow transition** — state_machine dispatches the group-level
   `commerce_order.post_transition` event for every transition of every order
   workflow.
2. **`OrderCompleteSubscriber::onTransition()`** (priority `-50`) — checks the
   optional `order.workflows` allowlist, then matches the transition ID
   against `order.create_transitions` (→ `processOrder()`) or
   `order.cancel_transitions` (→ `processCancellation()`). Transition info
   (`transition`, `from_state`, `to_state`, `workflow`) is passed as context.
3. **`OrderCivicrmUpdater::processOrder()`**
   - Guard: `CivicrmHelper::isReadyForOperations()` (bootstrap + not in
     maintenance mode).
   - Contact: `resolveContactId()` — UFMatch of the order's customer; when
     `contact.fallback: match_or_create`, falls back to
     `ContactUpdater::matchOrCreateContactFromOrder()` (email match → dedupe
     rule → strict name+email lookup → create Individual from the billing
     profile).
   - Ensures the `Commerce_Order` custom group exists
     (`ensureCommerceOrderCustomFields()`).
   - **Idempotency**: if `ContributionUpdater::findExistingContributionForOrder()`
     finds a contribution linked to the order, processing stops and the
     existing ID is returned.
4. **Directive building** — `buildOrderDirectives()` walks the order items.
   For each item a default directive is derived from the product's
   `field_civicrm` JSON, then `OrderItemDirectivesEvent` lets subscribers
   replace or expand it (e.g. split a bundle product into one directive per
   component). Directives are partitioned into *financial*
   (`membership` / `contribution` / `event`) and *mailing*.
5. **`createCiviOrder()`** — one CiviCRM contribution per order:
   - Builds one Order API line item per financial directive
     (see below), summing line totals with bcmath.
   - `receive_date` comes from the latest completed payment, falling back to
     the order completion time, then the request time.
   - Contribution values carry `Commerce_Order.commerce_order_id`, and — when
     a payment exists — `Commerce_Order.commerce_payment_id`, `trxn_id`
     (gateway remote ID) and the mapped `payment_instrument_id`.
   - `ContributionParamsEvent` lets subscribers alter contribution values and
     line items immediately before the API call.
   - `\Civi\Api4\Order::create()` creates the contribution as **Pending**
     (the Order API computes the total from the line items).
   - When the order is paid (state `completed`/`shipped`/`paid` or zero
     balance), a follow-up `\Civi\Api4\Payment::create()` records the money
     and CiviCRM's payment-completion logic flips the contribution and its
     memberships/participants to **Completed** with proper financial
     transactions.
   - When membership dates were dispatched (`membership.date_mode: dispatch`),
     the dispatched dates are re-asserted afterwards, because CiviCRM's
     payment completion renews memberships by one term and would override the
     site-supplied `end_date`.
   - Created record IDs are collected from the contribution's line items.
6. **Mailing directives** — each becomes a
   `MailingUpdater::processMailingSubscriptionFromOrder()` call
   (GroupContact create/update; `double_opt_in` maps to status `Pending`).
7. **`OrderProcessedEvent`** is dispatched with the created record IDs.

### Line item shapes

`buildLineItem()` produces flat Order-API `LineItem` arrays; values for the
related entity are passed as `entity_id.FIELD` keys:

| Directive type | Line item | Notes |
|---|---|---|
| `membership` | `membership_type_id`, `financial_type_id`, `entity_id.join_date` / `start_date` / `end_date`, `entity_id.source` | A numeric `entity_id` (existing New/Current/Grace membership) makes the Order API **renew** that membership instead of creating one. Financial type falls back to the membership type's own. |
| `event` | `entity_table: civicrm_participant`, `entity_id.event_id`, `entity_id.status_id:name: Registered`, `entity_id.role_id`, `financial_type_id` | Financial type falls back to `Event Fee`. |
| `contribution` | `financial_type_id`, `label`, `qty`, `unit_price`, `line_total` | Plain contribution line. |

### Membership dates

`membership.date_mode` controls who computes the dates:

- **`civicrm`** (default) — new memberships get `join_date`/`start_date` =
  today and CiviCRM computes `end_date` from the membership type; renewals
  pass no dates so CiviCRM's renewal logic extends the membership.
- **`dispatch`** — `MembershipDatesEvent` is fired and site code supplies
  authoritative dates (e.g. from a Drupal-side expiry field); NULL dates are
  omitted from the API call.

## Data flow — cancellation

`processCancellation()` cancels exactly what was created:

1. Loads **all** contributions linked to the order
   (`Commerce_Order.commerce_order_id` — initial and renewals).
2. Walks each contribution's line items and cancels the referenced entities:
   `civicrm_membership` → `MembershipUpdater::cancelMembershipById()` (status
   `Cancelled`, `is_override`, source note), `civicrm_participant` →
   `ParticipantUpdater::cancelParticipant()` (status `Cancelled`). Each
   cancellation is independently try/caught.
3. Sets every linked contribution's status to `Cancelled`
   (`ContributionUpdater::cancelContributionsFromOrder()`).
4. Mailing groups are not linked to the contribution, so they are re-derived
   from the order's directives and the contact is removed
   (`MailingUpdater::removeContactFromMailingGroup()`, status `Removed`).
5. Dispatches `OrderProcessedEvent` as `ORDER_CANCELLED`.

## Data flow — renewal payments

Recurring charges typically arrive on the same Commerce order without a
workflow transition, so the transition pipeline never sees them. Site code
(e.g. a `PaymentEvents` subscriber) calls
`RenewalProcessor::recordRenewalPayment($order, $payment)`:

1. Guards: CiviCRM ready, payment state `completed`, contact resolvable, an
   **initial** contribution exists for the order (otherwise the payment is
   not a renewal).
2. **Idempotency per payment**: if a contribution already references the
   payment (`Commerce_Order.commerce_payment_id`, falling back to `trxn_id`),
   nothing is created.
3. Rebuilds the order's financial directives and calls `createCiviOrder()`
   with the payment amount as total override — directive amounts are scaled
   proportionally (`Util\AmountSplitter`) so they sum exactly to the charge.
4. Membership line items reference the existing memberships (`entity_id`), so
   the Order API extends them.
5. Dispatches `RenewalRecordedEvent`.

## Idempotency & record linking

The `Commerce_Order` custom group (extends Contribution, auto-provisioned at
install and re-checked at runtime) carries two integer fields:

| Field | Set | Used for |
|---|---|---|
| `commerce_order_id` | on every contribution created from an order | one-contribution-per-order guard; cancellation lookup |
| `commerce_payment_id` | when the contribution reflects a specific payment (renewals) | one-contribution-per-payment guard |

A legacy fallback matches contributions by exact
`source = 'Commerce Order #<id>'` and backfills the custom field on hit.

## Product configuration storage

Each Commerce product carries a `field_civicrm` (type `text_long`) containing
a JSON object, e.g.:

```json
{
  "enabled": true,
  "entity": "membership",
  "membership_type": "Plusz előfizetés",
  "financial_type": null
}
```

The JSON is written by `ProductFormHelper::submitProductForm()` and read by
`OrderCivicrmUpdater::getCivicrmProductSettings()`. Type references are
stored by CiviCRM *name* and resolved to IDs at processing time
(`CivicrmHelper::resolveMembershipTypeId()` etc., per-request cached).

See [product-field-schema.md](product-field-schema.md) for the full schema.

## Logging

All services log to the `commerce_civicrm` logger channel. Granularity:

| Level | Used for |
|---|---|
| `debug` | Per-item processing steps, raw settings dumps |
| `info` | Successful record creation, order processing start/end, idempotent skips |
| `warning` | Missing data (no billing profile, unresolvable type references, unprocessable directives) |
| `error` | CiviCRM unavailable, API exceptions, initialization failures |

View logs: `drush ws --filter=commerce_civicrm` (dblog) or your syslog,
depending on the site's logging setup.

## Procedural code in `.module` / `.install`

The `.module` file contains thin hook stubs that delegate to services, plus
the `field_civicrm` provisioning helpers:

| Function | Concern | Delegates to |
|---|---|---|
| `commerce_civicrm_form_commerce_product_form_alter()` | Product form widget | `ProductFormHelper::alterProductForm()` |
| `commerce_civicrm_product_form_submit()` | Form submit → JSON | `ProductFormHelper::submitProductForm()` |
| `commerce_civicrm_commerce_product_type_insert()` | Auto-field on new product types | `commerce_civicrm_add_field_to_product_type()` |
| `commerce_civicrm_add_field_to_product_type()` | Field provisioning | Direct (procedural, hook context) |
| `commerce_civicrm_add_field_to_all_product_types()` | Field provisioning | Direct (procedural, hook context) |
| `commerce_civicrm_help()` | Help page HTML | Direct (standard Drupal pattern) |

All CiviCRM API calls live in `src/Service/` (plus the event classes' type
hints); the `.module` file contains none. All services use OOP API4
(`\Civi\Api4\Entity::action(FALSE)`); there are no procedural
`civicrm_api4()` calls and no static `\Drupal::` calls in services (the Drush
command uses `\Drupal::service()`, which is conventional there).
