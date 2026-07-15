# Service Reference

All services are defined in `commerce_civicrm.services.yml` and wired via
Drupal's service container. There are 10 services: one event subscriber, two
pipeline entry points (`order_civicrm_updater`, `renewal_processor`), six
support services and the product form helper.

---

## commerce_civicrm.order_complete_subscriber

**Class:** `Drupal\commerce_civicrm\EventSubscriber\OrderCompleteSubscriber`
**Dependencies:** `logger.factory`, `commerce_civicrm.order_civicrm_updater`, `config.factory`

Routes Commerce order workflow transitions to CiviCRM processing. Subscribes
to the group-level `commerce_order.post_transition` event (priority `-50`),
which state_machine dispatches for every transition of every order workflow.

| Method | Description |
|---|---|
| `onTransition(WorkflowTransitionEvent $event): void` | Checks the optional `order.workflows` allowlist, then matches the transition ID against `order.create_transitions` (→ `processOrder()`) and `order.cancel_transitions` (→ `processCancellation()`). Passes transition context (`transition`, `from_state`, `to_state`, `workflow`). |

Because processing is idempotent per order, it is safe to configure several
create transitions (e.g. `[paid, completed]`) to cover workflows that chain
transitions inside a single order save — state_machine only dispatches an
event for the **last** transition applied in a save.

---

## commerce_civicrm.order_civicrm_updater

**Class:** `Drupal\commerce_civicrm\Service\OrderCivicrmUpdater`
**Dependencies:** `logger.factory`, `civicrm_helper`, `contact_updater`,
`contribution_updater`, `membership_updater`, `mailing_updater`,
`participant_updater`, `event_dispatcher`, `config.factory`, `datetime.time`

The orchestrator. Resolves order items into *directives* and creates CiviCRM
records through the Order API — **one contribution per Commerce order**, with
one line item per financial directive.

### Public methods

| Method | Return | Description |
|---|---|---|
| `processOrder(OrderInterface $order, array $context = [])` | `array` | Full create pipeline: readiness guard → contact resolution → custom-field check → idempotency check → directives → `createCiviOrder()` + mailing subscriptions → `OrderProcessedEvent`. Returns created (or already existing) record IDs keyed by type (`contributions`, `memberships`, `participants`, `mailings`). |
| `processCancellation(OrderInterface $order, array $context = [])` | `array` | Cancels the memberships/participants referenced by the line items of every contribution linked to the order, cancels the contributions, removes mailing-group memberships. Dispatches `ORDER_CANCELLED`. Returns cancelled IDs keyed by type. |
| `resolveContactId(OrderInterface $order)` | `?int` | UFMatch of the order's customer; with `contact.fallback: match_or_create` falls back to `ContactUpdater::matchOrCreateContactFromOrder()`. |
| `buildOrderDirectives(OrderInterface $order, int $contact_id, array $context = [])` | `array` | Default directive per CiviCRM-enabled order item, then `OrderItemDirectivesEvent` per item. |
| `createCiviOrder(OrderInterface $order, int $contact_id, array $directives, ?PaymentInterface $payment, array $context = [], ?string $total_override = NULL)` | `array` | Builds line items, dispatches `ContributionParamsEvent`, calls `Order::create` (contribution starts Pending) and — when the order is paid — `Payment::create` to complete it. `$total_override` proportionally rescales directive amounts (renewals). Re-asserts dispatched membership dates. |
| `getContributionLineEntities(int $contribution_id)` | `array` | `['entity_table' => …, 'entity_id' => …]` rows of a contribution's line items (excluding plain contribution lines). |
| `getCivicrmProductSettings(ProductInterface $product)` | `array` | Decodes the product's `field_civicrm` JSON with defaults applied. |

### Protected pipeline steps

`buildDefaultDirectives()` (order item → 0..1 directive),
`partitionDirectives()` (financial vs. mailing), `buildLineItem()`
(directive → Order API line item), `resolveMembershipDates()` (date mode /
`MembershipDatesEvent`), `reassertMembershipDates()` (after payment
completion), `scaleDirectiveAmounts()` (renewal totals), `isOrderPaid()`.

---

## commerce_civicrm.renewal_processor

**Class:** `Drupal\commerce_civicrm\Service\RenewalProcessor`
**Dependencies:** `logger.factory`, `civicrm_helper`, `order_civicrm_updater`,
`contribution_updater`, `event_dispatcher`

Records additional (renewal) payments for already-processed orders. Recurring
charges typically happen on the same Commerce order without a workflow
transition, so **site code must call this** (e.g. from a payment-insert
subscriber).

