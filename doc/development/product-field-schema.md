# `field_civicrm` JSON Schema & Product Configuration

## Field storage

| Property | Value |
|---|---|
| Field name | `field_civicrm` |
| Entity type | `commerce_product` |
| Field type | `text_long` |
| Cardinality | 1 |
| Installed by | `commerce_civicrm_install()` / `commerce_civicrm_add_field_to_product_type()` |
| Auto-added to new types | Yes — via `hook_commerce_product_type_insert` |

The field is hidden from default form and view displays. The module provides
its own form widget via `hook_form_commerce_product_form_alter`.

---

## JSON schema

The field stores a JSON string. Type references are stored **by name** (not
numeric ID) wherever CiviCRM has stable names — membership types, financial
types and groups — so a shared product catalog can be imported into several
sites whose CiviCRM databases assign different IDs. CiviCRM *events* are the
exception: they are per-site, ad-hoc records and are referenced by ID.

One JSON object per product, keyed by `entity`:

```jsonc
// Membership product (subscription): Membership + linked Contribution.
{
  "enabled": true,
  "entity": "membership",
  "membership_type": "Plusz előfizetés",   // MembershipType name (or ID)
  "financial_type": "Plusz előfizetés"     // optional — defaults to the
                                           // membership type's financial type
}

// Plain contribution product (book, donation, merchandise).
{
  "enabled": true,
  "entity": "contribution",
  "financial_type": "Könyv"                // FinancialType name (or ID)
}

// Event ticket product: Participant + Event Fee contribution.
{
  "enabled": true,
  "entity": "event",
  "event_id": 3,                           // CiviCRM event ID (per-site!)
  "participant_role_id": 1,                // participant_role option value
  "financial_type": "Event Fee"            // optional — defaults to Event Fee
}

// Mailing list opt-in product.
{
  "enabled": true,
  "entity": "mailing",
  "group": "newsletter",                   // Group name or title (or ID)
  "mailing_preferences": {
    "double_opt_in": "double_opt_in",      // optional flags
    "send_welcome": "send_welcome",
    "update_existing": "update_existing"
  }
}

// Bundle product whose components are expanded by a site-specific
// OrderItemDirectivesEvent subscriber: only the toggle is set here.
{
  "enabled": true,
  "entity": "membership"
}
```

`{"enabled": false}` or an empty field disables processing for the product.

### Defaults applied by `OrderCivicrmUpdater::getCivicrmProductSettings()`

```php
[
  'enabled'             => FALSE,
  'entity'              => 'contribution',
  'membership_type'     => NULL,
  'financial_type'      => NULL,
  'event_id'            => NULL,
  'participant_role_id' => NULL,
  'group'               => NULL,
  'mailing_preferences' => [],
]
```

Keys outside this schema are preserved in the decoded array but ignored by
the module — subscribers may use them as private extension data.

---

## Form → JSON serialisation

`ProductFormHelper::submitProductForm()` writes `enabled` + `entity` plus the
entity-specific keys above. The membership-type and financial-type selects
are keyed by CiviCRM *name*, the mailing-group select by group *name*, the
event and participant-role selects by numeric ID/value.

---

## How the JSON is consumed

1. `OrderCivicrmUpdater::buildOrderDirectives()` walks the order items,
   parses each product's JSON and builds one default *directive* per
   CiviCRM-enabled item.
2. `OrderItemDirectivesEvent` lets site code replace or expand the
   directives (bundle splitting, per-variation overrides).
3. All financial directives of the order become **one** contribution created
   via `\Civi\Api4\Order::create` with one line item per directive:
   `civicrm_membership` line items (renewal-aware), `civicrm_participant`
   line items for events, and plain lines for contribution directives.
4. Mailing directives become `GroupContact` records outside the
   contribution.

Name references resolve through `CivicrmHelper::resolveMembershipTypeId()` /
`resolveFinancialTypeId()` / `resolveGroupId()` with per-request caching.
