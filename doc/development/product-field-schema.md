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

The field is hidden from default form and view displays. The module provides its own form widget via `hook_form_commerce_product_form_alter`.

---

## JSON schema

The field stores a JSON string. Canonical schema:

```jsonc
{
  // Master toggle — when false, the product is ignored by the CiviCRM processor.
  "enabled": true,               // boolean, required

  // CiviCRM entity type to create.
  "entity": "membership",        // enum: "contribution" | "membership" | "event" | "mailing"

  // Generic entity ID — interpreted based on "entity":
  //   membership   → CiviCRM membership_type_id
  //   contribution → CiviCRM financial_type_id
  //   event        → CiviCRM event ID
  //   mailing      → CiviCRM group ID
  "entity_id": 3,                // int | null

  // --- Normalised keys (populated by OrderCivicrmUpdater::getCivicrmProductSettings) ---
  "membership_type_id": 3,       // int | null — set when entity = "membership"
  "financial_type_id": null,     // int | null — set when entity = "contribution"

  // --- Event-specific (written by form, not yet consumed by any service) ---
  "participant_role_id": 1,      // int | null

  // --- Mailing-specific (written by form, not yet consumed by any service) ---
  "mailing_preferences": {       // object | array
    "double_opt_in": "double_opt_in",
    "send_welcome": "send_welcome",
    "update_existing": 0
  }
}
```

### Defaults applied by `OrderCivicrmUpdater::getCivicrmProductSettings()`

If a key is missing, these defaults are used:

```php
[
  'enabled'            => FALSE,
  'entity'             => 'contribution',
  'entity_id'          => NULL,
  'membership_type_id' => NULL,
  'financial_type_id'  => NULL,
]
```

### Backward-compatibility normalisation

For configs that only have `entity` + `entity_id` (without the explicit `membership_type_id` / `financial_type_id`), the orchestrator normalises:

| `entity` value | Effect |
|---|---|
| `membership` | `settings['membership_type_id'] = settings['entity_id']` |
| `contribution` | `settings['financial_type_id'] = settings['entity_id']` |

---

## Form → JSON serialisation

`commerce_civicrm_product_form_submit()` in `commerce_civicrm.module` reads form values and writes:

```php
$settings = [
  'enabled'   => (bool) $values['civicrm']['enabled'],
  'entity'    => $values['civicrm']['entity'],
  'entity_id' => $entity_id_based_on_entity_type,
];
// + participant_role_id for events
// + mailing_preferences for mailing
$product->set('field_civicrm', json_encode($settings));
```

---

## How the JSON is consumed

1. `OrderCivicrmUpdater::processOrderItem()` loads the product, calls `getCivicrmProductSettings()`.
2. Short-circuits if `$settings['enabled']` is falsy.
3. If `membership_type_id` is set → `MembershipUpdater::createMembershipFromOrder()`.
5. If both `membership_type_id` and `financial_type_id` are set → `OrderCivicrmUpdater::createLinkedMembershipContribution()` (atomic via `\Civi\Api4\Order::create`).
6. If `financial_type_id` is set → `ContributionUpdater::createContributionFromOrderWithFinancialType()`.
7. If entity type is `mailing` → `MailingUpdater::processMailingSubscriptionFromOrder()`.
8. `event` entity type is stored in the JSON but **no service processes it yet**.
