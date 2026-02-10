# Agent Prompt: Implement Todo #10 — Extract Procedural Form Alter to Service

## Objective

Extract the ~430-line procedural `hook_form_alter` implementation and its supporting helper functions from `commerce_civicrm.module` into a dedicated `ProductFormHelper` service class. The `.module` file should contain only the hook stub that delegates to the service. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `commerce_civicrm.module`, the function `commerce_civicrm_form_commerce_product_form_alter()` (L92–L229) contains ~140 lines of form build logic, and its companion `commerce_civicrm_product_form_submit()` (L235–L290) adds another ~55 lines. On top of that, 7 procedural helper functions (L308–L523) support the form:

| Function | Lines | Purpose |
|----------|-------|---------|
| `_commerce_civicrm_is_available()` | L308–L317 | Check CiviCRM availability |
| `_commerce_civicrm_get_product_settings()` | L328–L349 | Read product JSON settings |
| `_commerce_civicrm_get_membership_types()` | L351–L370 | Fetch membership types |
| `_commerce_civicrm_get_financial_types()` | L372–L390 | Fetch financial types |
| `_commerce_civicrm_get_events()` | ~L392–L410 | Fetch events |
| `_commerce_civicrm_get_participant_roles()` | ~L412–L430 | Fetch participant roles |
| `_commerce_civicrm_get_mailing_groups()` | ~L432–L450 | Fetch mailing groups |

This is a D7-era pattern. In Drupal 11+, form logic belongs in service classes, keeping the `.module` file minimal.

## What to implement

### 1. Create `src/Service/ProductFormHelper.php`

New service class that absorbs all form logic:

```php
<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for handling CiviCRM integration on product edit forms.
 */
class ProductFormHelper {

    use StringTranslationTrait;

    // Inject:
    // - LoggerChannelFactoryInterface (for logging)
    // - CivicrmHelper (for availability check + initialize)
    // - MembershipUpdater (for getMembershipTypes())
    // - ContactUpdater (for getFinancialTypes(), getEvents(), getParticipantRoles(), getMailingGroups())

    /**
     * Alters the product form to add CiviCRM settings.
     *
     * @param array &$form
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     * @param string $form_id
     */
    public function alterProductForm(array &$form, FormStateInterface $form_state, string $form_id): void {
        // Move the entire body of commerce_civicrm_form_commerce_product_form_alter() here
        // Replace _commerce_civicrm_is_available() with $this->civicrmHelper->isAvailable()
        // Replace _commerce_civicrm_get_product_settings($product) with $this->getProductSettings($product)
        // Replace _commerce_civicrm_get_membership_types() with $this->membershipUpdater->getMembershipTypes()
        // Replace _commerce_civicrm_get_financial_types() with $this->contactUpdater->getFinancialTypes()
        // Replace _commerce_civicrm_get_events() with $this->contactUpdater->getEvents()
        // Replace _commerce_civicrm_get_participant_roles() with $this->contactUpdater->getParticipantRoles()
        // Replace _commerce_civicrm_get_mailing_groups() with $this->contactUpdater->getMailingGroups()
        // Replace t() with $this->t()
        // Reference $this->submitProductForm() as the submit handler
    }

    /**
     * Submit handler for the product form.
     */
    public function submitProductForm(array &$form, FormStateInterface $form_state): void {
        // Move body of commerce_civicrm_product_form_submit() here
        // Replace t() with $this->t()
    }

    /**
     * Gets CiviCRM settings for a product.
     */
    public function getProductSettings(ProductInterface $product): array {
        // Move body of _commerce_civicrm_get_product_settings() here
    }
}
```

### 2. Register the service in `commerce_civicrm.services.yml`

Add the new service with its dependencies:

```yaml
commerce_civicrm.product_form_helper:
    class: Drupal\commerce_civicrm\Service\ProductFormHelper
    arguments:
        - '@logger.factory'
        - '@commerce_civicrm.civicrm_helper'
        - '@commerce_civicrm.membership_updater'
        - '@commerce_civicrm.contact_updater'
```

### 3. Reduce `commerce_civicrm.module` to a thin hook stub

