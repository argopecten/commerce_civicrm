# Agent Prompt: Implement Todo #2 — Address Fields Written Incorrectly to Contact

## Objective

Fix address field handling in `ContactUpdater.php` so that Commerce billing address data is correctly written to CiviCRM contacts via API4. Currently, address fields are set as top-level Contact values with raw text for state/country, which CiviCRM API4 silently ignores. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `src/Service/ContactUpdater.php`, the `extractContactData()` method (L131–L171) reads Commerce address fields from the billing profile and writes them into a flat `$contact_data` array:

```php
$contact_data['street_address'] = $address['address_line1'] ?? '';
$contact_data['supplemental_address_1'] = $address['address_line2'] ?? '';
$contact_data['city'] = $address['locality'] ?? '';
$contact_data['postal_code'] = $address['postal_code'] ?? '';
$contact_data['state_province'] = $address['administrative_area'] ?? '';
$contact_data['country'] = $address['country_code'] ?? '';
```

This array is later passed to `Contact::create(FALSE)->setValues($contact_data)` (L338) or `Contact::update(FALSE)->setValues($contact_data)` (L282).

### Two problems:

1. **State/country use wrong field names and raw text values**:
   - `state_province` is not a writable Contact field — the correct field is `state_province_id` (a numeric FK). Commerce provides the abbreviation ("CA", "NY"), not an ID.
   - `country` is not a writable Contact field — the correct field is `country_id` (a numeric FK). Commerce provides the ISO-2 code ("US", "AU"), not an ID.
   - CiviCRM API4 silently ignores unknown fields, so state and country are never saved.

2. **Address fields are set on Contact, not via the `address_primary.*` join path**:
   - `street_address`, `supplemental_address_1`, `city`, `postal_code` are Address entity fields, not Contact entity fields. CiviCRM API4 may resolve them via implicit joins for backward compatibility, but this is unreliable in 6.x.
   - The correct approach targets the primary address explicitly via `address_primary.field_name`.

### Impact
Contacts are created/updated without any address data — street, city, state, country, and postal code are all silently lost.

## What to implement

### 1. Refactor `extractContactData()` to separate contact fields from address fields

The method currently returns a single flat array mixing Contact-level fields (`first_name`, `last_name`, `contact_type`, `email`) with Address-level fields. Refactor it to return a structured array that clearly separates the two:

```php
protected function extractContactData(ProfileInterface $profile, OrderInterface $order) {
  $contact_data = [];
  $address_data = [];

  if ($profile->hasField('address') && !$profile->get('address')->isEmpty()) {
    $address = $profile->get('address')->first()->getValue();

    // Contact-level fields
    $contact_data['first_name'] = $address['given_name'] ?? '';
    $contact_data['last_name'] = $address['family_name'] ?? '';

    // Address-level fields — use address_primary.* join path
    $address_data['address_primary.street_address'] = $address['address_line1'] ?? '';
    $address_data['address_primary.supplemental_address_1'] = $address['address_line2'] ?? '';
    $address_data['address_primary.city'] = $address['locality'] ?? '';
    $address_data['address_primary.postal_code'] = $address['postal_code'] ?? '';

    // State/country use pseudoconstant syntax for text-to-ID resolution
    if (!empty($address['administrative_area'])) {
      $address_data['address_primary.state_province_id:abbr'] = $address['administrative_area'];
    }
    if (!empty($address['country_code'])) {
      $address_data['address_primary.country_id:name'] = $address['country_code'];
    }
  }

  // Email from order customer
  $customer = $order->getCustomer();
  if ($customer && $customer->getEmail()) {
    $contact_data['email'] = $customer->getEmail();
  }

  $contact_data['contact_type'] = 'Individual';

  // Merge address data into contact data — API4 resolves address_primary.* joins
  $result = array_merge($contact_data, $address_data);

  $this->logger->debug('Extracted contact data from order @order_id: @contact_data', [
    '@order_id' => $order->id(),
    '@contact_data' => json_encode($result),
  ]);

  return array_filter($result);
}
```

