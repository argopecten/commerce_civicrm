# Product Configuration

## Overview

Each Commerce product can be configured to trigger CiviCRM operations when
purchased. Configuration is done through a "CiviCRM Integration" section on the
product edit form that stores settings as JSON in a single `field_civicrm` field.

The module supports four entity types:

| Entity Type | CiviCRM Record Created | Status |
|-------------|----------------------|--------|
| **Contribution** | `Contribution` with specified financial type | Fully working |
| **Membership** | `Membership` with specified type (optionally linked to a contribution) | Fully working |
| **Mailing** | `GroupContact` subscription to a mailing group | Fully working |
| **Event** | `Participant` registration | Form UI works; backend processing not yet implemented |

## Configuration UI

When editing a product, the **CiviCRM Integration** section appears in the
Advanced sidebar (provided by `ProductFormHelper`).

### Common Settings

All entity types share:

- **Enable CiviCRM processing** — checkbox to activate/deactivate integration
  for this product
- **CiviCRM entity type** — dropdown: Contribution, Membership, Event, or
  Mailing

### Contribution Settings

When entity type is set to **Contribution**:

- **Financial type** — dropdown populated from CiviCRM active financial types
  (sorted by label). Sets the `financial_type_id` on the created contribution.

### Membership Settings

When entity type is set to **Membership**:

- **Membership type** — dropdown populated from CiviCRM active membership types
  (sorted by label). Sets the `membership_type_id` for membership creation.

If a product has **both** a membership type and a financial type configured
(by also selecting the financial type), the module creates a **linked
membership + contribution** using the CiviCRM Order API in a single atomic
transaction.

### Event Settings

When entity type is set to **Event**:

- **Event** — dropdown populated from CiviCRM active events
- **Participant role** — dropdown populated from CiviCRM active participant roles

> **Note**: Event registration is configured in the UI but the backend
> processing (`Participant::create()`) is not yet implemented. Event products
> will be silently skipped during order processing. See
> [FMO #32](../fmo/32-advanced-civicrm-integration.md) §4 for details.

### Mailing Settings

When entity type is set to **Mailing**:

- **Mailing group** — dropdown populated from CiviCRM active mailing groups
- **Mailing preferences** — checkboxes:
  - Double opt-in (sets `GroupContact` status to `Pending` instead of `Added`)
  - Send welcome message (stub — logs only, not yet sending actual emails)
  - Update existing subscriptions

## How Configuration Is Stored

All settings are serialized as a JSON object in the `field_civicrm` field on the
product entity. The field is a `text_long` type, hidden from standard form and
view displays.

Example JSON for a membership product with linked contribution:

```json
{
  "enabled": true,
  "entity": "membership",
  "entity_id": 2,
  "financial_type_id": 2
}
```

Example for a mailing subscription:

```json
{
  "enabled": true,
  "entity": "mailing",
  "entity_id": 5,
  "mailing_preferences": {
    "double_opt_in": true,
    "send_welcome": true,
    "update_existing": false
  }
}
```

You do not need to edit this JSON directly — the product form UI handles
serialization automatically via `ProductFormHelper`.

## Configuration Examples

### Membership with Linked Contribution

Creates both a CiviCRM membership and a contribution in one Order API call:

1. Edit the product
2. Set entity type to **Membership**
3. Select the membership type (e.g., "General Member")
4. Also select a financial type (e.g., "Member Dues")
5. Enable CiviCRM processing
6. Save

**Result**: On purchase, creates a `Membership` + `Contribution` + `LineItem` +
`MembershipPayment` atomically. CiviCRM handles renewal detection, date
calculation, and status management.

### Standalone Donation

Creates only a CiviCRM contribution:

1. Edit the product
2. Set entity type to **Contribution**
3. Select financial type "Donation"
4. Enable CiviCRM processing
5. Save

**Result**: On purchase, creates a `Contribution` with the order amount, currency,
and `source` set to `Commerce Order #<id>`.

### Mailing Subscription

Adds the customer's CiviCRM contact to a mailing group:

1. Edit the product
2. Set entity type to **Mailing**
3. Select the mailing group
4. Optionally enable double opt-in and/or welcome message
5. Enable CiviCRM processing
6. Save

**Result**: On purchase, creates or updates a `GroupContact` record.

## Best Practices

- **Test each product** after configuration by placing a test order
- **Check logs** at `/admin/reports/dblog` (filter by `commerce_civicrm`) to
  verify records were created
- **Use meaningful labels** — the dropdowns show CiviCRM `label` values (not
  internal `name` values), so multi-language CiviCRM sites see translated labels
- **One entity type per product** — though the form supports only one entity type
  selection, a membership product with a financial type creates linked records
- **Verify CiviCRM IDs** — if you change membership types or financial types in
  CiviCRM, update or re-save affected products

## Troubleshooting

- **Dropdowns are empty** — CiviCRM may be unavailable or in maintenance mode.
  Check `/admin/reports/status`.
- **Settings don't save** — verify the `field_civicrm` field exists on the product
  type. Run `commerce_civicrm_add_field_to_product_type('your_bundle')` if needed.
- **No CiviCRM records created** — see [Order Processing](order-processing.md)
  and [Troubleshooting](troubleshooting.md).

## Next Steps

- [Order Processing](order-processing.md) — understand what happens when an order
  is placed
- [Services Overview](../services/overview.md) — technical service architecture
