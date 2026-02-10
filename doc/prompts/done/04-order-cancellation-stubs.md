# Agent Prompt: Implement Todo #4 — Order Cancellation Stubs

## Objective

Implement real order cancellation logic in `OrderCivicrmUpdater::processCancellation()`, which is currently a stub that only logs a warning. The method must iterate the cancelled order's items, identify linked CiviCRM records (contributions, memberships), and cancel each one. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `src/Service/OrderCivicrmUpdater.php`, `processCancellation()` (L369–L388) is a placeholder:

```php
public function processCancellation(OrderInterface $order) {
    $this->logger->info('Processing cancellation for order @order_id', [
      '@order_id' => $order->id(),
    ]);
    // For now, just log the cancellation
    $this->logger->warning('Order @order_id was cancelled - manual CiviCRM record review may be needed', [
      '@order_id' => $order->id(),
    ]);
}
```

This method is called from `OrderCompleteSubscriber::onOrderCancel()` when an order transitions to the `canceled` state, but does nothing — leaving CiviCRM contributions and memberships active despite the order being cancelled.

The child services already have individual cancellation methods:
- `ContributionUpdater::cancelContributionFromOrder(OrderInterface $order)` — cancels a contribution by finding it via the `Commerce_Order.commerce_order_id` custom field (or `source` fallback) and setting the status to "Cancelled"
- `MembershipUpdater::cancelMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item)` — finds and cancels a membership by contact + type

But `processCancellation()` never calls either of them.

## What to implement

### 1. Rewrite `processCancellation()` in `OrderCivicrmUpdater`

The method should mirror the pattern of `processOrder()` (L97–L185) — iterate order items, read product settings, and delegate to child services — but for cancellation instead of creation.

```php
public function processCancellation(OrderInterface $order) {
    // 1. Log the start
    // 2. Check CiviCRM availability via $this->civicrmHelper->isAvailable()
    // 3. Get customer → get CiviCRM contact_id via $this->contactUpdater->getContactIdByUser($customer)
    //    - If no contact found, log warning and return (nothing to cancel)
    // 4. Iterate $order->getItems() — for each order item:
    //    a. Get variation → product (same chain as processOrderItem)
    //    b. Read $this->getCivicrmProductSettings($product)
    //    c. If settings have 'membership_type_id':
    //       - Call $this->membershipUpdater->cancelMembershipFromOrder($contact_id, $membership_type_id, $order, $order_item)
    //       - Track result in $cancelled_records['memberships'][]
    //    d. If settings have 'financial_type_id':
    //       - Call $this->contributionUpdater->cancelContributionFromOrder($order)
    //       - Track result in $cancelled_records['contributions'][]
    // 5. Log summary of cancelled records
    // 6. Return $cancelled_records array
}
```

Key considerations:
- `cancelContributionFromOrder()` takes only `$order` (it finds the contribution internally via the commerce order custom field). It should only be called **once per order**, not once per item. Track whether it's already been called.
- `cancelMembershipFromOrder()` takes `$contact_id`, `$membership_type_id`, `$order`, and `$order_item`. It should be called once per membership-typed order item.
- Wrap each cancellation call in a try/catch so one failure doesn't prevent other cancellations.
- Return an array of cancelled record IDs keyed by type (matching `processOrder()`'s return format).

### 2. Change `processCancellation()` return type

Currently returns `void`. Change it to return `array` (array of cancelled CiviCRM record IDs keyed by type), matching `processOrder()`.

### 3. Update `OrderCompleteSubscriber::onOrderCancel()` (optional)

Currently at `src/EventSubscriber/OrderCompleteSubscriber.php` L199–L225:

```php
public function onOrderCancel(WorkflowTransitionEvent $event) {
    $order = $event->getEntity();
    $to_state = $event->getToState()->getId();
    if ($to_state !== 'canceled') {
      return;
    }
    $this->logger->info('Order @order_id cancelled - processing CiviCRM cancellation', [
      '@order_id' => $order->id(),
    ]);
    $this->orderCivicrmUpdater->processCancellation($order);
}
```

If you change `processCancellation()` to return an array, optionally log the result in `onOrderCancel()`.

## Files to modify

1. **`src/Service/OrderCivicrmUpdater.php`** — Rewrite `processCancellation()` from a stub into a working implementation
2. **`src/EventSubscriber/OrderCompleteSubscriber.php`** — (Optional) Log the return value of `processCancellation()`

## Files NOT to modify

- `src/Service/ContributionUpdater.php` — `cancelContributionFromOrder()` already works
- `src/Service/MembershipUpdater.php` — `cancelMembershipFromOrder()` already works
- `commerce_civicrm.services.yml` — no new services needed
- `commerce_civicrm.install` — no update hooks needed

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Follow the same control flow pattern as `processOrder()` / `processOrderItem()` — get customer, get contact_id, iterate items, read settings, delegate
- Call `cancelContributionFromOrder($order)` at most **once per order** (it searches by order ID, not by item)
- Call `cancelMembershipFromOrder()` once per membership-type order item
- Wrap each cancellation in try/catch so failures are independent
- Use `$this->logger` for all logging (matching existing pattern)
- Include proper PHPDoc for the rewritten method

## Verification

After implementation, confirm:
1. `processCancellation()` no longer just logs a warning — it calls the child services
2. Contribution cancellation is called at most once per order
3. Membership cancellation is called for each membership-typed order item
4. The method returns an array of cancelled records (contributions/memberships)
5. Each cancellation is independently try/caught
6. No syntax errors in `OrderCivicrmUpdater.php`