The `hook_form_alter` in `.module` becomes:

```php
function commerce_civicrm_form_commerce_product_form_alter(&$form, FormStateInterface $form_state, $form_id) {
    \Drupal::service('commerce_civicrm.product_form_helper')
        ->alterProductForm($form, $form_state, $form_id);
}
```

### 4. Handle the submit callback

The form submit handler is registered as a string callback: `'commerce_civicrm_product_form_submit'`. Since it's a procedural function name, Drupal resolves it from the `.module` file. Two options:

**Option A (simpler)**: Keep a thin procedural wrapper in `.module`:
```php
function commerce_civicrm_product_form_submit(&$form, FormStateInterface $form_state) {
    \Drupal::service('commerce_civicrm.product_form_helper')
        ->submitProductForm($form, $form_state);
}
```

**Option B (cleaner)**: Register the submit handler as a service callback:
```php
// In alterProductForm():
$form['actions']['submit']['#submit'][] = [$this, 'submitProductForm'];
// But this only works if the form system can resolve the service method
```

Option A is recommended — it's simpler and consistent with how hooks work in Drupal.

### 5. Remove or keep procedural helpers

The `_` prefixed helper functions are now absorbed into the service or delegate to existing service methods. They can be:
- **Removed entirely** (breaking change — but allowed) if no other code calls them
- **Kept as thin wrappers** that delegate to the service (for backward compat)

Since breaking changes are allowed, remove them. The functions `_commerce_civicrm_get_events()`, `_commerce_civicrm_get_participant_roles()`, and `_commerce_civicrm_get_mailing_groups()` were just added by todo #1 — they can be removed since the service now calls the updater services directly.

Keep `_commerce_civicrm_is_available()` if it's used by `hook_help()` or other hooks in the module. Check for usages before removing.

### 6. What stays in `.module`

After extraction, the `.module` file should contain only:
- `hook_help()` (L18–L85)
- `hook_form_alter` stub (thin delegation)
- `commerce_civicrm_product_form_submit()` stub (thin delegation)
- `hook_commerce_product_type_insert()` (L396–L405) — this could also move to an event subscriber, but keep it in `.module` for this task
- `commerce_civicrm_add_field_to_product_type()` and `commerce_civicrm_add_field_to_all_product_types()` — field provisioning functions. Keep for now.
- `_commerce_civicrm_is_available()` — if used by other hooks; otherwise remove

## Files to modify

1. **Create `src/Service/ProductFormHelper.php`** — New service with all form logic
2. **`commerce_civicrm.services.yml`** — Register `commerce_civicrm.product_form_helper`
3. **`commerce_civicrm.module`** — Replace form alter/submit bodies with thin delegations; remove absorbed helper functions

## Files NOT to modify

- `src/Service/ContactUpdater.php` — its public methods are called by the new service
- `src/Service/MembershipUpdater.php` — its `getMembershipTypes()` is called by the new service
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use `StringTranslationTrait` in the service class (replace global `t()` with `$this->t()`)
- Use dependency injection in the service constructor — do NOT use `\Drupal::service()` inside the service class
- The `.module` file's hook stubs may use `\Drupal::service()` (that's standard for procedural hooks)
- Keep the form element names/keys identical (`civicrm[enabled]`, `civicrm[entity]`, etc.) so existing saved JSON settings remain compatible
- Include proper PHPDoc on the new class and all public methods
- The service should be injectable (other code can use it via DI)

## Verification

After implementation, confirm:
1. `src/Service/ProductFormHelper.php` exists with `alterProductForm()` and `submitProductForm()` methods
2. `commerce_civicrm.services.yml` registers `commerce_civicrm.product_form_helper`
3. `commerce_civicrm.module`'s form alter is a thin stub (~3 lines)
4. `commerce_civicrm.module`'s submit handler is a thin stub (~3 lines)
5. The `_commerce_civicrm_get_*` helper functions that are no longer needed are removed
6. No `t()` calls exist in the service class — all use `$this->t()`
7. No `\Drupal::service()` calls exist in the service class — all use injected dependencies
8. No syntax errors in any modified file
