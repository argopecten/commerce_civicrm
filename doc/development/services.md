# Service Reference

All services are defined in `commerce_civicrm.services.yml` and injected via Drupal's service container.

---

## commerce_civicrm.civicrm_helper

**Class:** `Drupal\commerce_civicrm\Service\CivicrmHelper`  
**Dependencies:** `logger.factory`, `module_handler`, `?civicrm`

Low-level helper that bootstraps CiviCRM, verifies API availability, and detects maintenance mode.

### Public methods

| Method | Return | Description |
|---|---|---|
| `initialize()` | `bool` | Bootstraps CiviCRM via the injected `civicrm` service. Returns `FALSE` if the module/service is missing or CiviCRM is in maintenance mode. |
| `isAvailable()` | `bool` | Calls `initialize()`, then runs a trivial `Contact::get` query to prove the API works end-to-end. |
| `isReadyForOperations()` | `bool` | Stronger check than `isAvailable()` — intended for write operations. Returns `FALSE` during maintenance mode. |
| `getSystemInfo()` | `array` | Returns CiviCRM system info via `System::get`. Empty array on failure. |

### Notes

- Every other service calls `$this->civicrmHelper->initialize()` before any API call.
- `isAvailable()` is deliberately more expensive than `initialize()` — it is used only for gate-checks (e.g. in `OrderCivicrmUpdater::processOrder()` and `hook_runtime_requirements()`).
- Maintenance mode detection checks `CIVICRM_UPGRADE_ACTIVE` constant and `\Civi::settings()->get('environment')` for `'Maintenance'`.
- `initialize()` intentionally catches broad `\Exception` (not `\CRM_Core_Exception`) because CiviCRM initialization can throw various exception types.

---

## commerce_civicrm.contact_updater

**Class:** `Drupal\commerce_civicrm\Service\ContactUpdater`  
**Dependencies:** `entity_type.manager`, `logger.factory`, `commerce_civicrm.civicrm_helper`

Manages the CiviCRM Contact ↔ Drupal User relationship.

### Public methods

| Method | Signature | Return | Description |
|---|---|---|---|
| `updateContactFromOrder` | `(OrderInterface $order)` | `int\|null` | Extracts billing-profile data, finds-or-creates a CiviCRM contact, updates address and email. |
| `getContactIdByUser` | `(UserInterface $user)` | `int\|null` | Looks up `UFMatch` to resolve Drupal UID → CiviCRM contact ID. |
| `getFinancialTypes` | `()` | `array` | Returns `[id => label]` of active CiviCRM financial types, sorted by label. |
| `getEvents` | `()` | `array` | Returns `[id => title]` of active CiviCRM events. |
| `getParticipantRoles` | `()` | `array` | Returns `[id => label]` of active participant roles. |
| `getMailingGroups` | `()` | `array` | Returns `[id => title]` of active mailing groups. |

### Internal flow of `updateContactFromOrder`

1. Reads the order's billing profile (`address` field).
2. Maps Commerce address fields → CiviCRM fields (`given_name` → `first_name`, etc.).
3. Uses `address_primary.*` join paths for address fields (e.g. `address_primary.street_address`, `address_primary.state_province_id:abbr`).
4. Searches for existing contacts using `Contact::getDuplicates(FALSE)` with CiviCRM's `Individual.Supervised` dedupe rule.
5. If found → `Contact::update`; if not → `Contact::create`.
6. Primary email is handled separately via `Email::create` / `Email::update`.

### Contact-matching strategy

| Priority | Criterion |
|---|---|
| 1 | CiviCRM's built-in Supervised dedupe rule (`Contact::getDuplicates`) |
| 2 | Fallback: first name + last name + contact_type=Individual (with ambiguity detection) |
| 3 | Create new contact |

---

## commerce_civicrm.contribution_updater

