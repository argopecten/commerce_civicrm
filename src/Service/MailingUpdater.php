<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;

/**
 * Service for updating CiviCRM mailing groups based on Commerce Order data.
 */
class MailingUpdater {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The CiviCRM helper service.
   *
   * @var \Drupal\commerce_civicrm\Service\CivicrmHelper
   */
  protected $civicrmHelper;

  /**
   * Constructs a MailingUpdater object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\commerce_civicrm\Service\CivicrmHelper $civicrm_helper
   *   The CiviCRM helper service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, CivicrmHelper $civicrm_helper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->civicrmHelper = $civicrm_helper;
  }

  /**
   * Adds a contact to a mailing group with preferences.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param array $preferences
   *   Array of mailing preferences.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function addContactToMailingGroup($contact_id, $mailing_group_id, array $preferences = []) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for mailing group subscription');
      return FALSE;
    }

    try {
      $this->logger->info('Adding contact @contact_id to mailing group @group_id', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);

      // Check if contact is already in the group
      $existing_membership = $this->checkGroupMembership($contact_id, $mailing_group_id);
      if ($existing_membership) {
        $this->logger->info('Contact @contact_id is already in mailing group @group_id', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
        
        // Update existing membership if needed
        if (!empty($preferences['update_existing'])) {
          return $this->updateGroupMembership($contact_id, $mailing_group_id, $preferences);
        }
        
        return TRUE;
      }

      // Add contact to group
      $group_contact_data = [
        'contact_id' => $contact_id,
        'group_id' => $mailing_group_id,
        'status' => 'Added',
      ];

      $result = civicrm_api4('GroupContact', 'create', [
        'values' => $group_contact_data,
        'checkPermissions' => FALSE,
      ]);

      if (!empty($result[0]['id'])) {
        $this->logger->info('Successfully added contact @contact_id to mailing group @group_id', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);

        // Handle double opt-in if requested
        if (!empty($preferences['double_opt_in'])) {
          $this->sendDoubleOptInEmail($contact_id, $mailing_group_id);
        }

        // Send welcome message if requested
        if (!empty($preferences['send_welcome'])) {
          $this->sendWelcomeMessage($contact_id, $mailing_group_id);
        }

        return TRUE;
      }

      $this->logger->error('Failed to add contact @contact_id to mailing group @group_id: no result', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);
      return FALSE;

    } catch (\Exception $e) {
      $this->logger->error('Error adding contact @contact_id to mailing group @group_id: @error', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Removes a contact from a mailing group.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function removeContactFromMailingGroup($contact_id, $mailing_group_id) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for mailing group unsubscription');
      return FALSE;
    }

    try {
      $result = civicrm_api4('GroupContact', 'delete', [
        'where' => [
          ['contact_id', '=', $contact_id],
          ['group_id', '=', $mailing_group_id],
        ],
        'checkPermissions' => FALSE,
      ]);

      $this->logger->info('Removed contact @contact_id from mailing group @group_id', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error removing contact @contact_id from mailing group @group_id: @error', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if a contact is already in a mailing group.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   *
   * @return array|null
   *   The group membership data if found, NULL otherwise.
   */
  protected function checkGroupMembership($contact_id, $mailing_group_id) {
    try {
      $result = civicrm_api4('GroupContact', 'get', [
        'select' => ['id', 'status'],
        'where' => [
          ['contact_id', '=', $contact_id],
          ['group_id', '=', $mailing_group_id],
          ['status', 'IN', ['Added', 'Pending']],
        ],
        'limit' => 1,
        'checkPermissions' => FALSE,
      ]);

      return !empty($result[0]) ? $result[0] : NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error checking group membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Updates an existing group membership.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param array $preferences
   *   Array of mailing preferences.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  protected function updateGroupMembership($contact_id, $mailing_group_id, array $preferences) {
    try {
      // Update status to 'Added' if it was pending
      $result = civicrm_api4('GroupContact', 'update', [
        'where' => [
          ['contact_id', '=', $contact_id],
          ['group_id', '=', $mailing_group_id],
        ],
        'values' => [
          'status' => 'Added',
        ],
        'checkPermissions' => FALSE,
      ]);

      $this->logger->info('Updated group membership for contact @contact_id in group @group_id', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error updating group membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Sends a double opt-in confirmation email.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function sendDoubleOptInEmail($contact_id, $mailing_group_id) {
    try {
      // This is a placeholder implementation
      // In a real implementation, you would:
      // 1. Create a confirmation token
      // 2. Send an email with a confirmation link
      // 3. Handle the confirmation process

      $this->logger->info('Double opt-in email sent to contact @contact_id for group @group_id (implementation needed)', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);

      // For now, just log the action
      // TODO: Implement actual double opt-in functionality
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error sending double opt-in email: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Sends a welcome message to a new mailing group subscriber.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function sendWelcomeMessage($contact_id, $mailing_group_id) {
    try {
      // This is a placeholder implementation
      // In a real implementation, you would:
      // 1. Get the contact's email address
      // 2. Get the group's welcome message template
      // 3. Send the welcome email

      $this->logger->info('Welcome message sent to contact @contact_id for group @group_id (implementation needed)', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);

      // For now, just log the action
      // TODO: Implement actual welcome message functionality
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error sending welcome message: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets available mailing groups.
   *
   * @return array
   *   Array of mailing groups keyed by ID.
   */
  public function getMailingGroups() {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting mailing groups');
      return [];
    }

    try {
      $result = civicrm_api4('Group', 'get', [
        'select' => ['id', 'name', 'description', 'title'],
        'where' => [
          ['is_active', '=', TRUE],
          ['group_type', 'CONTAINS', 'Mailing List'],
        ],
        'orderBy' => ['name' => 'ASC'],
        'checkPermissions' => FALSE,
      ]);

      $groups = ['' => t('- Select a mailing group -')];
      foreach ($result as $group) {
        $label = $group['title'] ?? $group['name'];
        if (!empty($group['description'])) {
          $label .= ' (' . $group['description'] . ')';
        }
        $groups[$group['id']] = $label;
      }

      return $groups;
    } catch (\Exception $e) {
      $this->logger->error('Error getting mailing groups: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => t('Error loading mailing groups')];
    }
  }

  /**
   * Gets all available groups (not just mailing groups).
   *
   * @return array
   *   Array of groups keyed by ID.
   */
  public function getAllGroups() {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting groups');
      return [];
    }

    try {
      $result = civicrm_api4('Group', 'get', [
        'select' => ['id', 'name', 'description', 'title', 'group_type'],
        'where' => [
          ['is_active', '=', TRUE],
        ],
        'orderBy' => ['name' => 'ASC'],
        'checkPermissions' => FALSE,
      ]);

      $groups = ['' => t('- Select a group -')];
      foreach ($result as $group) {
        $label = $group['title'] ?? $group['name'];
        if (!empty($group['description'])) {
          $label .= ' (' . $group['description'] . ')';
        }
        $groups[$group['id']] = $label;
      }

      return $groups;
    } catch (\Exception $e) {
      $this->logger->error('Error getting groups: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => t('Error loading groups')];
    }
  }

  /**
   * Gets group membership status options.
   *
   * @return array
   *   Array of status options.
   */
  public function getGroupMembershipStatuses() {
    return [
      'Added' => t('Added'),
      'Removed' => t('Removed'),
      'Pending' => t('Pending'),
    ];
  }

  /**
   * Processes mailing group subscription from Commerce Order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param array $preferences
   *   Array of mailing preferences.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function processMailingSubscriptionFromOrder($contact_id, $mailing_group_id, OrderInterface $order, array $preferences = []) {
    $this->logger->info('Processing mailing subscription from order @order_id for contact @contact_id', [
      '@order_id' => $order->id(),
      '@contact_id' => $contact_id,
    ]);

    // Add additional context for order-based subscriptions
    $preferences['source'] = 'Commerce Order #' . $order->id();
    
    return $this->addContactToMailingGroup($contact_id, $mailing_group_id, $preferences);
  }

  /**
   * Bulk adds contacts to a mailing group.
   *
   * @param array $contact_ids
   *   Array of CiviCRM contact IDs.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param array $preferences
   *   Array of mailing preferences.
   *
   * @return array
   *   Array with 'success' and 'errors' keys containing results.
   */
  public function bulkAddContactsToMailingGroup(array $contact_ids, $mailing_group_id, array $preferences = []) {
    $results = [
      'success' => [],
      'errors' => [],
    ];

    foreach ($contact_ids as $contact_id) {
      if ($this->addContactToMailingGroup($contact_id, $mailing_group_id, $preferences)) {
        $results['success'][] = $contact_id;
      } else {
        $results['errors'][] = $contact_id;
      }
    }

    $this->logger->info('Bulk mailing group subscription completed: @success successful, @errors errors', [
      '@success' => count($results['success']),
      '@errors' => count($results['errors']),
    ]);

    return $results;
  }

  /**
   * Gets mailing group statistics.
   *
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   *
   * @return array
   *   Array of statistics.
   */
  public function getMailingGroupStatistics($mailing_group_id) {
    if (!$this->civicrmHelper->initialize()) {
      return [];
    }

    try {
      $result = civicrm_api4('GroupContact', 'get', [
        'select' => ['status', 'id'],
        'where' => [
          ['group_id', '=', $mailing_group_id],
        ],
        'checkPermissions' => FALSE,
      ]);

      $stats = [
        'total' => 0,
        'added' => 0,
        'pending' => 0,
        'removed' => 0,
      ];

      foreach ($result as $membership) {
        $stats['total']++;
        $status = strtolower($membership['status']);
        if (isset($stats[$status])) {
          $stats[$status]++;
        }
      }

      return $stats;
    } catch (\Exception $e) {
      $this->logger->error('Error getting mailing group statistics: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Processes initial mailing subscription from a placed order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param array $preferences
   *   Array of mailing preferences.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function processInitialMailingSubscriptionFromOrder($contact_id, $mailing_group_id, OrderInterface $order, array $preferences = []) {
    $this->logger->info('Processing initial mailing subscription from placed order @order_id for contact @contact_id', [
      '@order_id' => $order->id(),
      '@contact_id' => $contact_id,
    ]);

    // Add additional context for order-based initial subscriptions
    $initial_preferences = array_merge($preferences, [
      'source' => 'Commerce Order #' . $order->id() . ' (Initial)',
      'immediate_only' => TRUE, // Flag to indicate only essential/immediate lists
    ]);
    
    return $this->addContactToMailingGroup($contact_id, $mailing_group_id, $initial_preferences);
  }

  /**
   * Removes a contact from a mailing group based on an order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM mailing group ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param array $preferences
   *   Array of mailing preferences.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function removeFromMailingGroupFromOrder($contact_id, $mailing_group_id, OrderInterface $order, array $preferences = []) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for mailing group removal from order');
      return FALSE;
    }

    try {
      $this->logger->info('Removing contact @contact_id from mailing group @group_id due to order @order_id', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@order_id' => $order->id(),
      ]);

      // Check if contact is in the group
      $existing_membership = $this->checkGroupMembership($contact_id, $mailing_group_id);
      if (!$existing_membership) {
        $this->logger->info('Contact @contact_id is not in mailing group @group_id, nothing to remove', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
        return TRUE; // Not an error if they're not in the group
      }

      // Update group membership to removed status
      $result = civicrm_api4('GroupContact', 'update', [
        'where' => [
          ['contact_id', '=', $contact_id],
          ['group_id', '=', $mailing_group_id],
        ],
        'values' => [
          'status' => 'Removed',
        ],
        'checkPermissions' => FALSE,
      ]);

      $this->logger->info('Removed contact @contact_id from mailing group @group_id due to order @order_id', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@order_id' => $order->id(),
      ]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error removing contact @contact_id from mailing group @group_id for order @order_id: @error', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
