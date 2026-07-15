# Product Configuration

## Overview

Each Commerce product can be configured to trigger CiviCRM operations when
purchased. Configuration is done through a "CiviCRM Integration" section on
the product edit form; the settings are stored as JSON in a single, hidden
`field_civicrm` field.

The module supports four entity types:

| Entity Type | CiviCRM Record Created |
|-------------|----------------------|
| **Contribution** | Contribution line with the chosen financial type |
| **Membership** | Membership of the chosen type (created or renewed) + its contribution line |
| **Event** | `Registered` participant on the chosen event + its contribution line |
| **Mailing** | Subscription to the chosen mailing group |

All financial records of one order end up in **one CiviCRM contribution**
with one line item per product — see
[Order Processing](order-processing.md).

## Configuration UI

When editing a product, the **CiviCRM Integration** section appears in the
Advanced sidebar. (If it is missing, CiviCRM is unavailable or the product
type has no `field_civicrm` field — see
[Troubleshooting](troubleshooting.md).)

### Common Settings

- **Enable CiviCRM Processing for this Product** — master toggle
- **CiviCRM Entity Type** — Contribution, Membership, Event Registration, or
  Mailing List Subscription; the type-specific selects below appear
  accordingly

### Contribution Settings

- **CiviCRM Financial Type** — active financial types from CiviCRM (sorted by
  label).

### Membership Settings

- **CiviCRM Membership Type** — active membership types from CiviCRM.

The contribution line of a membership product automatically uses the
membership type's own financial type. (A different financial type can be set
in the JSON — key `financial_type` — if ever needed.)

### Event Settings

- **CiviCRM Event** — active events (newest first)
- **Participant Role** — active participant roles

Note that CiviCRM events are per-site records referenced by ID, so event
products are not portable across sites the way membership/contribution
products are.

### Mailing Settings

- **CiviCRM Mailing Group** — active groups of type *Mailing List*
- **Mailing Preferences**:
  - *Require double opt-in confirmation* — the subscription is stored with
    status `Pending` instead of `Added` (note: the confirmation e-mail is
    not sent yet — see the [backlog](../development/todo.md))
  - *Send welcome message* — flag only, e-mail sending not implemented yet
  - *Update existing subscribers* — re-apply the subscription even if the
    contact is already in the group

## How Configuration Is Stored

The form serialises everything to JSON in `field_civicrm`. Membership types,
financial types and groups are stored **by name**, so a shared product
catalog can be imported into several sites whose CiviCRM databases assign
different IDs.

```json
{
  "enabled": true,
  "entity": "membership",
  "membership_type": "Plusz előfizetés"
}
```

```json
{
  "enabled": true,
  "entity": "mailing",
  "group": "newsletter",
  "mailing_preferences": {
    "double_opt_in": "double_opt_in"
  }
}
```

You do not need to edit this JSON directly — the product form handles it. The
full schema (including keys not exposed on the form) is documented in
[Product Field Schema](../development/product-field-schema.md).

## Configuration Examples

### Membership / subscription product

1. Edit the product
2. Enable CiviCRM processing
3. Set entity type to **Membership**
4. Select the membership type (e.g. "Plusz előfizetés")
5. Save

**Result**: on purchase, one contribution with a membership line item. If the
customer already has a New/Current/Grace membership of this type, it is
**renewed** rather than duplicated.

### Standalone product / donation

1. Edit the product
2. Enable CiviCRM processing
3. Set entity type to **Contribution**
4. Select the financial type (e.g. "Donation" or "Könyv")
5. Save

**Result**: on purchase, a contribution with the order amount, currency, and
`source` set to `Commerce Order #<id>`.

### Event ticket

1. Edit the product
2. Enable CiviCRM processing
3. Set entity type to **Event Registration**
4. Select the event and the participant role
5. Save

**Result**: on purchase, a `Registered` participant plus the linked
contribution line (financial type *Event Fee* unless configured otherwise).

### Mailing subscription

1. Edit the product
2. Enable CiviCRM processing
3. Set entity type to **Mailing List Subscription**
4. Select the mailing group; optionally set preferences
5. Save

**Result**: on purchase, the customer's contact is added to the group.

## Best Practices

- **Test each product** after configuration by placing a test order and
  checking the created CiviCRM records
- **Keep CiviCRM type names stable** — products reference membership types,
  financial types and groups by *name*; renaming them in CiviCRM breaks the
  reference (the order log will show an "unresolvable type" warning)
- **Bundle products** need site-specific handling: a bundle can be split into
  multiple CiviCRM records via the module's directive event — a developer
  task, see [Hooks & Events](../development/hooks-and-events.md)

## Troubleshooting

- **The CiviCRM Integration section is missing** — CiviCRM is unavailable, or
  the product type lacks the `field_civicrm` field
- **Dropdowns are empty** — CiviCRM may be unavailable or have no active
  entities of that type
- **No CiviCRM records created** — see
  [Order Processing](order-processing.md) and
  [Troubleshooting](troubleshooting.md)

## Next Steps

- [Order Processing](order-processing.md) — what happens when an order is placed
- [Product Field Schema](../development/product-field-schema.md) — the underlying JSON
