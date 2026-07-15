# Testing

## Unit tests

`tests/src/Unit/` contains pure PHPUnit unit tests (no Drupal kernel, no
CiviCRM needed):

| Test class | Covers |
|---|---|
| `AmountSplitterTest` | `Util\AmountSplitter::splitProportionally()` — proportional splits, rounding remainder on the last part, zero-weight even split, exact-sum invariant |
| `OrderCompleteSubscriberTest` | `OrderCompleteSubscriber::onTransition()` — config-routed create/cancel transition matching and the `order.workflows` allowlist |

### Running them

From a Drupal site root that has the module installed (dev dependencies of
the site provide PHPUnit):

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/contrib/commerce_civicrm/tests/src/Unit
```

Expected: all tests pass (`OK`); PHPUnit deprecation notices from core's
config are harmless.

### Gaps

There are no kernel or functional tests yet. The order pipeline
(`OrderCivicrmUpdater::processOrder()` / `createCiviOrder()`) and the
renewal flow are verified manually against a real CiviCRM instance — the
Order/Payment API interplay (Pending → Payment → Completed, membership
renewal via line-item `entity_id`) is CiviCRM behaviour that mocks would not
prove anything about. See [todo.md](todo.md).

---

## Manual testing checklist

### Prerequisites

- Drupal 11+ with Commerce 3+, CiviCRM ≥ 6.16, Profile, State Machine.
- At least one Commerce product type with the `field_civicrm` field
  (auto-added on install).
- CiviCRM bootstraps successfully (`drush civicrm:status` or `/civicrm`).
- `commerce_civicrm.settings` transitions match your order workflow — see
  [Configuration](../user-guide/configuration.md).

### 1. Verify module installation

```bash
drush en commerce_civicrm
drush cr
drush pm:list --filter="commerce_civicrm"
```

Check `admin/reports/status` — the "Commerce CiviCRM" row should show
"Available". In CiviCRM, the *Commerce Order* custom group (fields
`commerce_order_id`, `commerce_payment_id`) should exist on Contribution.

### 2. Verify services are registered

Requires the [Devel](https://www.drupal.org/project/devel) module:

```bash
drush devel:services | grep commerce_civicrm
```

Expected output (10 services):

```
commerce_civicrm.civicrm_helper
commerce_civicrm.contact_updater
commerce_civicrm.contribution_updater
commerce_civicrm.mailing_updater
commerce_civicrm.membership_updater
commerce_civicrm.order_civicrm_updater
commerce_civicrm.order_complete_subscriber
commerce_civicrm.participant_updater
commerce_civicrm.product_form_helper
commerce_civicrm.renewal_processor
```

### 3. Configure a test product

1. Edit a Commerce product.
2. In the "CiviCRM Integration" section, check **Enable CiviCRM Processing**.
3. Select the entity type and its type reference (membership type, financial
   type, event + role, or mailing group).
4. Save the product.
5. Verify `field_civicrm` contains the expected JSON (type references by
   name):
   `drush sqlq "SELECT field_civicrm_value FROM commerce_product__field_civicrm LIMIT 5"`

### 4. Order → contribution (any financial product)

1. Add the product to the cart, complete checkout, and drive the order
   through a configured create transition.
2. Watch the `commerce_civicrm` log channel.
3. In CiviCRM, verify **one** contribution for the order:
   - status `Completed` when the order was paid (a Payment record exists),
     `Pending` otherwise;
   - `source` = `Commerce Order #<id>`, custom field
     `Commerce_Order.commerce_order_id` set;
   - `trxn_id` and payment instrument matching the Commerce payment/gateway;
   - one line item per CiviCRM-enabled order item.

### 5. Order → membership

Same flow with a membership product. Verify in CiviCRM:

- A membership of the configured type linked to the contribution via its
  line item, status current, dates per the configured
  `membership.date_mode`.
- A second order for the same membership type **renews** the existing
  membership (New/Current/Grace) instead of creating a duplicate.

### 6. Order → event participant

Same flow with an event product. Verify a `Registered` participant on the
configured event, linked to the contribution via a `civicrm_participant`
line item.

### 7. Order → mailing subscription

Same flow with a mailing product. Verify the contact's group membership:
status `Added` (or `Pending` with double opt-in), source
`Commerce Order #<id>`.

### 8. Idempotency / replay

Reprocess an already-processed order:

```bash
drush commerce-civicrm:process-order <order_id>
```

Expected: `Contribution already exists for order … - skipping` in the log,
no new records.

### 9. Cancellation

Drive the order through a configured cancel transition. Verify in CiviCRM:

- Contribution(s) linked to the order → status `Cancelled`.
- Memberships/participants created through their line items → `Cancelled`.
- Mailing group membership → `Removed`.

### 10. Renewal payment

For a site that records recurring charges via
`RenewalProcessor::recordRenewalPayment()`: add a second completed payment
to a processed order and trigger the site's renewal call. Verify:

- A **new** contribution with `Commerce_Order.commerce_payment_id` set and
  amounts summing exactly to the charge.
- The existing membership's `end_date` extended (not a second membership).
- Repeating the call for the same payment creates nothing.

### 11. CiviCRM unavailable

Put CiviCRM in maintenance mode (or break its availability):

- Place an order: checkout must complete normally; the module logs
  `CiviCRM is not available - skipping order … processing` and creates
  nothing.
- Restore CiviCRM and replay the order with
  `drush commerce-civicrm:process-order` — records are created now.

### 12. New product type auto-field

1. Create a new Commerce product type via the admin UI.
2. Edit a product of the new type — the CiviCRM Integration section should
   appear.

---

## Debugging tips

| What to check | How |
|---|---|
| Service definitions | `drush devel:services \| grep commerce_civicrm` (requires `drupal/devel`) |
| Event subscriber registration | `drush devel:event commerce_order.post_transition` (requires `drupal/devel`) |
| Effective settings | `drush config:get commerce_civicrm.settings` |
| CiviCRM availability | `drush eval "var_dump(\Drupal::service('commerce_civicrm.civicrm_helper')->isAvailable());"` |
| Product field JSON | `drush sqlq "SELECT entity_id, field_civicrm_value FROM commerce_product__field_civicrm"` |
| Replay an order | `drush commerce-civicrm:process-order <id>` |
| Recent log messages | `drush ws --count=50 --filter=commerce_civicrm` (dblog) or grep your syslog |
