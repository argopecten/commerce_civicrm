# Agent Prompt: Todo #7 — Fragile Contribution Lookup (LIKE Search)

## Status: RESOLVED — No action needed

This item was fully resolved by the implementation of **Prompt #3** (`03-custom-commerce-order-id.md`).

### What was fixed by Prompt #3

1. **LIKE search replaced with exact custom field lookup**: `findExistingContribution()` now queries `Commerce_Order.commerce_order_id` with an exact `=` match as the primary lookup
2. **Exact source fallback**: The fallback uses `->addWhere('source', '=', 'Commerce Order #' . $order_id)` (exact match, not LIKE)
3. **Automatic backfill**: When a legacy contribution is found via the source fallback, the custom field is backfilled for future lookups
4. **Source format standardized**: Both `extractContributionData()` and `createContributionFromOrderWithFinancialType()` use the same format: `'Commerce Order #' . $order->id()`
5. **Date format standardized**: `createContributionFromOrderWithFinancialType()` changed from `YmdHis` to `Y-m-d H:i:s`

See the Completed Items section in `doc/development/todo.md` for full details.
