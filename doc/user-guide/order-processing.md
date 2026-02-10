# Order Processing

## Overview

When a customer completes an order, the module automatically creates CiviCRM
records based on each product's configuration. Processing is triggered by
Commerce workflow transitions and handled entirely in the background — the
customer sees a normal order confirmation regardless of CiviCRM outcomes.

## Workflow Transitions

`OrderCompleteSubscriber` listens to four Commerce `post_transition` events
(all at priority `-50`):

| Handler | Event | State Guard | Calls |
|---------|-------|-------------|-------|
| `onOrderPlace()` | `commerce_order.place.post_transition` | Only `draft → completed` | `processOrder()` |
| `onOrderValidate()` | `commerce_order.validate.post_transition` | None — processes unconditionally | `processOrder()` |
| `onOrderFulfill()` | `commerce_order.fulfill.post_transition` | None — processes unconditionally | `processOrder()` |
| `onOrderCancel()` | `commerce_order.cancel.post_transition` | Only transitions to `canceled` | `processCancellation()` |

### Which Handlers Fire Per Workflow

| Commerce Workflow | Transitions | Handlers That Fire |
|-------------------|------------|-------------------|
| **Default** (`order_default`) | draft → completed | `onOrderPlace` only |
| **Fulfillment** (`order_default_validation`) | draft → validation → fulfillment → completed | `onOrderPlace` (skipped: to≠completed), `onOrderValidate`, `onOrderFulfill` |

> **Known issue**: In fulfillment workflows, `processOrder()` runs twice (on
> validate and on fulfill). The linked membership+contribution path has an
> internal duplicate guard, but standalone contributions may be created twice.
> See [todo #37](../development/todo.md).

## Processing Flow

### Step 1 — Pre-Flight Checks

`OrderCivicrmUpdater::processOrder()` runs three guards:

1. **CiviCRM availability** — `CivicrmHelper::isAvailable()`. If CiviCRM is down
   or in maintenance mode, processing is skipped and the order completes normally.
2. **Customer exists** — `$order->getCustomer()`. Anonymous orders are skipped.
3. **Contact resolution** — `ContactUpdater::getContactIdByUser()` finds or
   creates a CiviCRM contact for the Drupal user. If this fails, processing stops.

### Step 2 — Per-Item Processing

Each order item is processed individually inside its own `try/catch` block
(catches `\CRM_Core_Exception`). One item's failure does not block others.

For each item, `processOrderItem()`:

1. Gets the product variation and parent product
2. Reads CiviCRM settings via `getCivicrmProductSettings()` (parses the
   `field_civicrm` JSON)
3. Checks the `enabled` flag
4. Dispatches to the appropriate branch:

### Step 3 — Entity Type Branching

| Configuration | Branch | Service Called | CiviCRM Record(s) Created |
|---------------|--------|---------------|--------------------------|
| Membership type + financial type | Linked | `OrderCivicrmUpdater::createLinkedMembershipContribution()` | `Membership` + `Contribution` + `LineItem` + `MembershipPayment` (via Order API) |
| Membership type only | Membership | `MembershipUpdater::createMembershipFromOrder()` | `Membership` |
| Financial type only | Contribution | `ContributionUpdater::createContributionFromOrderWithFinancialType()` | `Contribution` |
| Entity = mailing + group ID | Mailing | `MailingUpdater::processMailingSubscriptionFromOrder()` | `GroupContact` |
| Entity = event | **Not implemented** | — | Silently skipped |

### Linked Membership + Contribution (Order API)

This is the most sophisticated path. It uses `\Civi\Api4\Order::create()` to
atomically create all records in a single CiviCRM transaction:

1. Calls `ensureCommerceOrderCustomFieldExists()` to auto-provision the
   `Commerce_Order.commerce_order_id` custom field
2. Checks for **existing contributions** via `findExistingContributionForOrder()`
   (queries by custom field, falls back to `source` string, backfills on match)
3. Checks for **existing memberships** via `findExistingMembershipForRenewal()`
   (matches contact + type + status in `[New, Current, Grace]`)
4. Calls `Order::create()` with contribution values and a membership line item
5. If an existing membership is found, passes `membership_id` so CiviCRM
   processes it as a renewal

### Membership Only

`MembershipUpdater::createMembershipFromOrder()`:

1. Checks for existing membership of the same type for the same contact
2. If found in state `[New, Current, Grace]` → calls `updateMembership()` (renewal)
3. If not found → creates new membership with status `New`
4. Sets `join_date` and `start_date` to today; **omits `end_date`** so CiviCRM
   calculates it from the membership type definition
5. After creation, calls `updateMembershipStatus()` to set the right status
   based on the Commerce order state

### Contribution Only

`ContributionUpdater::createContributionFromOrderWithFinancialType()`:

1. Creates a `Contribution` with the order's total amount and currency
2. Sets `source` to `Commerce Order #<id>`
3. Sets `Commerce_Order.commerce_order_id` custom field for cross-referencing
4. Maps Commerce order state to CiviCRM contribution status:
   completed → Completed, canceled → Cancelled, pending → Pending

### Mailing Subscription

`MailingUpdater::processMailingSubscriptionFromOrder()`:

1. Checks existing group membership via `GroupContact::get()`
2. If double opt-in enabled → sets status to `Pending`; otherwise `Added`
3. Handles re-subscription (previously removed contacts)
4. Double opt-in email and welcome message are stubs (log only, not yet sending)

## Contact Management

`ContactUpdater::getContactIdByUser()` resolves a Drupal user to a CiviCRM
contact:

1. Looks up `UFMatch` record (Drupal user ↔ CiviCRM contact mapping)
2. If found, updates the existing contact with current information
3. If not found, uses CiviCRM's deduplication rules (`Contact::getDuplicates()`
   with the `Individual.Supervised` rule)