**Class:** `Drupal\commerce_civicrm\Service\ContributionUpdater`  
**Dependencies:** `entity_type.manager`, `logger.factory`, `commerce_civicrm.civicrm_helper`

Creates and manages CiviCRM Contribution records.

### Public methods

| Method | Signature | Return | Description |
|---|---|---|---|
| `createContributionFromOrder` | `(OrderInterface $order, int $contact_id)` | `int\|null` | Creates a contribution using `Donation` financial type (or first active type). Checks for duplicates by `source` field. |
| `createContributionFromOrderWithFinancialType` | `(OrderInterface $order, int $contact_id, int $financial_type_id, string $contribution_status = 'Completed')` | `int\|null` | Creates a contribution with an explicit financial type. Used by `OrderCivicrmUpdater`. |
| `cancelContributionFromOrder` | `(OrderInterface $order, int $contact_id)` | `int\|null` | Finds the existing contribution by source string and sets its status to `Cancelled`. |

### Duplicate detection

Contributions are matched primarily by the `Commerce_Order.commerce_order_id` custom field (exact integer match). If no match is found, a fallback queries `source = 'Commerce Order #<order_id>'` (exact string match). When the fallback finds a legacy contribution, the custom field is automatically backfilled.

### Order-state → Contribution-status mapping

| Commerce state | CiviCRM status |
|---|---|
| `completed` | `Completed` |
| `canceled` | `Cancelled` |
| `draft`, `validation` | `Pending` |

### Payment instrument mapping

Gateway plugin IDs are mapped to CiviCRM payment instruments:

| Gateway | Instrument |
|---|---|
| `paypal` | PayPal |
| `stripe`, `square`, `authorize_net` | Credit Card |
| `manual` | Cash |
| *(other)* | Credit Card (default) |

---

## commerce_civicrm.membership_updater

**Class:** `Drupal\commerce_civicrm\Service\MembershipUpdater`  
**Dependencies:** `entity_type.manager`, `logger.factory`, `commerce_civicrm.civicrm_helper`

Full membership lifecycle: create, renew, cancel.

### Public methods

| Method | Signature | Return | Description |
|---|---|---|---|
| `createMembershipFromOrder` | `(int $contact_id, int $membership_type_id, OrderInterface $order, OrderItemInterface $order_item)` | `int\|null` | Creates a membership (or updates existing New/Current/Grace one). Calculates dates from `MembershipType` duration fields. |
| `createPendingMembershipFromOrder` | `(int $contact_id, int $membership_type_id, OrderInterface $order, OrderItemInterface $order_item)` | `int\|null` | Like above but with Pending/New status. |
| `renewMembership` | `(int $contact_id, int $membership_type_id, OrderInterface $order)` | `int\|null` | Extends `end_date` by one duration period from the current end date. |
| `cancelMembership` | `(int $contact_id, int $membership_type_id, OrderInterface $order)` | `bool` | Sets status to `Cancelled`. |
| `cancelMembershipFromOrder` | `(int $contact_id, int $membership_type_id, OrderInterface $order, OrderItemInterface $order_item)` | `int\|null` | Same as `cancelMembership` but returns the membership ID. |
| `getMembershipTypes` | `()` | `array` | Returns `[id => label]` of active membership types, sorted by label. |
| `getMembershipStatuses` | `()` | `array` | Returns `[id => label]` of active membership statuses. |

### Date calculation

- `calculateMembershipDates()` returns only `join_date` and `start_date` (using `\DateTimeImmutable`).
- CiviCRM calculates `end_date` natively based on membership type period settings (rolling vs. fixed, rollover days, grace periods).
- **Renewal:** extends the existing membership; CiviCRM recalculates `end_date`.

### Existing-membership lookup

A membership is considered "existing" if it matches `contact_id`, `membership_type_id`, and has status `New`, `Current`, or `Grace`.

### Order-state → Membership-status mapping

| Commerce state | CiviCRM status |
|---|---|
| `completed` | `Current` |
| `canceled` | `Cancelled` |
| `draft`, `pending`, *(other)* | `Pending` |

