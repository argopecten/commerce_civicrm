# Configuration

All module behaviour that is not product-specific lives in the
`commerce_civicrm.settings` config object. There is no admin UI for it —
manage it with `drush config:set`, `drush config:edit commerce_civicrm.settings`,
or your config sync workflow.

Defaults (installed with the module):

```yaml
order:
  create_transitions:
    - place
  cancel_transitions:
    - cancel
  workflows: []
contact:
  fallback: none
contribution:
  payment_instrument_map:
    manual: Cash
    paypal: PayPal
  payment_instrument_default: Credit Card
membership:
  date_mode: civicrm
```

## order — which transitions trigger processing

| Key | Meaning |
|---|---|
| `create_transitions` | Order workflow **transition IDs** that create CiviCRM records (`OrderCivicrmUpdater::processOrder()`) |
| `cancel_transitions` | Transition IDs that cancel them (`processCancellation()`) |
| `workflows` | Optional allowlist of workflow IDs; empty = all workflows |

The subscriber listens to every order workflow transition and compares the
transition ID against these lists, so **custom workflows work without code** —
just list their transition IDs.

Processing is idempotent per order, so listing several create transitions
(e.g. both `paid` and `completed`) is safe and recommended for workflows
where a single order save chains multiple transitions — Drupal's state
machine only fires an event for the last one.

```bash
drush config:set commerce_civicrm.settings order.create_transitions.0 paid
drush config:set commerce_civicrm.settings order.create_transitions.1 completed
```

## contact — resolving the CiviCRM contact

| Value | Behaviour |
|---|---|
| `none` (default) | Only customers already linked to a CiviCRM contact (UFMatch) are processed; orders of unlinked users are skipped with a warning. |
| `match_or_create` | When the customer has no UFMatch, the module matches an existing contact by the order's e-mail, then by CiviCRM's `Individual.Supervised` dedupe rule, then by a strict name+e-mail lookup — and as a last resort creates a new Individual from the billing profile. |

Use `match_or_create` on sites where webshop customers are not (or not yet)
synchronised to CiviCRM users. Ambiguous matches (several candidate contacts)
are skipped rather than guessed.

## contribution — payment instruments

Maps Commerce payment gateways to CiviCRM payment instrument **names**
(option group `payment_instrument`):

- keys are matched against the payment gateway **config entity ID** first
  (e.g. `barion`, `bank_transfer`), then against the gateway **plugin ID**
  (e.g. `manual`);
- unmatched gateways fall back to `payment_instrument_default`.

```yaml
contribution:
  payment_instrument_map:
    bank_transfer: EFT
    barion: Credit Card
    manual: Cash
  payment_instrument_default: Credit Card
```

The gateway's remote transaction ID is stored as the contribution's
`trxn_id` in either case.

## membership — who computes membership dates

| Value | Behaviour |
|---|---|
| `civicrm` (default) | New memberships start today; CiviCRM computes `end_date` from the membership type (rolling/fixed periods, rollover). Renewals pass no dates so CiviCRM's renewal logic extends the membership by one term. |
| `dispatch` | The module fires `MembershipDatesEvent` and site code supplies authoritative `join_date` / `start_date` / `end_date` (e.g. from a Drupal-side subscription expiry). Dates the subscriber leaves NULL fall back to CiviCRM's logic. |

`dispatch` is for sites where the subscription expiry is owned by Drupal and
CiviCRM memberships must mirror it exactly — the module also re-asserts the
dispatched dates after payment completion, which would otherwise extend the
membership by a full term. Details for developers:
[Hooks & Events](../development/hooks-and-events.md).

## Checking the active configuration

```bash
drush config:get commerce_civicrm.settings
```

## Next Steps

- [Product Configuration](product-configuration.md) — per-product CiviCRM behaviour
- [Order Processing](order-processing.md) — what happens on each transition
