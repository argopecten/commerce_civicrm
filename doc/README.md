# Commerce CiviCRM Integration

This documentation covers the Commerce CiviCRM module: a direct, event-driven
integration between Drupal Commerce and CiviCRM. When an order passes a
configured workflow transition, the module creates CiviCRM records —
contributions, memberships, event participants and mailing-group
subscriptions — from the products' CiviCRM configuration.

## Overview

The module listens to Commerce order **workflow transitions** (which
transitions trigger processing is configuration, not code) and converts each
order into **one CiviCRM contribution** created through the CiviCRM
**Order API** (`\Civi\Api4\Order`), with one line item per CiviCRM-enabled
order item: membership line items (created or renewed), participant line
items for event products, and plain contribution lines. Mailing-list products
become `GroupContact` records outside the contribution. Recurring charges on
the same order can be recorded as renewal contributions.

Processing is **idempotent per order** (and per payment for renewals) via a
`Commerce_Order` custom field group on the contribution, so replays are safe.

## Quick Start

1. **Install the module**: `drush en commerce_civicrm && drush cr`
   (`field_civicrm` is added to all product types automatically).
2. **Configure transitions** in `commerce_civicrm.settings` if your order
   workflow doesn't use the default `place` / `cancel` transitions — see
   [Configuration](user-guide/configuration.md).
3. **Configure products**: edit a product and use the *CiviCRM Integration*
   section to pick the record type (contribution, membership, event
   registration or mailing subscription).
4. **Place a test order** and verify the CiviCRM records.
5. **Monitor logs** on the `commerce_civicrm` channel.

## Architecture Overview

### Event flow

1. **Commerce order transition** — state_machine dispatches
   `commerce_order.post_transition` for every order workflow transition.
2. **OrderCompleteSubscriber** — matches the transition ID against the
   configured `order.create_transitions` / `order.cancel_transitions` lists
   (optionally filtered by an `order.workflows` allowlist).
3. **OrderCivicrmUpdater** — resolves the CiviCRM contact, builds one
   *directive* per CiviCRM-enabled order item (site code can alter or expand
   them via `OrderItemDirectivesEvent`), and creates one contribution with
   line items via the CiviCRM Order API, followed by a Payment when the order
   is paid.
4. **Support services** — contact resolution, contribution/membership/
   participant lookups and cancellation, mailing-group subscriptions.
5. **Dispatched events** — six module events let site code customise every
   step and react to results. See
   [Hooks & Events](development/hooks-and-events.md).

### Core components

| Component | Role |
|---|---|
| `OrderCompleteSubscriber` | Routes configured workflow transitions to processing |
| `OrderCivicrmUpdater` | Orchestrator: directives → one contribution per order (Order API) |
| `RenewalProcessor` | Records recurring/renewal payments on already-processed orders |
| `ContactUpdater` | UFMatch lookup, match-or-create fallback, option lists |
| `ContributionUpdater` | Contribution lookups (idempotency), payment instrument mapping, cancellation |
| `MembershipUpdater` | Membership lookups and cancellation |
| `ParticipantUpdater` | Participant cancellation |
| `MailingUpdater` | Mailing group subscriptions (GroupContact) |
| `CivicrmHelper` | CiviCRM bootstrap, availability/maintenance checks, custom-field provisioning, name→ID resolution |
| `ProductFormHelper` | *CiviCRM Integration* section on product edit forms |

## Product Configuration

Products are configured through the *CiviCRM Integration* section of the
product edit form; the settings are stored as JSON in a hidden `field_civicrm`
field (type `text_long`, auto-provisioned on every product type).

Key JSON properties (type references are stored **by name** so shared
catalogs work across sites):

- `enabled` — whether purchasing the product creates CiviCRM records
- `entity` — `contribution` | `membership` | `event` | `mailing`
- `membership_type` / `financial_type` — CiviCRM type name (or numeric ID)
- `event_id`, `participant_role_id` — for event products (IDs, per-site)
- `group`, `mailing_preferences` — for mailing products

See [Product Field Schema](development/product-field-schema.md) for the full
schema and defaults.

## Module Settings