---

## commerce_civicrm.order_civicrm_updater

**Class:** `Drupal\commerce_civicrm\Service\OrderCivicrmUpdater`  
**Dependencies:** `logger.factory`, `commerce_civicrm.civicrm_helper`, `commerce_civicrm.contact_updater`, `commerce_civicrm.contribution_updater`, `commerce_civicrm.membership_updater`, `commerce_civicrm.mailing_updater`

Top-level orchestrator invoked by the event subscriber.

### Public methods

| Method | Signature | Return | Description |
|---|---|---|---|
| `processOrder` | `(OrderInterface $order)` | `array` | Iterates order items, reads product field config, delegates to updater services. Returns `['memberships' => [...], 'contributions' => [...]]`. |
| `processCancellation` | `(OrderInterface $order)` | `array` | Cancels memberships and contributions for the order. Returns structured results with per-item success/failure tracking. |

### Product settings normalisation

`getCivicrmProductSettings()` reads `field_civicrm` JSON and applies defaults:

```php
[
  'enabled'            => FALSE,
  'entity'             => 'contribution',
  'entity_id'          => NULL,
  'membership_type_id' => NULL,
  'financial_type_id'  => NULL,
]
```

For backward compatibility, `entity` + `entity_id` are normalised into `membership_type_id` / `financial_type_id` based on `entity` value.

---

## commerce_civicrm.mailing_updater

**Class:** `Drupal\commerce_civicrm\Service\MailingUpdater`  
**Dependencies:** `logger.factory`, `commerce_civicrm.civicrm_helper`

Manages CiviCRM mailing group subscriptions via `GroupContact` API4.

### Public methods

| Method | Signature | Return | Description |
|---|---|---|---|
| `addContactToMailingGroup` | `(int $contact_id, int $group_id, array $preferences = [])` | `bool` | Adds a contact to a mailing group. Supports double opt-in (status `Pending`) and update-existing semantics. |
| `removeContactFromMailingGroup` | `(int $contact_id, int $group_id)` | `bool` | Removes a contact from a mailing group by setting status to `Removed`. |
| `processMailingSubscriptionFromOrder` | `(int $contact_id, OrderInterface $order, OrderItemInterface $order_item, array $settings)` | `bool` | Processes a mailing subscription based on product field settings. |
| `checkGroupMembership` | `(int $contact_id, int $group_id)` | `?array` | Returns existing group membership record or NULL. |

### Notes

- `sendDoubleOptInEmail()` and `sendWelcomeMessage()` are stubs with `@todo` — they log info but do not send emails.
- Uses 4 OOP API4 calls (`GroupContact::create`, `::update`, `::get`).

---

## commerce_civicrm.product_form_helper

**Class:** `Drupal\commerce_civicrm\Service\ProductFormHelper`  
**Dependencies:** `logger.factory`, `commerce_civicrm.civicrm_helper`, `commerce_civicrm.membership_updater`, `commerce_civicrm.contact_updater`

Manages the CiviCRM integration form on Commerce product edit pages.

### Public methods

| Method | Signature | Return | Description |
|---|---|---|---|
| `alterProductForm` | `(array &$form, FormStateInterface $form_state, string $form_id)` | `void` | Adds the CiviCRM Integration details group to the product form. |
| `submitProductForm` | `(array &$form, FormStateInterface $form_state)` | `void` | Serialises CiviCRM form values to JSON and stores in `field_civicrm`. |
| `getProductSettings` | `(ProductInterface $product)` | `array` | Reads and decodes the `field_civicrm` JSON with defaults. |

### Notes

- Uses `StringTranslationTrait` for proper `$this->t()` translation.
- Option lists (membership types, financial types, events, participant roles, mailing groups) are loaded live from CiviCRM via the injected updater services.
- The `.module` file contains thin hook stubs that delegate to this service.
