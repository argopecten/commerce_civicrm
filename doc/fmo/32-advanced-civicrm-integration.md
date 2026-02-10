# FMO #32 — Advanced CiviCRM Integration Features

**Todo item**: `### 32. Advanced CiviCRM integration features`
**Priority**: Future
**Date**: 2025-05-22

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Contribution Tracking](#2-contribution-tracking)
3. [Membership Lifecycle](#3-membership-lifecycle)
4. [Event Registration](#4-event-registration)
5. [Mailing / Group Subscriptions](#5-mailing--group-subscriptions)
6. [Cross-Cutting Architectural Issues](#6-cross-cutting-architectural-issues)
7. [Priority Recommendations](#7-priority-recommendations)

---

## 1. Executive Summary

Todo #32 covers three high-level areas — contribution tracking, membership
lifecycle, and event registration — plus the mailing integration added by
prompt #13. This document audits every method in the codebase against those
use cases and catalogues what is **Implemented**, **Partially Implemented**,
and **Missing**.

### Severity Legend

| Icon | Meaning |
|------|---------|
| ✅ | Fully implemented and tested |
| ⚠️ | Partially implemented or has known gaps |
| ❌ | Not implemented / dead code / critical gap |

### Audit Scope

| Service | Lines | Methods | CiviCRM API4 Calls |
|---------|------:|--------:|--------------------:|
| `ContributionUpdater.php` | ~639 | 13 | 13 |
| `OrderCivicrmUpdater.php` | ~811 | 10 | 9 |
| `MembershipUpdater.php` | ~756 | 15+ | 13 |
| `MailingUpdater.php` | ~303 | 6 | 4 |
| `ContactUpdater.php` | — | 13+ | 13 |
| `ProductFormHelper.php` | ~328 | — | 0 (delegates) |
| `commerce_civicrm.module` | ~195 | — | 0 (delegates) |

---

## 2. Contribution Tracking

### 2.1 What's Implemented

#### Three contribution-creation paths

| # | Method | Location | Used By |
|---|--------|----------|---------|
| 1 | `ContributionUpdater::createContributionFromOrder()` | `ContributionUpdater.php` | ⚠️ No caller found in current codebase — possibly dead code |
| 2 | `ContributionUpdater::createContributionFromOrderWithFinancialType()` | `ContributionUpdater.php` | Called by `OrderCivicrmUpdater::processOrderItem()` for contribution-only products |
| 3 | `OrderCivicrmUpdater::createLinkedMembershipContribution()` | `OrderCivicrmUpdater.php` | Called by `processOrderItem()` for membership+contribution products (uses Order API) |

**Currency handling** ✅ — All three paths read `$order->getTotalPrice()->getCurrencyCode()`.

**Source tracking** ✅ — All contributions are tagged `'Commerce Order #' . $order->id()`.

**Custom field** ✅ — `Commerce_Order.commerce_order_id` is auto-provisioned on the
`Contribution` entity. Used for de-duplication and cross-referencing.

**Duplicate detection** ✅ — Primary lookup by custom field `Commerce_Order.commerce_order_id`,
fallback by `source` string matching, with automatic backfill of the custom field on
fallback match.

**Status management** ✅ — Maps Commerce order states:
- `completed` → Completed
- `canceled` → Cancelled
- `draft` / `pending` / default → Pending

**Financial type assignment** ✅ — Product-level configuration via `field_civicrm` JSON.
Fallback to `'Donation'` when not configured.

**Payment instrument mapping** ⚠️ — `getPaymentInstrumentId()` maps gateway plugin IDs
(paypal → PayPal, stripe → Credit Card, etc.), but is **only used by Path 1** — which
has no callers. Paths 2 and 3 do **not** set `payment_instrument_id` or `trxn_id`.

**Cancellation** ✅ — `ContributionUpdater::cancelContributionFromOrder()` sets status
to Cancelled. Guarded by `$contribution_cancelled` flag so it runs only once per order.

### 2.2 What's Missing

#### 2.2.1 Line Item Details ❌

**Use case**: A customer buys three different products in one order. CiviCRM best
practice is to create **one** contribution with multiple line items, not three separate
contributions.

**Current behaviour**: `processOrderItem()` is called per order item. Each call creates
its own contribution. There is no aggregation into a single contribution with multiple
`LineItem` records.

**Impact**: Financial reports in CiviCRM show inflated contribution counts. Contribution
summaries don't match Commerce order totals when orders have mixed product types.

**What's needed**:
- Refactor `processOrderItem()` to collect all order items first, then create one
  `Order::create()` call with multiple `addLineItem()` calls.
- Handle mixed entity types (contribution-only + membership + event) within a single
  Order API call — the CiviCRM Order API supports this.
- Decide how to handle failures in partial line items.

#### 2.2.2 Tax Handling ❌

**Use case**: Commerce stores typically charge tax. CiviCRM supports `tax_amount` and
`non_deductible_amount` on contributions. Tax-exempt donations vs taxable merchandise
need different treatment.

**Current behaviour**: `non_deductible_amount` is hardcoded to `0`. No `tax_amount`
field is set. Commerce tax adjustments (`$order_item->getAdjustments(['tax'])`) are
never read.

**What's needed**:
- Read Commerce tax adjustments via `$order_item->getAdjustments(['tax'])`.
- Map to CiviCRM `tax_amount` on the contribution and `tax_amount` on line items.
- Handle `non_deductible_amount` based on financial type configuration.
- Consider CiviCRM's tax settings (`invoicing` component, tax rates, financial accounts).

#### 2.2.3 Refund Processing ❌

**Use case**: Order is partially or fully refunded. CiviCRM should record the refund
as a negative payment against the contribution.

**Current behaviour**: Only cancellation is supported (sets contribution status to
Cancelled). No `Payment::create()` with negative amounts. No partial refund support.

**What's needed**:
- Listen for Commerce refund events (e.g., `commerce_order.place.post_transition`
  to `refunded` state).
- Call `\Civi\Api4\Payment::create()` with negative `total_amount` and
  `contribution_id`.
- Support partial refunds (don't cancel contribution, just add negative payment).
- Update contribution status to `Refunded` or `Partially paid` accordingly.

#### 2.2.4 Recurring Contributions ❌

**Use case**: Subscription products (magazines, recurring donations) should create
CiviCRM `ContributionRecur` records linked to a payment processor.

**Current behaviour**: No `ContributionRecur` API calls. No `contribution_recur_id`
set on contributions. No payment processor token handling.

**What's needed**:
- Integration with a Commerce recurring module (e.g., `commerce_recurring`).
- Create `ContributionRecur::create()` with `frequency_unit`, `frequency_interval`,
  `amount`, `payment_processor_id`, `payment_token_id`.
- Link individual contributions via `contribution_recur_id`.
- Handle recurring payment successes and failures.

#### 2.2.5 Partial Payments ❌

**Use case**: Layaway or instalment plans where the customer pays over time.

**Current behaviour**: Always records full `total_amount`. No `Partially paid` status.
No `Payment::create()` for individual instalments.

**What's needed**:
- Use `Payment::create()` for each instalment.
- Set contribution status to `Partially paid` until fully paid.
- Track balance via CiviCRM's native `GET Contribution.balance_amount`.

#### 2.2.6 Soft Credits ❌

**Use case**: Peer-to-peer fundraising where one contact pays on behalf of another.

**Current behaviour**: Not implemented.

**What's needed**:
- `ContributionSoft::create()` linking the contribution to a soft-credited contact.
- Product or order-level configuration to specify honoree/beneficiary.

#### 2.2.7 Payment Data Inconsistency ⚠️

**Issue**: Path 1 captures `payment_instrument_id` and `trxn_id` via `getPaymentInfo()`.
Paths 2 and 3 do **not** — meaning the actively-used code paths produce contributions
without payment instrument or transaction ID.

**What's needed**:
- Move `getPaymentInfo()` / `getPaymentInstrumentId()` logic to a shared location.
- Call it from all contribution creation paths.
- Store `trxn_id` from Commerce payment gateway on every contribution.

#### 2.2.8 Dead Code (Path 1) ⚠️

`ContributionUpdater::createContributionFromOrder()` appears to have **no callers**
in the current codebase. It should be either:
- Removed if it was superseded by Path 2.
- Documented as a public API for custom integrations.
- Integrated as the fallback path when no explicit financial type is configured.

---

## 3. Membership Lifecycle

### 3.1 What's Implemented

**New membership creation** ✅ — `MembershipUpdater::createMembershipFromOrder()`
creates a `Membership` via API4 with `contact_id`, `membership_type_id`, `join_date`,
`start_date`, `source`, and `status_id` (New).

**Pending memberships** ✅ — `createPendingMembershipFromOrder()` creates memberships
with Pending status for orders not yet completed.

**Renewal detection** ✅ — `findExistingMembership()` looks for existing memberships
of the same type for the same contact in states `[New, Current, Grace]`. If found, the
purchase is treated as a renewal.

**Renewal processing** ✅ — `renewMembership()` updates status to Current, appends
"(Renewal)" to source. Does **not** touch dates, deferring end_date calculation to
CiviCRM.

**Linked membership+contribution** ✅ — `OrderCivicrmUpdater::createLinkedMembershipContribution()`
uses the CiviCRM **Order API** (`Order::create()`) to atomically create a contribution
and membership in a single transaction.

**Status management** ✅ — Maps Commerce order states: `completed` → Current,
`canceled` → Cancelled, `draft`/`pending` → Pending.

**Cancellation** ✅ — `cancelMembership()` and `cancelMembershipFromOrder()` set
status to Cancelled.

**Date handling** ✅ — `calculateMembershipDates()` sets `join_date` and `start_date`
to today. `end_date` is **intentionally omitted** so CiviCRM calculates it from the
membership type's `duration_unit`, `duration_interval`, and `period_type`.

**Membership type listing** ✅ — `getMembershipTypes()` fetches active types for
product configuration dropdowns.

### 3.2 What's Partially Implemented

#### 3.2.1 Grace Period Handling ⚠️

`findExistingMembership()` includes `'Grace'` in the status filter, so contacts in the
grace period will have their membership found and renewed. However, grace period
transitions are fully delegated to CiviCRM's scheduled job
(`Job.process_membership`). The module does not independently manage grace period
timing or notifications.

#### 3.2.2 Custom Field Mapping ⚠️

`addCustomFieldsToMembership()` exists but only sets `source`. The method contains a
comment: *"This could be extended to map specific order or product fields to CiviCRM
custom fields."* No actual custom field mapping is implemented.

### 3.3 What's Missing

#### 3.3.1 Auto-Renewal / Recurring Memberships ❌

**Use case**: Annual memberships that auto-renew via a saved payment method. The
membership stays active as long as the recurring payment succeeds.

**Current behaviour**: No `ContributionRecur` creation, no payment processor token
handling, no integration with Commerce subscription modules.

**What's needed**:
- Create `ContributionRecur::create()` linked to the membership.
- Handle `Membership.contribution_recur_id` linkage.
- Process recurring payment notifications (success → renew, failure → grace/cancel).
- Integrate with a Commerce recurring payment module.

#### 3.3.2 Upgrade/Downgrade Workflows ❌

**Use case**: A member with a "Basic" membership purchases a "Premium" membership.
The old membership should be cancelled or superseded, and the new one should start
immediately (possibly prorated).

**Current behaviour**: `findExistingMembership()` filters by `membership_type_id`,
so it only finds memberships of the **same type**. Purchasing a different membership
type creates a second, independent membership.

**What's needed**:
- Detect when a contact already has a membership of a **different** type.
- Implement upgrade/downgrade logic: cancel old, create new with prorated dates.
- Or use CiviCRM's `MembershipType.relationship_type_id` hierarchy.
- Product configuration to mark membership types as "upgradeable from" list.

#### 3.3.3 Family / Organizational Memberships ❌

**Use case**: One purchase grants membership to an entire household or organization.
CiviCRM supports this via `owner_membership_id` (inherited memberships).

**Current behaviour**: Only individual memberships. No `owner_membership_id` handling.
No related contact membership creation.

**What's needed**:
- After creating the primary membership, create inherited memberships for related
  contacts using `owner_membership_id`.
- Provide a way to specify family members during checkout (custom checkout pane).
- Support CiviCRM's membership type `relationship_type_id` for automatic inheritance.

#### 3.3.4 Benefit Tracking ❌

**Use case**: Memberships grant benefits (discount codes, access to content, etc.)
that should be provisioned/revoked when membership status changes.

**Current behaviour**: Not implemented. The module creates the CiviCRM membership but
does not trigger any Drupal-side benefits.

**What's needed**:
- Event/hook when membership is created/renewed/cancelled.
- Integration with Drupal roles or permissions based on CiviCRM membership status.
- Benefit provisioning (e.g., create discount code, grant content access).

#### 3.3.5 Multi-Quantity Memberships ❌

**Use case**: An organization buys 10 memberships for its employees in a single order
item (qty=10).

**Current behaviour**: The order item quantity is passed to the Order API line item,
but only one membership is created regardless of quantity.

**What's needed**:
- Loop over quantity and create individual memberships for each unit.
- Or provide a mechanism to assign each membership to a different contact.

#### 3.3.6 Renewal Date Extension ⚠️

**Use case**: A member renews 30 days before their membership expires. The new end
date should extend from the **current end date**, not from today.

**Current behaviour**: `calculateMembershipDates()` always sets `start_date` to today.
CiviCRM recalculates `end_date` based on the membership type definition, but the
behaviour depends on the membership type's `period_type`:
- `rolling` → extends from today.
- `fixed` → extends to next fixed period end.

This may produce unexpected results for early renewals of rolling memberships.

**What's needed**:
- For renewals, detect the current `end_date` and, if it's in the future, pass it as
  `start_date` so CiviCRM extends from the existing end.
- Or omit `start_date` on renewals and let CiviCRM handle it entirely.

#### 3.3.7 Duplicated Renewal Detection Code ⚠️

`MembershipUpdater::findExistingMembership()` and
`OrderCivicrmUpdater::findExistingMembershipForRenewal()` have identical logic (same
filters: `contact_id`, `membership_type_id`, `status_id:name IN ['New', 'Current', 'Grace']`).
One should delegate to the other.

---

## 4. Event Registration

### 4.1 What's Implemented

#### Product Configuration UI ⚠️ (form only, no backend)

`ProductFormHelper` provides a product configuration form with:
- **Entity type** dropdown including `'event'` as an option.
- **Event select** dropdown — `ContactUpdater::getEvents()` fetches active events
  via API4 (`Event::get()` where `is_active = TRUE`).
- **Participant role** dropdown — `ContactUpdater::getParticipantRoles()` fetches
  active participant roles via API4.
- **Form submit** saves `entity` = `'event'`, `entity_id` (event ID), and
  `participant_role_id` to the `field_civicrm` JSON field on the product variation.

### 4.2 What's Missing

#### 4.2.1 Participant Creation ❌ CRITICAL

**Use case**: A customer purchases an event registration product. A CiviCRM
`Participant` record should be created linking the contact to the event.

**Current behaviour**: `OrderCivicrmUpdater::processOrderItem()` has **no
`entity === 'event'` branch**. Products configured as event registrations are silently
ignored during order processing. No `Participant::create()` call exists anywhere
in the codebase.

**This is the single largest functional gap in the module.**

**What's needed**:
```php
// In OrderCivicrmUpdater::processOrderItem()
if ($entity === 'event') {
  \Civi\Api4\Participant::create(FALSE)
    ->addValue('contact_id', $contact_id)
    ->addValue('event_id', $entity_id)
    ->addValue('role_id', $participant_role_id)
    ->addValue('status_id:name', 'Registered')
    ->addValue('register_date', date('Y-m-d H:i:s'))
    ->addValue('source', 'Commerce Order #' . $order->id())
    ->execute();
}
```

#### 4.2.2 Event + Contribution Linking ❌

**Use case**: When a participant registers for a paid event, CiviCRM links the
participant record to a contribution for financial tracking.

**Current behaviour**: Not implemented.

**What's needed**:
- Use the `Order::create()` API (same pattern as membership+contribution) with
  `entity_table` → `civicrm_participant` in the line item.
- This creates the contribution, line item, and participant atomically.

#### 4.2.3 Event Capacity / Waitlist ❌

**Use case**: Events have a maximum number of participants. Once full, additional
registrations go to a waitlist or are blocked.

**Current behaviour**: Not implemented. No capacity check before accepting orders.

**What's needed**:
- Before allowing checkout, query event capacity:
  `Event::get()->addSelect('max_participants', 'event_full_text')`.
- Count existing participants:
  `Participant::get()->addWhere('event_id', '=', $id)->selectRowCount()`.
- If full and waitlist enabled: create participant with status `'On waitlist'`.
- If full and no waitlist: block checkout with an error message.
- Integration with Commerce availability checking.

#### 4.2.4 Session / Track Registration ❌

**Use case**: Multi-day conferences with breakout sessions. Each session is a separate
CiviCRM event linked to a parent event.

**Current behaviour**: Not implemented.

**What's needed**:
- Support for child events (sessions) on a single product or multi-product bundle.
- Create parent + child participant records.
- Schedule conflict detection.

#### 4.2.5 Event-Specific Pricing ❌

**Use case**: CiviCRM events can have price sets with multiple price fields (e.g.,
"Early Bird $50 / Regular $75 / VIP $150"). Commerce product price should sync with
CiviCRM event pricing.

**Current behaviour**: Commerce product price is independent. No CiviCRM price set
integration.

**What's needed**:
- Fetch event price sets via `PriceSet::get()` and `PriceField::get()`.
- Map Commerce product variations to CiviCRM price field values.
- Or dynamically create Commerce product variations from CiviCRM price fields.

#### 4.2.6 Participant Cancellation ❌

**Use case**: Order cancellation should cancel or remove the participant registration.

**Current behaviour**: `processCancellation()` handles memberships and contributions
but has **no participant cancellation logic**.

**What's needed**:
- In `processCancellation()`, add an `entity === 'event'` branch that calls
  `Participant::update()` setting `status_id:name` → `'Cancelled'`.

#### 4.2.7 Duplicate Registration Detection ❌

**Use case**: Prevent the same contact from registering for the same event twice.

**Current behaviour**: Not implemented. No de-duplication check.

**What's needed**:
- Before creating a participant, query for existing:
  `Participant::get()->addWhere('contact_id', '=', $cid)->addWhere('event_id', '=', $eid)`.
- If found, either block or update the existing registration.

---

## 5. Mailing / Group Subscriptions

### 5.1 What's Implemented

**Contact-to-group subscription** ✅ — `MailingUpdater::addContactToMailingGroup()`
creates or updates `GroupContact` records via API4. Sets status to `'Added'` or
`'Pending'` based on double-opt-in preference.

**Removal from groups** ✅ — `removeContactFromMailingGroup()` sets status to
`'Removed'`. Idempotent.

**Group membership checking** ✅ — `checkGroupMembership()` queries existing
`GroupContact` records.

**Order integration** ✅ — `processMailingSubscriptionFromOrder()` is called from
`OrderCivicrmUpdater::processOrderItem()` when `entity === 'mailing'`.

**Re-subscription handling** ✅ — When a contact was previously removed, the status
is updated back to `'Added'`.

### 5.2 What's Partially Implemented

#### 5.2.1 Double Opt-In Flow ⚠️

**Status is correctly set** to `'Pending'` when `double_opt_in` preference is enabled.
However, `sendDoubleOptInEmail()` is a **stub** — it logs a message but sends no email.
The `@todo` comment says: *"Implement full double opt-in flow: token generation, Drupal
route for confirmation, token validation, status update."*

**What's needed**:
- Generate a unique confirmation token.
- Store token with expiry (Drupal `tempstore` or database table).
- Create a Drupal route for confirmation URL.
- Send email via Drupal mail system or CiviCRM's `MessageTemplate` API.
- On confirmation: validate token, update `GroupContact` status to `'Added'`.

#### 5.2.2 Welcome Messages ⚠️

`sendWelcomeMessage()` is a **stub** — logs only. The `@todo` says: *"Implement via
CiviCRM MessageTemplate API."*

**What's needed**:
- Call `\Civi\Api4\MessageTemplate::get()` to find the appropriate template.
- Use `\Civi\Api4\Action\MessageTemplate\Send` or CiviCRM's `Mail::send()` API.
- Or delegate to Drupal's `MailManager` if the template lives in Drupal.

### 5.3 What's Missing

#### 5.3.1 Cancellation-Triggered Unsubscribe ❌

**Use case**: When a mailing-product order is cancelled, the contact should be
removed from the mailing group.

**Current behaviour**: `processCancellation()` handles memberships and contributions
but does **not** call `removeContactFromMailingGroup()` for mailing products.

**What's needed**:
- Add an `entity === 'mailing'` branch in `processCancellation()`.

#### 5.3.2 Group Validation ❌

No check that the `$mailing_group_id` refers to a valid, active mailing group before
attempting subscription.

**What's needed**:
- `Group::get(FALSE)->addWhere('id', '=', $id)->addWhere('is_active', '=', TRUE)`.

#### 5.3.3 GDPR / Consent Tracking ❌

**Use case**: GDPR requires recording when and how consent was given for marketing
communications.

**Current behaviour**: Not implemented. No consent timestamps, no legal basis
recording.

**What's needed**:
- Record consent date, source, and legal basis on the `GroupContact` or contact record.
- Integration with CiviCRM's GDPR extension if installed.
- Drupal-side consent record for audit trail.

#### 5.3.4 Smart Group Support ❌

Mailing groups in CiviCRM can be "smart groups" (saved searches). The current
implementation only works with regular groups (`GroupContact` records). Smart group
membership is dynamic and cannot be managed via `GroupContact::create()`.

**What's needed**:
- Detect group type before attempting to add contacts.
- Error or fallback gracefully for smart groups.

---

## 6. Cross-Cutting Architectural Issues

### 6.1 Duplicated Code

| Pair | Location A | Location B |
|------|-----------|-----------|
| Contribution lookup | `ContributionUpdater::findExistingContribution()` | `OrderCivicrmUpdater::findExistingContributionForOrder()` |
| Custom field provisioning | `ContributionUpdater::ensureCustomFieldExists()` | `OrderCivicrmUpdater::ensureCommerceOrderCustomFieldExists()` |
| Membership renewal lookup | `MembershipUpdater::findExistingMembership()` | `OrderCivicrmUpdater::findExistingMembershipForRenewal()` |

**Recommendation**: Extract shared methods into `ContributionUpdater` (for contribution
lookups) and `MembershipUpdater` (for membership lookups). Have `OrderCivicrmUpdater`
delegate to them.

### 6.2 One Contribution Per Order Item

The module creates separate contributions per order item. CiviCRM best practice is
one contribution per financial transaction (i.e., per order), with multiple line items.

**Impact**: Inflated contribution counts, financial reports don't match Commerce
order totals, difficulty reconciling payments.

**Recommendation**: Refactor to use a single `Order::create()` call per order, adding
multiple `addLineItem()` calls for each order item.

### 6.3 Inconsistent Payment Data

Path 1 (`createContributionFromOrder`) captures `payment_instrument_id` and `trxn_id`.
Paths 2 and 3 do not.

**Impact**: Contributions created via the active code paths lack payment method and
gateway transaction ID.

**Recommendation**: Extract `getPaymentInfo()` to a shared utility. Call it from all
contribution creation paths.

### 6.4 Possibly Dead Code

`ContributionUpdater::createContributionFromOrder()` (Path 1) has no callers in the
current codebase. It may have been superseded by Path 2
(`createContributionFromOrderWithFinancialType()`).

**Recommendation**: Either remove it or document it as a public API entry point for
custom integrations.

### 6.5 Missing Event Processing Branch

`processOrderItem()` handles `membership`, `contribution`, and `mailing` entities but
has **no `event` branch** despite the product form UI supporting event configuration.
This is the **largest functional gap** — event registration products are silently ignored.

---

## 7. Priority Recommendations

### Tier 1 — High Impact, Moderate Effort

| # | Feature | Reason |
|---|---------|--------|
| 1 | **Event participant creation** | Critical functional gap — form UI works but backend is missing. All event registration products are silently broken. |
| 2 | **Payment data on all contribution paths** | Active code paths lack payment_instrument_id and trxn_id. Quick fix. |
| 3 | **Deduplicate shared methods** | Three pairs of near-identical methods across two services. Reduces maintenance burden. |
| 4 | **Mailing cancellation handling** | Missing `entity === 'mailing'` branch in `processCancellation()`. Quick fix. |

### Tier 2 — High Impact, Higher Effort

| # | Feature | Reason |
|---|---------|--------|
| 5 | **Single contribution per order** | Architectural change. Aligns with CiviCRM accounting best practices. Requires refactoring the order processing loop. |
| 6 | **Tax handling** | Important for any store charging tax. Requires reading Commerce adjustments and mapping to CiviCRM financial accounts. |
| 7 | **Event + contribution linking** | Extends #1 to use Order API for atomic participant+contribution creation. |

### Tier 3 — Medium Impact, Moderate Effort

| # | Feature | Reason |
|---|---------|--------|
| 8 | **Refund processing** | Commerce stores need refund support. Requires `Payment::create()` with negative amounts. |
| 9 | **Double opt-in email** | Mailing stub needs real implementation. Important for email compliance. |
| 10 | **Membership upgrade/downgrade** | Common membership workflow. Requires cross-type membership detection. |
| 11 | **Event capacity / waitlist** | Important for events with limited seats. |
| 12 | **Participant cancellation** | Completes the event registration lifecycle. |

### Tier 4 — Lower Priority / Future

| # | Feature | Reason |
|---|---------|--------|
| 13 | Recurring contributions / auto-renewal | Requires Commerce subscription module integration. |
| 14 | Family/org memberships | Requires checkout UI changes for related contacts. |
| 15 | Welcome message sending | Mailing stub. Lower urgency than double opt-in. |
| 16 | GDPR consent tracking | Important for EU compliance but separate concern. |
| 17 | Event pricing integration | Complex: syncing CiviCRM price sets with Commerce products. |
| 18 | Partial payments / instalments | Niche use case. |
| 19 | Soft credits | Peer-to-peer fundraising. Niche. |
| 20 | Multi-quantity memberships | Organizational bulk purchases. |
| 21 | Session/track registration | Conference-specific. |
| 22 | Smart group support | Edge case for mailing integration. |

---

*Generated from codebase audit of `commerce_civicrm` module.*
*All line references are approximate — the codebase may have shifted since the audit.*