`commerce_civicrm.settings` (config object, no admin UI — manage via
`drush config:set` or config sync):

| Key | Default | Purpose |
|---|---|---|
| `order.create_transitions` | `[place]` | Transition IDs that create CiviCRM records |
| `order.cancel_transitions` | `[cancel]` | Transition IDs that cancel them |
| `order.workflows` | `[]` | Optional workflow-ID allowlist (empty = all) |
| `contact.fallback` | `none` | `match_or_create` enables billing-profile contact matching/creation when the customer has no UFMatch |
| `contribution.payment_instrument_map` | `{manual: Cash, paypal: PayPal}` | Gateway → payment instrument name |
| `contribution.payment_instrument_default` | `Credit Card` | Fallback instrument |
| `membership.date_mode` | `civicrm` | `dispatch` fires `MembershipDatesEvent` so site code supplies membership dates |

See [Configuration](user-guide/configuration.md) for details.

## Verifying the Integration

```bash
drush en commerce_civicrm && drush cr
# All 10 services should be registered:
drush devel:services | grep commerce_civicrm
# Replay an order into CiviCRM (idempotent — safe on processed orders):
drush commerce-civicrm:process-order 128
```

Then place a test order and check:

- New/updated contact record (via UFMatch, or billing-profile fallback)
- One contribution linked to the order (`Commerce_Order.commerce_order_id`)
- Membership / participant records created through the contribution's line
  items (for membership / event products)
- Mailing group membership (for mailing products)

See [Testing](development/testing.md) for the unit tests and the full manual
checklist.

## Log Messages

All services log to the `commerce_civicrm` channel. Typical messages:

- `Processing order @order_id transition @transition (@from → @to, workflow @workflow) for CiviCRM record creation`
- `Completed processing order @order_id. Created records: {"contributions":[…],"memberships":[…]}`
- `Contribution already exists for order @order_id: @contribution_id - skipping`
- `Order @order_id has no CiviCRM-enabled items - nothing to do`
- `Could not resolve a CiviCRM contact for order @order_id (customer @uid, fallback: @fallback)`
- `CiviCRM is not available - skipping order @order_id processing`

## Customization

- **Module events** — subscribe to `OrderItemDirectivesEvent`,
  `MembershipDatesEvent`, `ContributionParamsEvent`, `OrderProcessedEvent`,
  `RenewalRecordedEvent` to alter directives, dates, API parameters, or react
  to results. This is the primary extension mechanism.
- **Renewal API** — call
  `commerce_civicrm.renewal_processor::recordRenewalPayment()` from site code
  (e.g. a payment-insert subscriber) to record recurring charges.
- **Service decoration** — override any module service via Drupal's service
  decoration.
- **hook_form_alter** — extend the *CiviCRM Integration* product form section.

See [Developer Guide](development/developer-guide.md) and
[Hooks & Events](development/hooks-and-events.md).

## Documentation Structure

### User Guide

- **[Installation](user-guide/installation.md)** — requirements, setup, what gets installed
- **[Configuration](user-guide/configuration.md)** — the `commerce_civicrm.settings` reference
- **[Product Configuration](user-guide/product-configuration.md)** — setting up CiviCRM behaviour on products
- **[Order Processing](user-guide/order-processing.md)** — how orders become CiviCRM records
- **[Troubleshooting](user-guide/troubleshooting.md)** — common issues and solutions

### Development

- **[Developer Guide](development/developer-guide.md)** — overview, extension points, documentation index
- **[Architecture](development/architecture.md)** — module structure, service graph, data flow
- **[Services](development/services.md)** — service reference with public API
- **[API Reference](development/api-reference.md)** — CiviCRM API4 patterns used in the module
- **[Hooks & Events](development/hooks-and-events.md)** — Drupal hooks, dispatched events, extension points
- **[Product Field Schema](development/product-field-schema.md)** — `field_civicrm` JSON schema
- **[Field Automation](development/field-automation.md)** — automatic `field_civicrm` provisioning
- **[Testing](development/testing.md)** — unit tests and manual test procedures
- **[TODO / Backlog](development/todo.md)** — open issues and development backlog
