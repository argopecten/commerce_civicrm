# Agent Prompt: Implement Todo #13 — Mailing List Stubs (Double Opt-in / Welcome)

## Objective

Create the `MailingUpdater` service class with working mailing group subscription functionality, including stub implementations for double opt-in emails and welcome messages. The service is documented but the file does not exist yet. Breaking changes are allowed. No update hooks are needed.

## Repository

`/Users/petergerner/Documents/GitHub/commerce_civicrm/`

## Problem

The todo references `src/Service/MailingUpdater.php` with stub methods at L279 and L314 — but the file **does not exist**. Documentation in `doc/services/mailing-updater.md` describes a fully planned service with API methods, but no code was ever created. Meanwhile:

- The product form already has a `mailing` entity type option with `mailing_group_id` and `mailing_preferences` fields (see `commerce_civicrm.module` form alter)
- `OrderCivicrmUpdater::processOrderItem()` currently only handles `membership` and `contribution` entity types — it has no `mailing` case
- `ContactUpdater` already has a `getMailingGroups()` method that fetches available groups via API4

Two specific stubs are called out:
1. **`sendDoubleOptInEmail()`** — should send a confirmation email with a token for opt-in verification
2. **`sendWelcomeMessage()`** — should send a welcome message after subscription

## What to implement

### 1. Create `src/Service/MailingUpdater.php`

Follow the same pattern as `ContactUpdater` and `ContributionUpdater`:

```php
<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;

/**
 * Service for managing CiviCRM mailing group subscriptions.
 */
class MailingUpdater {

    // Dependencies: EntityTypeManagerInterface, LoggerChannelFactoryInterface, CivicrmHelper

    /**
     * Adds a contact to a CiviCRM mailing group.
     *
     * @param int $contact_id
     * @param int $mailing_group_id
     * @param array $preferences
     *   Optional preferences: 'double_opt_in', 'send_welcome', 'update_existing'.
     *
     * @return bool
     */
    public function addContactToMailingGroup(int $contact_id, int $mailing_group_id, array $preferences = []): bool {
        // 1. Initialize CiviCRM
        // 2. Check if contact is already in the group via checkGroupMembership()
        // 3. If already a member and 'update_existing' not set, return TRUE
        // 4. Determine status: 'Pending' if double_opt_in, else 'Added'
        // 5. Use \Civi\Api4\GroupContact::create(FALSE) or ::save(FALSE)
        //    ->addValue('contact_id', $contact_id)
        //    ->addValue('group_id', $mailing_group_id)
        //    ->addValue('status', $status)
        //    ->execute()
        // 6. If double_opt_in, call sendDoubleOptInEmail()
        // 7. If send_welcome and status is 'Added', call sendWelcomeMessage()
        // 8. Log and return TRUE on success
    }

    /**
     * Removes a contact from a mailing group.
     */
    public function removeContactFromMailingGroup(int $contact_id, int $mailing_group_id): bool {
        // Use \Civi\Api4\GroupContact::update(FALSE) to set status = 'Removed'
        // or \Civi\Api4\GroupContact::delete(FALSE) if appropriate
    }

    /**
     * Processes a mailing subscription from an order.
     *
     * Called by OrderCivicrmUpdater when processing an order item
     * with entity type 'mailing'.
     */
    public function processMailingSubscriptionFromOrder(int $contact_id, int $mailing_group_id, OrderInterface $order, array $preferences = []): bool {
        // Set source preference from order
        // Delegate to addContactToMailingGroup()
    }

    /**
     * Checks if a contact is already in a group.
     */
    public function checkGroupMembership(int $contact_id, int $mailing_group_id): ?array {
        // Query \Civi\Api4\GroupContact::get(FALSE)
        //   ->addWhere('contact_id', '=', $contact_id)
        //   ->addWhere('group_id', '=', $mailing_group_id)
        //   ->setLimit(1)
        //   ->execute()
        // Return the record or NULL
    }

    /**
     * Sends a double opt-in confirmation email.
     *
     * @todo Implement full double opt-in flow: token generation, Drupal route
     *   for confirmation, token validation, status update.
     */
    protected function sendDoubleOptInEmail(int $contact_id, int $mailing_group_id): void {
        // STUB: Log the action, note it needs implementation
        $this->logger->info('Double opt-in email requested for contact @contact_id, group @group_id — not yet implemented', [
            '@contact_id' => $contact_id,
            '@group_id' => $mailing_group_id,
        ]);
    }

    /**
     * Sends a welcome message after subscription.
     *
     * @todo Implement via CiviCRM MessageTemplate API.
     */
    protected function sendWelcomeMessage(int $contact_id, int $mailing_group_id): void {
        // STUB: Log the action, note it needs implementation
        $this->logger->info('Welcome message requested for contact @contact_id, group @group_id — not yet implemented', [
            '@contact_id' => $contact_id,
            '@group_id' => $mailing_group_id,
        ]);
    }
}
```