**Key changes**:
- `state_province` → `address_primary.state_province_id:abbr` (pseudoconstant — CiviCRM resolves "CA" to the numeric ID)
- `country` → `address_primary.country_id:name` (pseudoconstant — CiviCRM resolves "US" to the numeric ID)
- `street_address` → `address_primary.street_address`
- `supplemental_address_1` → `address_primary.supplemental_address_1`
- `city` → `address_primary.city`
- `postal_code` → `address_primary.postal_code`
- `print_r($contact_data, TRUE)` → `json_encode($result)` (fixes todo #14 for this specific call site)

### 2. Update `updateExistingContact()` to handle `address_primary.*` fields correctly

Currently (L266–L305), `updateExistingContact()` does:

```php
$contact_data['id'] = $contact_id;
$result = \Civi\Api4\Contact::update(FALSE)
  ->setValues($contact_data)
  ->execute();
```

The `setValues()` call works for `address_primary.*` fields — API4 accepts them via implicit join writes. **No structural change is needed here**, but verify that:
- The `email` key is still stripped out before `setValues()` (it's handled separately via `updateContactEmail()`)
- `contact_type` is not included in updates (it can't be changed on existing contacts) — currently `extractContactData()` always sets it, which would cause an API4 error on update if passed. Add logic to strip `contact_type` when updating.

Check the current code: `updateExistingContact()` passes the full `$contact_data` (minus `email`) to `setValues()`. Since `contact_type` is always set to `'Individual'`, this will cause a warning/error on API4 updates where the contact already exists. Strip it:

```php
unset($contact_data['email']);
unset($contact_data['contact_type']); // Can't change contact_type on update
$contact_data['id'] = $contact_id;
```

### 3. Update `createNewContact()` — verify it works with `address_primary.*`

Currently (L318–L355), `createNewContact()` does:

```php
$result = \Civi\Api4\Contact::create(FALSE)
  ->setValues($contact_data)
  ->execute();
```

API4's `Contact::create` supports `address_primary.*` fields — it creates the Address record automatically. **No structural change needed.** Just confirm the email stripping still works (it currently does).

## Files to modify

**`src/Service/ContactUpdater.php`** — three changes:

1. **`extractContactData()`** (L131–L171): Replace the six address field assignments with `address_primary.*` join paths and pseudoconstant syntax for state/country. Also replace `print_r()` with `json_encode()`.

2. **`updateExistingContact()`** (L266–L305): Add `unset($contact_data['contact_type'])` alongside the existing `unset($contact_data['email'])`.

3. **`createNewContact()`** (L318–L355): No changes expected, but verify `address_primary.*` fields pass through correctly with `setValues()`.

## Files NOT to modify

- `commerce_civicrm.module` — no changes
- `commerce_civicrm.services.yml` — no changes
- `commerce_civicrm.install` — no update hooks
- Other service files — not affected

## Commerce address field mapping reference

Commerce address (from `address` module) → CiviCRM API4 field:

| Commerce field key | Commerce example | CiviCRM API4 target | Notes |
|---|---|---|---|
| `given_name` | `"Jane"` | `first_name` | Contact-level |
| `family_name` | `"Doe"` | `last_name` | Contact-level |
| `address_line1` | `"123 Main St"` | `address_primary.street_address` | Address join |
| `address_line2` | `"Suite 4"` | `address_primary.supplemental_address_1` | Address join |
| `locality` | `"San Francisco"` | `address_primary.city` | Address join |
| `postal_code` | `"94102"` | `address_primary.postal_code` | Address join |
| `administrative_area` | `"CA"` | `address_primary.state_province_id:abbr` | Pseudoconstant |
| `country_code` | `"US"` | `address_primary.country_id:name` | Pseudoconstant |

## CiviCRM API4 pseudoconstant syntax

Pseudoconstants allow writing human-readable values that CiviCRM resolves to numeric IDs internally:

```php
// Pseudoconstant syntax — CiviCRM resolves "CA" → state_province_id 1004 (California)
->addValue('address_primary.state_province_id:abbr', 'CA')

// Pseudoconstant syntax — CiviCRM resolves "US" → country_id 1228 (United States)
->addValue('address_primary.country_id:name', 'US')
```

When using `setValues()` with an array, pseudoconstant keys work as array keys:

```php
$data = [
  'address_primary.state_province_id:abbr' => 'CA',
  'address_primary.country_id:name' => 'US',
];
Contact::create(FALSE)->setValues($data)->execute();
```

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use OOP API4 style with `checkPermissions: FALSE` (matching existing `ContactUpdater` convention)
- All address fields must use the `address_primary.*` join path
- State and country must use pseudoconstant syntax (`:abbr` and `:name` respectively)
- Maintain existing PHPDoc blocks and update them if return structure changes
- Keep the existing email handling flow (stripped from `$contact_data`, handled by `updateContactEmail()`)
- Use `json_encode()` instead of `print_r()` for debug logging at modified call sites

## Verification

After implementation, confirm:
1. `ContactUpdater.php` has no syntax errors
2. `extractContactData()` returns address fields keyed with `address_primary.*` prefix
3. State uses `address_primary.state_province_id:abbr` (not `state_province`)
4. Country uses `address_primary.country_id:name` (not `country`)
5. `updateExistingContact()` strips both `email` and `contact_type` before calling `setValues()`
6. `createNewContact()` passes `address_primary.*` fields through correctly
7. No references to the old flat field names (`street_address`, `supplemental_address_1`, `city`, `postal_code`, `state_province`, `country`) remain as top-level Contact data keys — they should all be prefixed with `address_primary.`
