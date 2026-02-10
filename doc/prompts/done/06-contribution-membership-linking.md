# Agent Prompt: Implement Todo #6 — Contribution↔Membership LineItem Linking

## Objective

Link CiviCRM memberships to their funding contributions via LineItem records, so CiviCRM correctly tracks which payment funds which membership. Currently, memberships and contributions are created as completely independent records with no financial linkage. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In the current flow (`OrderCivicrmUpdater::processOrderItem()` in `src/Service/OrderCivicrmUpdater.php` L189–L310):

1. If the product has a `membership_type_id`, it calls `$this->membershipUpdater->createMembershipFromOrder()` → creates a standalone `Membership` record
2. If the product has a `financial_type_id`, it calls `$this->contributionUpdater->createContributionFromOrderWithFinancialType()` → creates a standalone `Contribution` record

These two records are never linked. CiviCRM 6.x expects a `LineItem` record to bridge a `Contribution` to a `Membership`:

- CiviCRM 6.1 ([#32244](https://github.com/civicrm/civicrm-core/pull/32244)): "Get Membership Contribution from LineItem instead of MembershipPayment"
- CiviCRM 6.2 ([#32423](https://github.com/civicrm/civicrm-core/pull/32423)): "Remove create membershipPayment code — it's already created by lineitem"

Without linking:
- CiviCRM reports don't show payments against memberships
- Auto-renewal and status calculations are incorrect
- Membership status doesn't update when contributions are refunded/cancelled

## What to implement

### Approach: Use `\Civi\Api4\Order::create()` (Recommended)

CiviCRM's `Order` API wraps `Contribution` + `LineItem` creation in a single transaction. This is the recommended approach for CiviCRM 6.x.

Replace the separate contribution + membership creation in `processOrderItem()` with an `Order::create()` call when **both** a membership and a financial type are configured for the same product:

```php
// When a product has BOTH membership_type_id AND financial_type_id:
$result = \Civi\Api4\Order::create(FALSE)
    ->setContributionValues([
        'contact_id' => $contact_id,
        'financial_type_id' => $financial_type_id,
        'total_amount' => $order_item->getTotalPrice()->getNumber(),
        'currency' => $order_item->getTotalPrice()->getCurrencyCode(),
        'receive_date' => date('Y-m-d H:i:s', $order->getCompletedTime() ?: time()),
        'source' => 'Commerce Order #' . $order->id(),
        'contribution_status_id:name' => 'Completed',
        'Commerce_Order.commerce_order_id' => (int) $order->id(),
    ])
    ->addLineItem([
        'line_item' => [
            'entity_table' => 'civicrm_membership',
            'financial_type_id' => $financial_type_id,
            'label' => $order_item->getTitle(),
            'qty' => (int) $order_item->getQuantity(),
            'unit_price' => $order_item->getUnitPrice()->getNumber(),
            'line_total' => $order_item->getTotalPrice()->getNumber(),
        ],
        'params' => [
            'membership_type_id' => $membership_type_id,
            'contact_id' => $contact_id,
            'source' => 'Commerce Order #' . $order->id(),
            // Do NOT set end_date — let CiviCRM calculate it (see todo #9)
        ],
    ])
    ->execute();
```

This creates a `Contribution`, `LineItem`, `Membership`, and `MembershipPayment` in one atomic operation.

### Detailed implementation plan

#### 1. Modify `OrderCivicrmUpdater::processOrderItem()` (L189–L310)

Restructure the logic to handle three cases:

1. **Membership + Financial type** (linked) → use `Order::create()` to create contribution + membership + line item together
2. **Membership only** (no financial type) → use existing `MembershipUpdater::createMembershipFromOrder()` (standalone)
3. **Financial type only** (no membership) → use existing `ContributionUpdater::createContributionFromOrderWithFinancialType()` (standalone)

```php
protected function processOrderItem(OrderItemInterface $order_item, $contact_id, OrderInterface $order) {
    // ... existing variation/product/settings retrieval ...

    $has_membership = !empty($civicrm_settings['membership_type_id']);
    $has_contribution = !empty($civicrm_settings['financial_type_id']);

    if ($has_membership && $has_contribution) {
        // Case 1: Linked — use Order::create()
        return $this->createLinkedMembershipContribution(
            $contact_id, $order, $order_item,
            $civicrm_settings['membership_type_id'],
            $civicrm_settings['financial_type_id']
        );
    }

    if ($has_membership) {
        // Case 2: Membership only (existing path)
        $membership_id = $this->membershipUpdater->createMembershipFromOrder(...);
        // ...
    }

    if ($has_contribution) {
        // Case 3: Contribution only (existing path)
        $contribution_id = $this->contributionUpdater->createContributionFromOrderWithFinancialType(...);
        // ...
    }

    return $created_records;
}
```

#### 2. Add `createLinkedMembershipContribution()` to `OrderCivicrmUpdater`

New protected method:

```php
/**
 * Creates a linked Contribution + Membership via Order::create().
 *
 * @param int $contact_id
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
 * @param int $membership_type_id
 * @param int $financial_type_id
 *
 * @return array
 *   Array of created record IDs.
 */
protected function createLinkedMembershipContribution($contact_id, OrderInterface $order, OrderItemInterface $order_item, $membership_type_id, $financial_type_id) {
    if (!$this->civicrmHelper->initialize()) {
        $this->logger->error('Failed to initialize CiviCRM for linked creation');
        return [];
    }

    // Check for existing contribution to avoid duplicates
    // (delegate to ContributionUpdater::findExistingContribution if needed)

    try {
        $result = \Civi\Api4\Order::create(FALSE)
            ->setContributionValues([...])
            ->addLineItem([...])
            ->execute();

        $created_records = [];
        // Extract contribution ID from result
        // Extract membership ID from result (look in lineitem/membership data)
        // Log success
        return $created_records;
    } catch (\Exception $e) {
        $this->logger->error('Error creating linked membership/contribution: @error', [
            '@error' => $e->getMessage(),
        ]);
        return [];
    }
}
```

#### 3. Handle renewal/existing memberships

When `Order::create()` is used with a `membership_type_id` for a contact who already has an active membership of that type, CiviCRM internally handles renewal (extending the end date). Verify that this matches the current behavior in `MembershipUpdater::createMembershipFromOrder()` which checks for existing memberships and calls `updateMembership()`.

If the behavior differs, you may need to:
- Check for existing memberships before calling `Order::create()`
- Add `'membership_id' => $existing_id` to the lineItem params to renew instead of creating a new membership

## Files to modify

1. **`src/Service/OrderCivicrmUpdater.php`** — Restructure `processOrderItem()` and add `createLinkedMembershipContribution()` method

## Files NOT to modify

- `src/Service/MembershipUpdater.php` — Still needed for standalone membership creation (Case 2)
- `src/Service/ContributionUpdater.php` — Still needed for standalone contributions (Case 3)
- `commerce_civicrm.services.yml` — no new service dependencies needed
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use OOP API4 style: `\Civi\Api4\Order::create(FALSE)` (not procedural)
- The `Order` API is part of CiviCRM core since 5.x and is stable in 6.x
- Standalone membership and standalone contribution paths must still work for products that only configure one or the other
- Duplicate detection: check for existing contribution (via `Commerce_Order.commerce_order_id` custom field) before creating
- Use try/catch with logging, matching existing error handling patterns
- Include proper PHPDoc blocks

## Verification

After implementation, confirm:
1. Products with **both** `membership_type_id` and `financial_type_id` create a linked Contribution + Membership via `Order::create()`
2. Products with **only** `membership_type_id` still create standalone memberships via `MembershipUpdater`
3. Products with **only** `financial_type_id` still create standalone contributions via `ContributionUpdater`
4. The `Order::create()` result includes both a contribution ID and a membership ID
5. Duplicate contributions are not created for the same order
6. No syntax errors in `OrderCivicrmUpdater.php`