4. If still not found, creates a new contact

Contact data is extracted from the order's billing profile, including address
fields mapped to CiviCRM's `address_primary.*` join paths.

## Cancellation Processing

`OrderCivicrmUpdater::processCancellation()` runs when an order transitions to
`canceled`:

| Entity Type | Cancellation Action | Per-Item? |
|-------------|-------------------|-----------|
| Membership | `MembershipUpdater::cancelMembershipFromOrder()` — sets status to Cancelled | Yes |
| Contribution | `ContributionUpdater::cancelContributionFromOrder()` — sets status to Cancelled | Once per order |
| Mailing | **Not handled** — contact remains subscribed | — |
| Event | **Not handled** | — |

Each cancellation is wrapped in its own `try/catch` — one failure does not
block others.

> **Known gap**: Mailing subscriptions are not reversed on cancellation. The
> `MailingUpdater::removeContactFromMailingGroup()` method exists but is never
> called from `processCancellation()`.

## Duplicate Prevention

| Record Type | Guard | Method |
|-------------|-------|--------|
| Linked contribution | Custom field `Commerce_Order.commerce_order_id` + `source` fallback | `findExistingContributionForOrder()` |
| Membership | Same contact + same type + status in `[New, Current, Grace]` | `findExistingMembership()` / `findExistingMembershipForRenewal()` |
| Mailing subscription | `GroupContact::get()` check | `checkGroupMembership()` |
| Standalone contribution | **No duplicate guard** | — |

## Error Handling

- **Per-item isolation**: Each order item is processed in its own `try/catch`
  (`\CRM_Core_Exception`). One item's failure does not block others.
- **CiviCRM unavailable**: Processing is skipped entirely; the order completes
  normally in Commerce. No retry mechanism exists.
- **Customer visibility**: Errors are **never** shown to the customer. All
  failures are logged to the `commerce_civicrm` log channel only.
- **Admin visibility**: Check `/admin/reports/dblog` filtered by
  `commerce_civicrm`. The status page at `/admin/reports/status` reports
  CiviCRM availability.

## Logging

### Log Channel

All messages use the `commerce_civicrm` logger channel.

### Log Levels

| Level | Examples |
|-------|---------|
| **info** | Successful record creation, processing start/end |
| **warning** | Missing customer, missing billing profile, failed record creation |
| **error** | CiviCRM unavailable, API exceptions, processing failures |
| **debug** | Product settings, per-item processing details |

### Sample Messages

```
INFO: Processing order 456 placement (draft → completed) in workflow order_default
INFO: Created membership 789 for order item 1 (order 456)
WARNING: Order 456 has no customer - skipping CiviCRM integration
ERROR: CiviCRM is not available - skipping order 456 processing
```

## Service Architecture

| Service | Role |
|---------|------|
| `commerce_civicrm.order_complete_subscriber` | Event subscriber — catches Commerce transitions |
| `commerce_civicrm.order_civicrm_updater` | Orchestrator — dispatches to entity-specific services |
| `commerce_civicrm.contact_updater` | Contact resolution and creation |
| `commerce_civicrm.contribution_updater` | Contribution CRUD |
| `commerce_civicrm.membership_updater` | Membership CRUD |
| `commerce_civicrm.mailing_updater` | Mailing group subscriptions |
| `commerce_civicrm.civicrm_helper` | CiviCRM bootstrap, availability, maintenance mode |
| `commerce_civicrm.product_form_helper` | Product form UI for CiviCRM settings |

## Next Steps

- [Troubleshooting](troubleshooting.md) — resolve common processing issues
- [Services Overview](../services/overview.md) — technical service documentation
