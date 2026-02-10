# Agent Prompt: Implement Todo #8 — Contact Dedup by First+Last Name Only

## Objective

Fix the unsafe name-only contact deduplication fallback in `ContactUpdater::findExistingContact()` that matches contacts by `first_name` + `last_name` alone — without considering `contact_type`, email, or any other distinguishing field. Replace it with CiviCRM's built-in dedupe rules or a more restrictive query. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

In `src/Service/ContactUpdater.php`, `findExistingContact()` (around L226–L250) has a two-tier lookup:

1. **Primary**: search by email via `\Civi\Api4\Email::get()` → returns `contact_id` — good
2. **Fallback**: match by `first_name` + `last_name` only — dangerous

The fallback code:

```php
// If no email match, try by first and last name
if (!empty($contact_data['first_name']) && !empty($contact_data['last_name'])) {
    $result = \Civi\Api4\Contact::get(FALSE)
        ->addSelect('id')
        ->addWhere('first_name', '=', $contact_data['first_name'])
        ->addWhere('last_name', '=', $contact_data['last_name'])
        ->setLimit(1)
        ->execute();
    
    if ($result->count() > 0) {
        $this->logger->info('Found existing CiviCRM contact by name: @contact_id', [
            '@contact_id' => $result->first()['id'],
        ]);
        return $result->first()['id'];
    }
}
```

Issues:
- Common names like "John Smith" will match the wrong contact
- No `contact_type` filter — the query could match Organizations or Households
- `setLimit(1)` picks an arbitrary match when multiple contacts share the same name
- No consideration of email, phone, address, or any other distinguishing data

## What to implement

### Option A: Use CiviCRM's built-in dedupe rules (Recommended)

CiviCRM has a sophisticated deduplication system with configurable rules. Use the `Contact.getDuplicates` or the dedupe API to find potential matches:

```php
// Replace the name-only fallback with CiviCRM's dedupe system
if (!empty($contact_data['first_name']) && !empty($contact_data['last_name'])) {
    try {
        $dedupeParams = [
            'contact_type' => 'Individual',
            'match' => [
                'first_name' => $contact_data['first_name'],
                'last_name' => $contact_data['last_name'],
            ],
        ];

        // Add email to dedupe if available (increases accuracy)
        if (!empty($contact_data['email'])) {
            $dedupeParams['match']['email'] = $contact_data['email'];
        }

        $result = \Civi\Api4\Contact::getDuplicates(FALSE)
            ->setValues($dedupeParams['match'])
            ->setDedupeRule('Individual.Supervised')
            ->execute();

        if ($result->count() > 0) {
            $contact_id = $result->first()['id'];
            $this->logger->info('Found duplicate CiviCRM contact via dedupe rules: @contact_id', [
                '@contact_id' => $contact_id,
            ]);
            return $contact_id;
        }
    } catch (\Exception $e) {
        $this->logger->warning('CiviCRM dedupe check failed, skipping name fallback: @error', [
            '@error' => $e->getMessage(),
        ]);
    }
}
```

Note: The `Contact.getDuplicates` API may not be available in all CiviCRM versions. If it's not available, fall back to Option B.

### Option B: Restrict the name query (Simpler alternative)

If the built-in dedupe API is unavailable or unreliable, make the name-based fallback safer by:

1. Adding `contact_type` filter (only match `Individual`)
2. Adding email as an additional clause if available
3. Only accepting a match if exactly one result is found (reject ambiguous matches)

```php
if (!empty($contact_data['first_name']) && !empty($contact_data['last_name'])) {
    $query = \Civi\Api4\Contact::get(FALSE)
        ->addSelect('id')
        ->addWhere('contact_type', '=', 'Individual')
        ->addWhere('first_name', '=', $contact_data['first_name'])
        ->addWhere('last_name', '=', $contact_data['last_name']);

    // If we have an email, add it to narrow the match
    if (!empty($contact_data['email'])) {
        $query->addWhere('email_primary.email', '=', $contact_data['email']);
    }

    $result = $query->setLimit(2)->execute(); // Fetch 2 to detect ambiguity

    if ($result->count() === 1) {
        // Exactly one match — safe to use
        $this->logger->info('Found unique CiviCRM contact by name: @contact_id', [
            '@contact_id' => $result->first()['id'],
        ]);
        return $result->first()['id'];
    } elseif ($result->count() > 1) {
        // Ambiguous — do NOT pick one arbitrarily
        $this->logger->warning('Multiple CiviCRM contacts match name @first @last — creating new contact to avoid merge error', [
            '@first' => $contact_data['first_name'],
            '@last' => $contact_data['last_name'],
        ]);
        return NULL;
    }
}
```

### Implementation preference

Try Option A first. If `Contact.getDuplicates` is not available in CiviCRM 6.x or causes issues, implement Option B. Either approach is a significant improvement over the current name-only `setLimit(1)` query.

## Files to modify

1. **`src/Service/ContactUpdater.php`** — Replace the name-only fallback in `findExistingContact()` (around L226–L250)

## Files NOT to modify

- All other files — this is a single-file fix
- `commerce_civicrm.services.yml` — no changes needed
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- The email-based primary lookup (via `\Civi\Api4\Email::get()`) must remain unchanged — it works correctly
- Use OOP API4 style (matching existing code)
- Always filter by `contact_type = 'Individual'` in name-based queries
- Never pick an arbitrary contact when multiple matches exist
- Log a clear warning when the name fallback is skipped due to ambiguity
- Include proper PHPDoc or inline comments explaining the dedup logic

## Verification

After implementation, confirm:
1. The name-only fallback no longer matches without `contact_type` filter
2. Ambiguous matches (multiple contacts with same name) do NOT return an arbitrary contact
3. The email-based primary lookup is unchanged
4. A warning is logged when the name fallback is skipped
5. No syntax errors in `ContactUpdater.php`