### 2. Register the service in `commerce_civicrm.services.yml`

Add after the existing services:

```yaml
commerce_civicrm.mailing_updater:
    class: Drupal\commerce_civicrm\Service\MailingUpdater
    arguments:
        - '@entity_type.manager'
        - '@logger.factory'
        - '@commerce_civicrm.civicrm_helper'
```

### 3. Wire into `OrderCivicrmUpdater`

Add `MailingUpdater` as a dependency of `OrderCivicrmUpdater`:

**In `commerce_civicrm.services.yml`:**
```yaml
commerce_civicrm.order_civicrm_updater:
    arguments:
        - '@logger.factory'
        - '@commerce_civicrm.civicrm_helper'
        - '@commerce_civicrm.contact_updater'
        - '@commerce_civicrm.contribution_updater'
        - '@commerce_civicrm.membership_updater'
        - '@commerce_civicrm.mailing_updater'  # NEW
```

**In `OrderCivicrmUpdater.php`:**
- Add `MailingUpdater` constructor parameter and property
- Add a `mailing` case in `processOrderItem()` after the contribution case:

```php
// Check for mailing group configuration
if ($civicrm_settings['entity'] === 'mailing' && !empty($civicrm_settings['entity_id'])) {
    $mailing_group_id = $civicrm_settings['entity_id'];
    $preferences = $civicrm_settings['mailing_preferences'] ?? [];
    $success = $this->mailingUpdater->processMailingSubscriptionFromOrder(
        $contact_id, $mailing_group_id, $order, $preferences
    );
    if ($success) {
        $created_records['mailings'][] = $mailing_group_id;
    }
}
```

### 4. CiviCRM API4 entities to use

- `\Civi\Api4\GroupContact` — for adding/removing contacts from groups
- `\Civi\Api4\Group` — for querying group details (already used in `ContactUpdater::getMailingGroups()`)

Key `GroupContact` operations:
```php
// Add contact to group
\Civi\Api4\GroupContact::create(FALSE)
    ->addValue('contact_id', $contact_id)
    ->addValue('group_id', $group_id)
    ->addValue('status', 'Added')  // or 'Pending' for double opt-in
    ->execute();

// Check membership
\Civi\Api4\GroupContact::get(FALSE)
    ->addWhere('contact_id', '=', $contact_id)
    ->addWhere('group_id', '=', $group_id)
    ->setLimit(1)
    ->execute();

// Remove (set status to Removed)
\Civi\Api4\GroupContact::update(FALSE)
    ->addWhere('contact_id', '=', $contact_id)
    ->addWhere('group_id', '=', $group_id)
    ->addValue('status', 'Removed')
    ->execute();
```

## Files to create

1. **`src/Service/MailingUpdater.php`** — New service class

## Files to modify

2. **`commerce_civicrm.services.yml`** — Register `commerce_civicrm.mailing_updater`, add it to `order_civicrm_updater` arguments
3. **`src/Service/OrderCivicrmUpdater.php`** — Add `MailingUpdater` dependency, add `mailing` case to `processOrderItem()`

## Files NOT to modify

- `src/Service/ContactUpdater.php` — `getMailingGroups()` already exists and works
- `commerce_civicrm.module` — form already supports `mailing` entity type
- `commerce_civicrm.install` — no update hooks

## Constraints

- **Breaking changes are allowed**
- **No update hooks needed**
- Use OOP API4 style: `\Civi\Api4\GroupContact::action(FALSE)` (not procedural)
- `sendDoubleOptInEmail()` and `sendWelcomeMessage()` are **stubs** — they log the action with `@todo` describing what's needed, but do not implement the full email flow
- The core `addContactToMailingGroup()` and `processMailingSubscriptionFromOrder()` methods must be **fully functional** (not stubs)
- Follow the existing constructor and error handling patterns from `ContactUpdater` / `ContributionUpdater`
- Include proper PHPDoc on all methods
- Use `$this->civicrmHelper->initialize()` directly (not a wrapper — see todo #12)

## Verification

After implementation, confirm:
1. `src/Service/MailingUpdater.php` exists with all methods listed above
2. `commerce_civicrm.services.yml` registers the service and injects it into `OrderCivicrmUpdater`
3. `OrderCivicrmUpdater::processOrderItem()` handles the `mailing` entity type
4. `addContactToMailingGroup()` uses `GroupContact` API4
5. `sendDoubleOptInEmail()` and `sendWelcomeMessage()` log stubs with `@todo` comments
6. No syntax errors in any modified file