| Method | Return | Description |
|---|---|---|
| `recordRenewalPayment(OrderInterface $order, PaymentInterface $payment)` | `array` | `['contribution' => ?int, 'memberships' => int[], 'skipped' => bool]`. Guards: payment `completed`, an initial contribution exists, no contribution references this payment yet. Creates a new contribution via `createCiviOrder()` with the payment amount as total override; membership line items reference (and thus extend) the existing memberships. Dispatches `RenewalRecordedEvent`. |

---

## commerce_civicrm.civicrm_helper

**Class:** `Drupal\commerce_civicrm\Service\CivicrmHelper`
**Dependencies:** `logger.factory`, `module_handler`, `?civicrm` (optional)

Low-level helper: CiviCRM bootstrap, availability and maintenance-mode
detection, `Commerce_Order` custom-field provisioning, and cached name→ID
lookups.

| Method | Return | Description |
|---|---|---|
| `initialize()` | `bool` | Bootstraps CiviCRM via the injected `civicrm` service. `FALSE` if the module/service is missing, bootstrap throws, or CiviCRM is in maintenance mode. |
| `isAvailable()` | `bool` | `initialize()` + a trivial `Contact::get` to prove the API works end-to-end. Used for gate checks (product form, status report). |
| `isReadyForOperations()` | `bool` | `initialize()` + explicit maintenance-mode rejection. Used before write pipelines. |
| `ensureCommerceOrderCustomFields()` | `bool` | Creates the `Commerce_Order` custom group (extends Contribution) and its `commerce_order_id` / `commerce_payment_id` fields if missing. |
| `resolveMembershipTypeId(string\|int\|null $ref)` | `?int` | Name or numeric ID → ID. |
| `resolveFinancialTypeId(string\|int\|null $ref)` | `?int` | Name or numeric ID → ID. |
| `resolveGroupId(string\|int\|null $ref)` | `?int` | Group name, **title** or numeric ID → ID. |
| `resolveEntityId(string $entity, string\|int\|null $ref)` | `?int` | Generic API4 name→ID resolution with per-request caching. |
| `getOptionValue(string $option_group, string $name)` | `?int` | Option value lookup (e.g. `payment_instrument`), per-request cached. |
| `getSystemInfo()` | `array` | `System::get` info; empty array on failure. |

Maintenance-mode detection checks the `CIVICRM_UPGRADE_ACTIVE` constant and
`\Civi::settings()->get('environment') === 'Maintenance'`. `initialize()`
intentionally catches broad `\Exception` because CiviCRM bootstrap can throw
various exception types.

---

## commerce_civicrm.contact_updater

**Class:** `Drupal\commerce_civicrm\Service\ContactUpdater`
**Dependencies:** `entity_type.manager`, `logger.factory`, `civicrm_helper`

Contact resolution and the CiviCRM option lists used by the product form.

| Method | Return | Description |
|---|---|---|
| `getContactIdByUser(UserInterface $user)` | `?int` | `UFMatch` lookup: Drupal UID → CiviCRM contact ID. |
| `matchOrCreateContactFromOrder(OrderInterface $order)` | `?int` | Fallback used when `contact.fallback: match_or_create`: extracts name/address from the billing profile and email from the customer, then matches by email → `Individual.Supervised` dedupe rule → strict name(+email) lookup, updating the matched contact or creating a new Individual. |
| `getFinancialTypes()` | `array` | `[name => label]` of active financial types (label-sorted). |
| `getEvents()` | `array` | `[id => title]` of active events (start-date descending). |
| `getParticipantRoles()` | `array` | `[value => label]` of active participant roles. |
| `getMailingGroups()` | `array` | `[name => title]` of active groups of type *Mailing List*. |

Matching notes: address fields use `address_primary.*` join paths
(`state_province_id:abbr`, `country_id:name`); ambiguous dedupe/name matches
(> 1 hit) return NULL with a warning instead of guessing; primary email is
maintained separately via the `Email` entity.

---

## commerce_civicrm.contribution_updater

**Class:** `Drupal\commerce_civicrm\Service\ContributionUpdater`
**Dependencies:** `entity_type.manager`, `logger.factory`, `civicrm_helper`, `config.factory`

Contribution creation goes through the Order API in `OrderCivicrmUpdater`;
this service provides the supporting pieces: order/payment-scoped lookups
(idempotency), payment instrument mapping, and cancellation.

