# Testing

## Automated tests

The module does not currently include automated tests. The `composer.json` declares `phpunit/phpunit` as a dev dependency and reserves the `Drupal\Tests\commerce_civicrm\` namespace — but no test classes exist yet.

Adding at least kernel-level tests is recommended. See [todo.md](todo.md).

---

## Manual testing checklist

### Prerequisites

- Drupal 11+ with Commerce 3+, CiviCRM, Profile, State Machine installed.
- Drush 13.7+ (`composer require drush/drush:^13.7`).
- At least one Commerce product type with the `field_civicrm` field (auto-added on install).
- CiviCRM bootstraps successfully (`drush civicrm:status` or `/civicrm`).

### 1. Verify module installation

```bash
drush en commerce_civicrm
drush cr
drush pm:list --filter="commerce_civicrm"
```

Check `admin/reports/status` — the "Commerce CiviCRM" row should show "Available".

### 2. Verify services are registered

Requires the [Devel](https://www.drupal.org/project/devel) module (`composer require --dev drupal/devel`):

```bash
drush devel:services | grep commerce_civicrm
```

Expected output (8 services):

```
commerce_civicrm.civicrm_helper
commerce_civicrm.contact_updater
commerce_civicrm.contribution_updater
commerce_civicrm.mailing_updater
commerce_civicrm.membership_updater
commerce_civicrm.order_civicrm_updater
commerce_civicrm.order_complete_subscriber
commerce_civicrm.product_form_helper
```

### 3. Configure a test product

1. Edit a Commerce product.
2. In the "CiviCRM Integration" sidebar, check **Enable CiviCRM Processing**.
3. Select entity type:
   - **Membership** — pick a membership type.
   - **Contribution** — pick a financial type.
4. Save the product.
5. Verify `field_civicrm` contains valid JSON: `drush sqlq "SELECT field_civicrm_value FROM commerce_product__field_civicrm LIMIT 5"`

### 4. Test order → CiviCRM (membership)

1. Add the membership product to cart.
2. Complete checkout.
3. Watch logs in real time: `drush ws --tail --filter="commerce_civicrm"`.
4. In CiviCRM, verify:
   - A Contact exists matching the billing profile.
   - A Membership record exists with the expected type, dates, and source.

### 5. Test order → CiviCRM (contribution)

Same flow as above but with a contribution-type product. Verify:

- Contribution exists in CiviCRM with correct amount, currency, financial type, and status `Completed`.
- The `source` field references the Drupal Commerce order ID.
- The payment instrument matches the gateway used.

### 6. Test duplicate prevention

Place a second order for the same membership type with the same user:

- The existing membership should be **updated/renewed**, not duplicated.
- For contributions, a new contribution should be created (one per order), but not duplicated if the same order is processed again.

### 7. Test cancellation

Cancel a completed order (if the workflow supports it):

- Check logs for info messages about membership and contribution cancellations.
- In CiviCRM, verify:
  - Membership status changed to `Cancelled`.
  - Contribution status changed to `Cancelled`.
- Each cancellation is independent — one failure should not block others.

### 8. Test with CiviCRM unavailable

Disable the `civicrm` module or break the CiviCRM database connection:

- Place an order.
- The module should log an error and **not** crash the checkout flow.
- No CiviCRM records should be created.
- Re-enable CiviCRM — new orders should process normally again.

### 9. Test new product type auto-field

1. Create a new Commerce product type via admin UI.
2. Create a product of the new type.
3. Edit the product — the CiviCRM Integration details group should appear.

---

## Debugging tips

| What to check | How |
|---|---|
| Service definitions | `drush devel:services \| grep commerce_civicrm` (requires `drupal/devel`) |
| Event subscriber registration | `drush devel:event commerce_order.place.post_transition` (requires `drupal/devel`) |
| CiviCRM availability | `drush eval "\Drupal::service('commerce_civicrm.civicrm_helper')->isAvailable();"` |
| Product field JSON | `drush sqlq "SELECT entity_id, field_civicrm_value FROM commerce_product__field_civicrm"` |
| Recent log messages | `drush ws --count=50 --filter=commerce_civicrm` |
| All errors | `drush ws --severity=3 --filter=commerce_civicrm` |
