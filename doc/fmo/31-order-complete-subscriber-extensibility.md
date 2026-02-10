# FMO #31 — OrderCompleteSubscriber Extensibility

**Todo item**: `### 31. OrderCompleteSubscriber extensibility`
**Priority**: Future
**Date**: 2026-02-10

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current Architecture](#2-current-architecture)
3. [Duplicate Processing Bug](#3-duplicate-processing-bug)
4. [Missing Cancellation Branches](#4-missing-cancellation-branches)
5. [Extensibility Gaps](#5-extensibility-gaps)
6. [Use Cases Blocked](#6-use-cases-blocked)
7. [Error Handling & Resilience](#7-error-handling--resilience)
8. [Recommended Implementation](#8-recommended-implementation)
9. [Priority Recommendations](#9-priority-recommendations)

---

## 1. Executive Summary

The `OrderCompleteSubscriber` + `OrderCivicrmUpdater` pipeline is the core of
the module: it converts Commerce order transitions into CiviCRM API calls. The
pipeline is **functionally correct for the default workflow** but has three
critical gaps:

1. **Duplicate processing bug** — fulfillment workflows fire `processOrder()`
   twice; only the linked membership+contribution path has an internal guard.
2. **Zero extensibility** — no events dispatched, no hooks invoked, no alters
   called. Other modules cannot extend, intercept, or modify the pipeline.
3. **Incomplete cancellation** — mailing subscriptions and event registrations
   (when implemented) are not reversed on order cancellation.

---

## 2. Current Architecture

### 2.1 Event Subscriber — Four Transition Handlers

`OrderCompleteSubscriber` ([OrderCompleteSubscriber.php](../../../src/EventSubscriber/OrderCompleteSubscriber.php))
subscribes to four Commerce `post_transition` events at priority `-50`:

| Handler | Event | Line | State Guard | Calls |
|---------|-------|------|-------------|-------|
| `onOrderPlace()` | `commerce_order.place.post_transition` | L62 | `from=draft AND to=completed` | `processOrder()` |
| `onOrderValidate()` | `commerce_order.validate.post_transition` | L103 | **None** | `processOrder()` |
| `onOrderFulfill()` | `commerce_order.fulfill.post_transition` | L131 | **None** | `processOrder()` |
| `onOrderCancel()` | `commerce_order.cancel.post_transition` | L159 | `to=canceled` | `processCancellation()` |

**Key observations:**

- All three non-cancel handlers extract `from_state`, `to_state`, and `workflow`
  but **never pass them** to `OrderCivicrmUpdater`. The updater has no knowledge
  of which transition triggered it.
- `processOrder()` return value is **ignored** by all three handlers.
  `processCancellation()` return is captured and logged.
- Customer guard: all handlers check `$order->getCustomer()` and return early
  if null.
- Entity type guard: all handlers check `$order->getEntityTypeId() !== 'commerce_order'`.

### 2.2 Order Processing Flow

`OrderCivicrmUpdater::processOrder()` ([OrderCivicrmUpdater.php](../../../src/Service/OrderCivicrmUpdater.php) L65–L135):

```
processOrder($order)
  ├─ Guard: civicrmHelper->isAvailable()        → return [] if unavailable
  ├─ Guard: $order->getCustomer()               → return [] if no customer
  ├─ Contact resolution: getContactIdByUser()   → return [] if no contact
  └─ foreach ($order->getItems()) {
       try {
         processOrderItem($item, $contact_id, $order)
       } catch (\CRM_Core_Exception) { log & continue }
     }
```

### 2.3 Order Item Processing — Entity Type Branches

`processOrderItem()` (L141–L305) reads the product's `field_civicrm` JSON via
`getCivicrmProductSettings()` and dispatches based on the configuration:

| Branch | Condition | Lines | Method Called |
|--------|-----------|-------|--------------|
| Linked membership+contribution | `membership_type_id` AND `financial_type_id` | L195–L212 | `createLinkedMembershipContribution()` |
| Membership only | `membership_type_id` AND NOT `financial_type_id` | L213–L241 | `membershipUpdater->createMembershipFromOrder()` |
| Contribution only | NOT `membership_type_id` AND `financial_type_id` | L243–L273 | `contributionUpdater->createContributionFromOrderWithFinancialType()` |
| Mailing subscription | `entity === 'mailing'` AND `entity_id` | L275–L296 | `mailingUpdater->processMailingSubscriptionFromOrder()` |
| **Event registration** | `entity === 'event'` | **NONE** | **Silently ignored** |

**Important**: The mailing branch (L275) uses an independent `if`, not `elseif`.
A product could theoretically trigger both a membership and a mailing in one pass.

### 2.4 Settings Normalization Gap

`getCivicrmProductSettings()` (L625–L664) has a `switch` on `$settings['entity']` at
L651 that maps entity types to internal settings:

```php
switch ($settings['entity']) {
    case 'membership':
        $settings['membership_type_id'] = $settings['entity_id'];
        break;
    case 'contribution':
        $settings['financial_type_id'] = $settings['entity_id'];
        break;
    // NO case 'event' — NO case 'mailing' for ID mapping
}
```

- `mailing` works because `processOrderItem()` reads `$civicrm_settings['entity']`
  and `$civicrm_settings['entity_id']` directly (L275).
- `event` falls through: `membership_type_id` and `financial_type_id` remain null,
  no membership/contribution branch fires, the mailing check fails, and the product
  is silently ignored at L298–L302 with a "No CiviCRM records created" info log.

### 2.5 Cancellation Flow

`processCancellation()` (L669–L811) iterates order items:

| Entity Type | Cancellation Action | Lines |
|-------------|-------------------|-------|
| Membership (`membership_type_id` set) | `cancelMembershipFromOrder()` — per item | L758–L776 |
| Contribution (`financial_type_id` set) | `cancelContributionFromOrder()` — once per order via `$contribution_cancelled` flag | L780–L804 |
| **Mailing** (`entity === 'mailing'`) | **Not handled** | — |
| **Event** (`entity === 'event'`) | **Not handled** | — |

Each cancellation is wrapped in its own `try/catch (\CRM_Core_Exception)` — one
failure does not block others.

### 2.6 Service Wiring

```yaml
# commerce_civicrm.services.yml
commerce_civicrm.order_complete_subscriber:
  arguments: ['@logger.factory', '@commerce_civicrm.order_civicrm_updater']
  tags: [{ name: event_subscriber }]

commerce_civicrm.order_civicrm_updater:
  arguments:
    - '@logger.factory'
    - '@commerce_civicrm.civicrm_helper'
    - '@commerce_civicrm.contact_updater'
    - '@commerce_civicrm.contribution_updater'
    - '@commerce_civicrm.membership_updater'
    - '@commerce_civicrm.mailing_updater'
```

**Not injected**: `EventDispatcherInterface`, `ModuleHandlerInterface`. The updater
has no mechanism to dispatch events or invoke hooks.

---

## 3. Duplicate Processing Bug

### 3.1 Commerce Workflow Analysis

| Workflow | Transitions | Handlers That Fire |
|----------|------------|-------------------|
| **`order_default`** (simple) | `place`: draft → completed | `onOrderPlace` only |
| **`order_default_validation`** (fulfillment) | `place`: draft → validation, `validate`: validation → fulfillment, `fulfill`: fulfillment → completed | `onOrderPlace` + `onOrderValidate` + `onOrderFulfill` |

### 3.2 Trace: Fulfillment Workflow

| Step | Commerce Transition | Handler | State Guard | Calls `processOrder()`? |
|------|---------------------|---------|-------------|------------------------|
| 1 | `place` (draft → validation) | `onOrderPlace` | from=draft, to=**validation** (not completed) → **guard rejects** | **No** ✓ |
| 2 | `validate` (validation → fulfillment) | `onOrderValidate` | **No guard** | **Yes — 1st call** |
| 3 | `fulfill` (fulfillment → completed) | `onOrderFulfill` | **No guard** | **Yes — 2nd call** |

**Result**: `processOrder()` runs **twice** for the same order.

### 3.3 Internal Duplicate Guards

| Processing Path | Has Duplicate Guard? | Method | Impact on 2nd Call |
|----------------|---------------------|--------|-------------------|
| Linked membership+contribution | ✅ | `findExistingContributionForOrder()` at L328 | Returns existing IDs; no duplicate created |
| Standalone membership | ⚠️ | `MembershipUpdater::findExistingMembership()` inside `createMembershipFromOrder()` | If existing found, calls `updateMembership()` instead — **safe but performs redundant update** |
| Standalone contribution | ❌ | **None** | **Creates a duplicate contribution** |
| Mailing subscription | ⚠️ | `checkGroupMembership()` inside `addContactToMailingGroup()` | Idempotent — updates existing `GroupContact` |

**Verdict**: Standalone contribution products are at risk of duplicate creation
in fulfillment workflows. This is a **bug**, not a feature gap.

### 3.4 Fix Required

Either:
- **Option A** (minimal): Add state guards to `onOrderValidate()` and
  `onOrderFulfill()` — e.g., only process on the **final** transition to
  `completed`.
- **Option B** (robust): Add a per-order duplicate guard in `processOrder()` —
  check if CiviCRM records already exist for this order ID before processing.
  This protects against all scenarios including manual re-processing.
- **Option C** (transition-aware): Pass transition context to `processOrder()`
  and let it decide when to create vs. update records based on the workflow stage.

---

## 4. Missing Cancellation Branches

### 4.1 Mailing Cancellation ❌

**Problem**: A customer purchases a mailing-subscription product, gets added to a
CiviCRM group. The order is later cancelled. The contact remains in the group.

**Existing code**: `MailingUpdater::removeContactFromMailingGroup()` is implemented
and functional — it sets `GroupContact.status` to `'Removed'`. But
`processCancellation()` has **no `entity === 'mailing'` branch** to call it.

**Fix**: Add after the contribution cancellation block (L804):

```php
// Cancel mailing subscription if configured.
if (!empty($civicrm_settings['entity'])
    && $civicrm_settings['entity'] === 'mailing'
    && !empty($civicrm_settings['entity_id'])) {
  try {
    $removed = $this->mailingUpdater->removeContactFromMailingGroup(
      $contact_id,
      $civicrm_settings['entity_id']
    );
    if ($removed) {
      $cancelled_records['mailings'][] = $civicrm_settings['entity_id'];
    }
  }
  catch (\CRM_Core_Exception $e) {
    $this->logger->error('Error removing mailing subscription: @error', [
      '@error' => $e->getMessage(),
    ]);
  }
}
```

**Effort**: Low (< 20 lines). **Impact**: Medium. **Should be a standalone prompt.**

### 4.2 Event Cancellation ❌

**Problem**: When event registration processing is eventually implemented (see
FMO #32), cancellation must also handle participant status updates
(`Participant::update()` → status `'Cancelled'`).

**Status**: Not actionable until event processing is implemented.

---

## 5. Extensibility Gaps

### 5.1 Verification: Zero Extension Points

Confirmed via terminal search — no extensibility mechanisms exist anywhere in
`src/` or `commerce_civicrm.module`:

| Pattern | Occurrences in `src/` |
|---------|----------------------|
| `dispatch` (Symfony event dispatch) | 0 |
| `moduleHandler->invokeAll` | 0 |
| `moduleHandler->alter` | 0 |
| `moduleHandler->invoke` | 0 |
| `EventDispatcherInterface` (injected) | 0 |

`EventDispatcherInterface` is **not injected** into `OrderCivicrmUpdater`
(confirmed in `services.yml`). `ModuleHandlerInterface` is injected into
`CivicrmHelper` but not into `OrderCivicrmUpdater` or any processing service.

### 5.2 What This Means

The order processing pipeline is a **closed black box**. Other Drupal modules
or custom code cannot:

- **React** to CiviCRM record creation or failure
- **Modify** data before it's sent to CiviCRM API
- **Add** custom entity type processing (e.g., Activities, Cases, Pledges)
- **Veto** or conditionally skip processing for specific orders/products
- **Override** default field mappings per product type
- **Extend** the pipeline with additional CiviCRM record types
- **Audit** or log processing for compliance

### 5.3 Comparison with Drupal Ecosystem Patterns

Drupal modules typically provide extensibility via:

| Pattern | Used By | Status in This Module |
|---------|---------|---------------------|
| Custom Symfony events | Commerce, Search API, Migrate | ❌ Not used |
| `hook_MODULENAME_*` alter hooks | Nearly all contrib modules | ❌ Not used |
| Tagged service collectors | Commerce (resolvers, adjusters) | ❌ Not used |
| Plugin system | Commerce (payment, shipping) | ❌ Not used (not applicable) |
| Event subscribers (subscribable by others) | Commerce order events | ❌ No custom events to subscribe to |

---

## 6. Use Cases Blocked

### 6.1 Custom Cancel Handlers

Without extensibility, other modules cannot react to order cancellation:

| Use Case | Why Needed | Status |
|----------|-----------|--------|
| Remove from mailing list on cancel | Gap #4.1 — should be built-in | ❌ Missing |
| Update custom CiviCRM fields on cancel | Track cancellation reason, count | ❌ Blocked |
| Trigger CiviCRM case/activity on cancel | Org-specific processing rules | ❌ Blocked |
| Send admin notifications on cancel | Compliance, fraud detection | ❌ Blocked |
| Sync cancellation with external systems | Payment processors, inventory | ❌ Blocked |
| Revoke benefits on cancel | Discount codes, content access | ❌ Blocked |

### 6.2 Custom Fulfillment Handlers

Without transition awareness, modules cannot differentiate order stages:

| Use Case | Why Needed | Status |
|----------|-----------|--------|
| Activate membership only on fulfillment | Membership shouldn't start until shipped | ❌ Blocked |
| Send welcome email on fulfillment | Email timing matters for UX | ❌ Blocked |
| Trigger post-purchase workflows | Drip campaigns, onboarding sequences | ❌ Blocked |
| Create CiviCRM Activity on fulfillment | Track fulfillment in contact timeline | ❌ Blocked |
| Differentiate "placed" vs "shipped" | Different CiviCRM statuses per stage | ❌ Blocked |

### 6.3 Custom Record Types

Without per-item events, modules cannot add CiviCRM record types:

| Use Case | CiviCRM Entity | Status |
|----------|---------------|--------|
| Create Activity on purchase | `Activity::create()` | ❌ Blocked |
| Create Case on purchase | `Case::create()` | ❌ Blocked |
| Create Pledge on purchase | `Pledge::create()` | ❌ Blocked |
| Add Tag to contact | `EntityTag::create()` | ❌ Blocked |
| Create Relationship | `Relationship::create()` | ❌ Blocked |
| Record Note on contact | `Note::create()` | ❌ Blocked |

### 6.4 Data Modification

Without alter hooks, modules cannot modify data before CiviCRM API calls:

| Use Case | Why Needed | Status |
|----------|-----------|--------|
| Override financial type per store | Multi-store setups | ❌ Blocked |
| Add custom field values to contributions | Org-specific tracking | ❌ Blocked |
| Modify contact data before update | Data normalization rules | ❌ Blocked |
| Override CiviCRM settings per product | Complex product configurations | ❌ Blocked |
| Apply conditional membership logic | Time-limited promotions | ❌ Blocked |

---

## 7. Error Handling & Resilience

### 7.1 Exception Handling

`processOrder()` catches `\CRM_Core_Exception` per item (L120). This means:

| Exception Type | Behavior |
|---------------|----------|
| `\CRM_Core_Exception` | Logged, processing continues to next item ✅ |
| `\RuntimeException`, `\TypeError`, etc. | **Bubbles up uncaught**, aborts all remaining items ❌ |
| `\Exception` (generic) | **Bubbles up uncaught** ❌ |

**Risk**: A PHP error in any service method (e.g., null dereference, type error)
will abort the entire order processing pipeline for remaining items.

### 7.2 CiviCRM Unavailability

If CiviCRM is unavailable when an order is placed:

1. `processOrder()` checks `isAvailable()` → returns `[]`, logs error
2. Commerce order transition **completes normally** — customer sees success
3. **No CiviCRM records** are created
4. **No retry mechanism** — the order is effectively "lost" to CiviCRM
5. No flag on the order, no queue entry, no admin notification

If CiviCRM goes down **mid-processing** (between items):

1. Individual API calls throw `\CRM_Core_Exception`
2. Per-item catch handles it — some items succeed, some fail
3. **Partial processing state** — some records exist, others don't
4. No reconciliation mechanism

### 7.3 Failure Visibility

| Audience | Can See Failures? | How |
|----------|------------------|-----|
| Customer | **No** | No exceptions reach the response. No Drupal messages. Order completes normally. |
| Admin (realtime) | **No** | No email alerts, no dashboard warnings, no admin notifications. |
| Admin (retrospective) | **Partially** | Errors logged to `commerce_civicrm` channel at `admin/reports/dblog`. Requires Database Logging module. Easy to miss. |
| Developer | **Partially** | Log messages include order IDs and error details. No structured error reporting. |

---

## 8. Recommended Implementation

### Phase 1: Duplicate Processing Guard (Bug Fix)

**Problem**: Fulfillment workflows cause double `processOrder()` calls.

**Approach**: Add state guards to `onOrderValidate()` and `onOrderFulfill()`, or
add an order-level duplicate check at the start of `processOrder()`.

**Option A — State guards** (minimal, same pattern as `onOrderPlace`):

```php
// onOrderValidate: only process when transitioning to 'fulfillment'
// onOrderFulfill: only process when transitioning to 'completed'
```

**Option B — Order-level guard** (robust):

```php
// At top of processOrder():
// Check if any CiviCRM records already exist for this order
// If yes, log and return existing records without re-creating
```

**Effort**: Low. **Impact**: Critical — fixes a data integrity bug.

### Phase 2: Mailing Cancellation (Bug Fix)

Wire `MailingUpdater::removeContactFromMailingGroup()` into
`processCancellation()`. See §4.1 for implementation.

**Effort**: Low (< 20 lines). **Impact**: Medium.

### Phase 3: Custom Symfony Events

Create event classes dispatched at key pipeline stages:

```
src/Event/
  OrderCivicrmEvent.php          (base class)
  OrderPreProcessEvent.php       (before any CiviCRM calls)
  OrderPostProcessEvent.php      (after all records created)
  OrderPreCancelEvent.php        (before cancellation)
  OrderPostCancelEvent.php       (after cancellation)
  OrderItemPreProcessEvent.php   (per-item, before record creation)
  OrderItemPostProcessEvent.php  (per-item, after record creation)
```

Each event carries:
- `OrderInterface $order`
- `int $contactId`
- `?string $fromState`, `?string $toState`, `?string $workflowId` (transition context)
- `array $createdRecords` / `array $cancelledRecords` (for post-events)
- `array $civicrmSettings` (for item-level events)

**Service wiring change**: Inject `EventDispatcherInterface` into
`OrderCivicrmUpdater`.

**Dispatch points in `processOrder()`**:

```php
// Before the foreach loop:
$this->eventDispatcher->dispatch(
  new OrderPreProcessEvent($order, $contactId, $fromState, $toState, $workflowId)
);

// Inside foreach, before processOrderItem():
$itemEvent = new OrderItemPreProcessEvent($order, $orderItem, $contactId, $civicrmSettings);
$this->eventDispatcher->dispatch($itemEvent);
// Allow event subscribers to modify $civicrmSettings or skip processing

// Inside foreach, after processOrderItem():
$this->eventDispatcher->dispatch(
  new OrderItemPostProcessEvent($order, $orderItem, $contactId, $itemRecords)
);

// After the foreach loop:
$this->eventDispatcher->dispatch(
  new OrderPostProcessEvent($order, $contactId, $createdRecords)
);
```

**Use case examples**:

```php
// Other module's event subscriber:
class CustomCivicrmSubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents(): array {
    return [
      OrderItemPostProcessEvent::class => 'onItemProcessed',
      OrderPostCancelEvent::class => 'onOrderCancelled',
    ];
  }

  public function onItemProcessed(OrderItemPostProcessEvent $event): void {
    // Create a CiviCRM Activity for each processed item
    Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Commerce Purchase')
      ->addValue('source_contact_id', $event->getContactId())
      ->addValue('subject', 'Purchased: ' . $event->getOrderItem()->getTitle())
      ->execute();
  }

  public function onOrderCancelled(OrderPostCancelEvent $event): void {
    // Send admin notification
    $this->mailManager->mail('my_module', 'order_cancelled', ...);
  }
}
```

**Effort**: Medium (new event classes + dispatch calls + service wiring).
**Impact**: High — core extensibility enabler.

### Phase 4: Hook/Alter Integration

Drupal-idiomatic hooks for simpler use cases (no event subscriber class needed):

```php
// In OrderCivicrmUpdater::processOrderItem(), before creating records:
$this->moduleHandler->alter(
  'commerce_civicrm_order_settings',
  $civicrm_settings, $product, $order
);

// In OrderCivicrmUpdater, after contact resolution:
$this->moduleHandler->alter(
  'commerce_civicrm_contact_data',
  $contact_data, $order
);

// In OrderCivicrmUpdater::processOrder(), after all items processed:
$this->moduleHandler->invokeAll(
  'commerce_civicrm_post_process',
  [$order, $created_records]
);
```

**Service wiring change**: Inject `ModuleHandlerInterface` into
`OrderCivicrmUpdater`.

**Effort**: Medium. **Impact**: Medium — complements Symfony events with
simpler hook-based extension.

### Phase 5: Transition-Aware Processing

Pass transition context from subscriber to updater:

```php
// OrderCompleteSubscriber — all handlers:
$this->orderCivicrmUpdater->processOrder($order, $from_state, $to_state, $workflow->getId());

// OrderCivicrmUpdater::processOrder() signature change:
public function processOrder(
  OrderInterface $order,
  ?string $fromState = NULL,
  ?string $toState = NULL,
  ?string $workflowId = NULL,
): array {
```

This enables:
- Different processing behavior per transition stage
- Proper handling of multi-step workflows
- Events carrying full transition context

**Effort**: Low (signature change + parameter forwarding). **Impact**: High —
prerequisite for proper workflow support.

### Phase 6: Resilience Improvements

#### 6a. Broader Exception Catching

Change per-item catch from `\CRM_Core_Exception` to `\Throwable`:

```php
} catch (\Throwable $e) {
  $this->logger->error('Error processing order item @index: @error', [...]);
}
```

This prevents PHP errors (TypeError, RuntimeException) from aborting remaining
items.

#### 6b. Processing Queue

For CiviCRM unavailability, enqueue failed orders for retry:

```php
if (!$this->civicrmHelper->isAvailable()) {
  $this->queue->createItem(['order_id' => $order->id()]);
  $this->logger->warning('CiviCRM unavailable — order @id queued for retry', [...]);
  return [];
}
```

Requires a Drupal Queue worker and a cron-based retry mechanism.

#### 6c. Admin Notifications

On processing failure, add an admin-visible warning:

```php
\Drupal::messenger()->addWarning(
  $this->t('CiviCRM processing failed for order @id. Check logs.', ['@id' => $order->id()])
);
```

Or better: dispatch an event that an admin-notification module can subscribe to.

**Effort**: Medium–High. **Impact**: Medium — improves operational reliability.

---

## 9. Priority Recommendations

### Tier 1 — Bug Fixes (should be standalone prompts)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 1 | **Duplicate processing guard** — `onOrderValidate` and `onOrderFulfill` lack state guards, causing double `processOrder()` in fulfillment workflows | Low | Critical |
| 2 | **Mailing cancellation** — add `entity === 'mailing'` branch to `processCancellation()` | Low | Medium |
| 3 | **Broader exception catching** — change per-item catch to `\Throwable` | Low | Medium |

### Tier 2 — Core Extensibility

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 4 | **Transition context forwarding** — pass from/to state and workflow ID to `processOrder()` | Low | High |
| 5 | **Custom Symfony events** — 6–8 event classes + dispatch calls | Medium | High |
| 6 | **Hook/alter integration** — settings alter, contact data alter, post-process hook | Medium | Medium |

### Tier 3 — Resilience

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 7 | **Processing queue** for CiviCRM unavailability | Medium | Medium |
| 8 | **Admin failure notifications** | Low | Medium |
| 9 | **Return value handling** — log `processOrder()` results in subscriber | Low | Low |

### Dependencies

```
Phase 1 (duplicate guard)     ──→ no dependencies
Phase 2 (mailing cancel)      ──→ no dependencies
Phase 3 (Symfony events)      ──→ requires Phase 5 (transition context)
Phase 4 (hooks/alters)        ──→ inject ModuleHandlerInterface
Phase 5 (transition context)  ──→ no dependencies
Phase 6 (resilience)          ──→ no dependencies
```

**Recommended order**: Phase 1 → Phase 2 → Phase 5 → Phase 3 → Phase 4 → Phase 6.

---

*Generated from codebase audit of `commerce_civicrm` module.*
*All line references are approximate — the codebase may have shifted since the audit.*
