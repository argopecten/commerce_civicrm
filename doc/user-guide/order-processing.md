# Order Processing

## Overview

When an order passes a configured workflow transition, the module creates
CiviCRM records based on each product's configuration. Processing is handled
entirely in the background — the customer sees a normal order confirmation
regardless of the CiviCRM outcome — and is **idempotent**: reprocessing an
already-processed order creates nothing.

## Workflow Transitions

Which transitions trigger processing is **configuration**
([Configuration](configuration.md)), not code:

| Setting | Default | Effect |
|---|---|---|
| `order.create_transitions` | `[place]` | These transitions create CiviCRM records |
| `order.cancel_transitions` | `[cancel]` | These transitions cancel them |
| `order.workflows` | `[]` (all) | Optional workflow allowlist |

The subscriber (`OrderCompleteSubscriber`) listens to every order workflow
transition, so custom workflows work by listing their transition IDs. Because
processing is idempotent, several create transitions can be listed to cover
workflows that chain transitions in one order save.

## Processing Flow (create)

### Step 1 — Pre-flight checks

1. **CiviCRM ready** — available and not in maintenance mode; otherwise the
   order is skipped (and can be replayed later with
   `drush commerce-civicrm:process-order`).
2. **Contact resolution** — the customer's UFMatch link; if there is none and
   `contact.fallback` is `match_or_create`, the module matches or creates a
   contact from the order's e-mail and billing profile. If no contact can be
   resolved, processing stops with a warning.
3. **Idempotency** — if a contribution linked to the order already exists
   (custom field `Commerce_Order.commerce_order_id`), processing stops.

### Step 2 — Directives

Each order item whose product has CiviCRM processing enabled yields a
*directive*: what to create (membership / contribution / event / mailing),
with which CiviCRM type, and the item's amount, quantity and label. Site-
specific code can rewrite or expand these (e.g. split a bundle product into
its components) — see
[Hooks & Events](../development/hooks-and-events.md).

### Step 3 — One contribution per order

All financial directives (membership, contribution, event) become **one
CiviCRM contribution** created through the CiviCRM Order API, with one line
item each:

| Product type | Line item creates |
|---|---|
| Membership | A membership of the configured type — or a **renewal** of the contact's existing membership of that type (status New/Current/Grace) |
| Event | A `Registered` participant on the configured event |
| Contribution | A plain contribution line with the configured financial type |

Contribution details:

- `source` = `Commerce Order #<id>`; custom fields link the contribution to
  the order (and payment, if any).
- `receive_date` = the completed payment's timestamp, falling back to the
  order completion time.
- Payment gateway mapping: `trxn_id` = the gateway's remote transaction ID;
  the payment instrument comes from the configured
  `payment_instrument_map`.
- The contribution starts **Pending**; when the order is paid (completed
  state or zero balance), a CiviCRM Payment is recorded, which flips the
  contribution and its memberships/participants to **Completed** with proper
  financial transactions. Unpaid orders leave a Pending contribution.

Membership dates follow the configured `membership.date_mode` — CiviCRM
computes them by default; `dispatch` mode lets site code supply them (see
[Configuration](configuration.md)).

### Step 4 — Mailing subscriptions

Mailing directives are processed outside the contribution: the contact is
added to the configured CiviCRM group (status `Added`, or `Pending` with
double opt-in) with `source` = `Commerce Order #<id>`.

## Cancellation Processing

On a configured cancel transition the module cancels **exactly what it
created**, by walking the line items of every contribution linked to the
order (the initial one and any renewals):

| Record | Action |
|---|---|
| Memberships from the order's line items | Status → `Cancelled` (status override, source note) |
| Participants from the order's line items | Status → `Cancelled` |
| All linked contributions | Status → `Cancelled` |
| Mailing groups configured on the order's products | Contact removed (status `Removed`) |

Each cancellation is independent — one failure does not block the others.

## Renewal Payments

Recurring charges (e.g. subscription renewals) usually arrive as a new
payment on the **same** Commerce order, without a workflow transition. Site
code calls the renewal API
(`commerce_civicrm.renewal_processor::recordRenewalPayment()`) with the new
completed payment; the module then:

- creates a **new** contribution for the charge (idempotent per payment),
  with amounts split proportionally across the order's items so they sum
  exactly to the charged amount;
- **extends** the existing memberships instead of creating new ones.

See [Hooks & Events](../development/hooks-and-events.md) for wiring this up.

## Replaying Orders

```bash
drush commerce-civicrm:process-order 128          # one order
drush commerce-civicrm:process-order 128,129,130  # several
```

Safe on already-processed orders (they are skipped). Useful for orders paid
while the module was disabled or CiviCRM was in maintenance.

## Error Handling

- **CiviCRM unavailable / maintenance**: processing is skipped entirely; the
  order completes normally in Commerce. There is no automatic retry — replay
  affected orders with the drush command.
- **Unresolvable configuration** (unknown membership/financial type name,
  event without ID): the directive is skipped with a warning; other
  directives still process.
- **Customer visibility**: errors are never shown to the customer; all
  failures are logged to the `commerce_civicrm` channel only.

## Logging

All messages use the `commerce_civicrm` logger channel.

| Level | Examples |
|-------|---------|
| **info** | Processing start/end, created record IDs, idempotent skips |
| **warning** | Unresolvable contact or type references, skipped directives |
| **error** | CiviCRM unavailable, API exceptions |
| **debug** | Contact matching details, extracted data dumps |

Sample messages:

```
INFO: Processing order 456 transition paid (pending → paid, workflow magyar_hang_workflow) for CiviCRM record creation
INFO: Completed processing order 456. Created records: {"contributions":[101],"memberships":[789]}
INFO: Contribution already exists for order 456: 101 - skipping
WARNING: Could not resolve a CiviCRM contact for order 456 (customer 12, fallback: none)
ERROR: CiviCRM is not available - skipping order 456 processing
```

## Service Architecture

| Service | Role |
|---------|------|
| `commerce_civicrm.order_complete_subscriber` | Routes configured workflow transitions |
| `commerce_civicrm.order_civicrm_updater` | Orchestrator — directives → Order API contribution |
| `commerce_civicrm.renewal_processor` | Renewal payments on processed orders |
| `commerce_civicrm.contact_updater` | Contact resolution (UFMatch, match-or-create) |
| `commerce_civicrm.contribution_updater` | Contribution lookups, payment instruments, cancellation |
| `commerce_civicrm.membership_updater` | Membership lookups and cancellation |
| `commerce_civicrm.participant_updater` | Participant cancellation |
| `commerce_civicrm.mailing_updater` | Mailing group subscriptions |
| `commerce_civicrm.civicrm_helper` | CiviCRM bootstrap, availability, name→ID resolution |
| `commerce_civicrm.product_form_helper` | Product form UI for CiviCRM settings |

## Next Steps

- [Troubleshooting](troubleshooting.md) — resolve common processing issues
- [Services](../development/services.md) — technical service documentation