| Method | Return | Description |
|---|---|---|
| `findExistingContributionForOrder(OrderInterface $order)` | `?int` | Primary lookup by `Commerce_Order.commerce_order_id`; falls back to exact `source = 'Commerce Order #<id>'` and backfills the custom field on hit. |
| `findAllContributionsForOrder(OrderInterface $order)` | `int[]` | All contributions linked to the order (initial + renewals), oldest first. |
| `findContributionForPayment(PaymentInterface $payment)` | `?int` | By `Commerce_Order.commerce_payment_id`, falling back to `trxn_id` = payment remote ID. |
| `getLatestCompletedPayment(OrderInterface $order)` | `?PaymentInterface` | Most recent completed Commerce payment, or NULL (e.g. manually completed bank-transfer orders). |
| `getPaymentInstrumentId(PaymentInterface $payment)` | `?int` | `contribution.payment_instrument_map` consulted with the gateway config entity ID first, then the gateway plugin ID; unmatched gateways use `payment_instrument_default`. Resolved to the option value via `CivicrmHelper::getOptionValue()`. |
| `cancelContributionsFromOrder(OrderInterface $order)` | `int[]` | Sets every linked contribution's status to `Cancelled`. |

---

## commerce_civicrm.membership_updater

**Class:** `Drupal\commerce_civicrm\Service\MembershipUpdater`
**Dependencies:** `entity_type.manager`, `logger.factory`, `civicrm_helper`

Membership creation and renewal go through Order API line items built by
`OrderCivicrmUpdater`; this service provides the supporting lookups and
cancellation.

| Method | Return | Description |
|---|---|---|
| `findExistingMembership(int $contact_id, int $membership_type_id)` | `?array` | Renewable membership: same contact + type, status in `[New, Current, Grace]`. |
| `getMembershipTypeDetails(int $membership_type_id)` | `?array` | `id`, `name`, `financial_type_id`, `duration_unit`, `duration_interval`, `period_type`. |
| `cancelMembershipById(int $membership_id, OrderInterface $order)` | `bool` | Status `Cancelled` + `is_override` + source note. |
| `cancelMembershipFromOrder(int $contact_id, int $membership_type_id, OrderInterface $order)` | `?int` | Cancels the contact's active membership of a type when no line-item link is available (public API; not used by the pipeline itself). |
| `getMembershipTypes()` | `array` | `[name => label]` of active membership types (label-sorted) for form options — keyed by name so product config stays portable. |

---

## commerce_civicrm.participant_updater

**Class:** `Drupal\commerce_civicrm\Service\ParticipantUpdater`
**Dependencies:** `logger.factory`, `civicrm_helper`

Participant creation happens through Order API participant line items; this
service covers the operations outside that call.

| Method | Return | Description |
|---|---|---|
| `cancelParticipant(int $participant_id, OrderInterface $order)` | `bool` | Status `Cancelled` + source note. |

---

## commerce_civicrm.mailing_updater

**Class:** `Drupal\commerce_civicrm\Service\MailingUpdater`
**Dependencies:** `entity_type.manager`, `logger.factory`, `civicrm_helper`

Mailing group subscriptions via the `GroupContact` API.

| Method | Return | Description |
|---|---|---|
| `addContactToMailingGroup(int $contact_id, int $group_id, array $preferences = [])` | `bool` | Creates/updates the GroupContact. `double_opt_in` → status `Pending` (else `Added`); `update_existing` allows touching an existing non-removed subscription; optional `source`. |
| `removeContactFromMailingGroup(int $contact_id, int $group_id)` | `bool` | Sets status `Removed` (no-op if absent or already removed). |
| `processMailingSubscriptionFromOrder(int $contact_id, int $group_id, OrderInterface $order, array $preferences = [])` | `bool` | `addContactToMailingGroup()` with `source` defaulting to `Commerce Order #<id>`. |
| `checkGroupMembership(int $contact_id, int $group_id)` | `?array` | Existing GroupContact record (`id`, `status`) or NULL. |

`sendDoubleOptInEmail()` and `sendWelcomeMessage()` are `@todo` stubs — they
log but do not send anything yet.

---

## commerce_civicrm.product_form_helper

**Class:** `Drupal\commerce_civicrm\Service\ProductFormHelper`
**Dependencies:** `logger.factory`, `civicrm_helper`, `membership_updater`, `contact_updater`

The *CiviCRM Integration* section on Commerce product edit forms.

| Method | Return | Description |
|---|---|---|
| `alterProductForm(array &$form, FormStateInterface $form_state, string $form_id)` | `void` | Adds the details group (enable checkbox, entity type select, and per-entity selects with `#states` visibility). Skipped when the product has no `field_civicrm` or CiviCRM is unavailable. |
| `submitProductForm(array &$form, FormStateInterface $form_state)` | `void` | Serialises the form values to JSON in `field_civicrm`. Membership/financial types and groups are stored **by name**; events and participant roles by numeric ID/value. |
| `getProductSettings(ProductInterface $product)` | `array` | Decodes the JSON with defaults (same shape as `OrderCivicrmUpdater::getCivicrmProductSettings()`). |

Option lists are loaded live from CiviCRM via `MembershipUpdater` and
`ContactUpdater`.
