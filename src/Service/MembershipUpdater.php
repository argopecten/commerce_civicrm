<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;

/**
 * Service for updating CiviCRM memberships based on Commerce Order data.
 */
class MembershipUpdater {

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
   * Constructs a MembershipUpdater object.
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
   * Creates or updates a CiviCRM membership from a Commerce Order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The Commerce Order Item entity.
   *
   * @return int|null
   *   The membership ID if successful, NULL otherwise.
   */
  public function createMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for membership creation');
      return NULL;
    }

    try {
      $this->logger->info('Creating CiviCRM membership for contact @contact_id, type @type_id', [
        '@contact_id' => $contact_id,
        '@type_id' => $membership_type_id,
      ]);

      // Check if membership already exists
      $existing_membership = $this->findExistingMembership($contact_id, $membership_type_id);
      if ($existing_membership) {
        $this->logger->info('Existing membership found, updating instead of creating new one');
        return $this->updateMembership($existing_membership['id'], $order, $order_item);
      }

      // Get membership type details
      $membership_type = $this->getMembershipTypeDetails($membership_type_id);
      if (!$membership_type) {
        $this->logger->error('Membership type @type_id not found', [
          '@type_id' => $membership_type_id,
        ]);
        return NULL;
      }

      // Calculate membership dates
      $dates = $this->calculateMembershipDates($membership_type);
      
      // Prepare membership data
      $membership_data = [
        'contact_id' => $contact_id,
        'membership_type_id' => $membership_type_id,
        'join_date' => $dates['join_date'],
        'start_date' => $dates['start_date'],
        'end_date' => $dates['end_date'],
        'source' => 'Commerce Order #' . $order->id(),
        'status_id' => $this->getMembershipStatusId('New'), // Will be updated based on payment status
      ];

      // Add custom fields if configured
      $membership_data = $this->addCustomFieldsToMembership($membership_data, $order, $order_item);

      // Create the membership
      $result = civicrm_api4('Membership', 'create', [
        'values' => $membership_data,
      ]);

      if (!empty($result[0]['id'])) {
        $membership_id = $result[0]['id'];
        $this->logger->info('Created CiviCRM membership @membership_id for contact @contact_id', [
          '@membership_id' => $membership_id,
          '@contact_id' => $contact_id,
        ]);

        // Update membership status based on order payment status
        $this->updateMembershipStatus($membership_id, $order);

        return $membership_id;
      }

      $this->logger->error('Failed to create CiviCRM membership: empty result');
      return NULL;

    } catch (\Exception $e) {
      $this->logger->error('Error creating CiviCRM membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Finds an existing membership for a contact and membership type.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   *
   * @return array|null
   *   The membership data if found, NULL otherwise.
   */
  protected function findExistingMembership($contact_id, $membership_type_id) {
    try {
      $result = civicrm_api4('Membership', 'get', [
        'select' => ['id', 'status_id', 'end_date'],
        'where' => [
          ['contact_id', '=', $contact_id],
          ['membership_type_id', '=', $membership_type_id],
          ['status_id:name', 'IN', ['New', 'Current', 'Grace']],
        ],
        'limit' => 1,
      ]);

      return !empty($result[0]) ? $result[0] : NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error finding existing membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Updates an existing membership.
   *
   * @param int $membership_id
   *   The membership ID to update.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The Commerce Order Item entity.
   *
   * @return int|null
   *   The membership ID if successful, NULL otherwise.
   */
  protected function updateMembership($membership_id, OrderInterface $order, OrderItemInterface $order_item) {
    try {
      $update_data = [
        'source' => 'Commerce Order #' . $order->id() . ' (Renewal)',
      ];

      // Add custom fields if configured
      $update_data = $this->addCustomFieldsToMembership($update_data, $order, $order_item);

      $result = civicrm_api4('Membership', 'update', [
        'where' => [['id', '=', $membership_id]],
        'values' => $update_data,
      ]);

      if (!empty($result[0]['id'])) {
        $this->logger->info('Updated CiviCRM membership @membership_id', [
          '@membership_id' => $membership_id,
        ]);
        return $membership_id;
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error updating CiviCRM membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets membership type details.
   *
   * @param int $membership_type_id
   *   The membership type ID.
   *
   * @return array|null
   *   The membership type data if found, NULL otherwise.
   */
  protected function getMembershipTypeDetails($membership_type_id) {
    try {
      $result = civicrm_api4('MembershipType', 'get', [
        'select' => ['id', 'name', 'duration_unit', 'duration_interval', 'period_type'],
        'where' => [['id', '=', $membership_type_id]],
        'limit' => 1,
      ]);

      return !empty($result[0]) ? $result[0] : NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error getting membership type details: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Calculates membership dates based on membership type.
   *
   * @param array $membership_type
   *   The membership type data.
   *
   * @return array
   *   Array with join_date, start_date, and end_date.
   */
  protected function calculateMembershipDates($membership_type) {
    $today = new \DateTime();
    $join_date = $today->format('Y-m-d');
    $start_date = $today->format('Y-m-d');
    
    // Calculate end date based on membership type duration
    $end_date = clone $today;
    $duration_unit = $membership_type['duration_unit'] ?? 'year';
    $duration_interval = $membership_type['duration_interval'] ?? 1;
    
    switch ($duration_unit) {
      case 'day':
        $end_date->add(new \DateInterval('P' . $duration_interval . 'D'));
        break;
      case 'month':
        $end_date->add(new \DateInterval('P' . $duration_interval . 'M'));
        break;
      case 'year':
      default:
        $end_date->add(new \DateInterval('P' . $duration_interval . 'Y'));
        break;
    }
    
    // Handle fixed period memberships (e.g., calendar year)
    if ($membership_type['period_type'] === 'fixed') {
      $end_date = new \DateTime($today->format('Y') . '-12-31');
    }
    
    return [
      'join_date' => $join_date,
      'start_date' => $start_date,
      'end_date' => $end_date->format('Y-m-d'),
    ];
  }

  /**
   * Adds custom fields to membership data.
   *
   * @param array $membership_data
   *   The membership data array.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The Commerce Order Item entity.
   *
   * @return array
   *   The updated membership data.
   */
  protected function addCustomFieldsToMembership($membership_data, OrderInterface $order, OrderItemInterface $order_item) {
    // Add order reference
    $membership_data['source'] = 'Commerce Order #' . $order->id();
    
    // Add any custom field mappings here
    // This could be extended to map specific order or product fields to CiviCRM custom fields
    
    return $membership_data;
  }

  /**
   * Updates membership status based on order payment status.
   *
   * @param int $membership_id
   *   The membership ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   */
  protected function updateMembershipStatus($membership_id, OrderInterface $order) {
    try {
      $status_name = 'New';
      
      // Map order state to membership status
      switch ($order->getState()->getId()) {
        case 'completed':
          $status_name = 'Current';
          break;
        case 'canceled':
          $status_name = 'Cancelled';
          break;
        case 'draft':
        case 'pending':
        default:
          $status_name = 'Pending';
          break;
      }

      civicrm_api4('Membership', 'update', [
        'where' => [['id', '=', $membership_id]],
        'values' => ['status_id' => $this->getMembershipStatusId($status_name)],
      ]);

      $this->logger->info('Updated membership @membership_id status to @status', [
        '@membership_id' => $membership_id,
        '@status' => $status_name,
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error updating membership status: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Renews an existing membership.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return int|null
   *   The membership ID if successful, NULL otherwise.
   */
  public function renewMembership($contact_id, $membership_type_id, OrderInterface $order) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for membership renewal');
      return NULL;
    }

    try {
      $existing_membership = $this->findExistingMembership($contact_id, $membership_type_id);
      if (!$existing_membership) {
        $this->logger->warning('No existing membership found for renewal, creating new one');
        return NULL;
      }

      // Get membership type details for calculating new end date
      $membership_type = $this->getMembershipTypeDetails($membership_type_id);
      if (!$membership_type) {
        return NULL;
      }

      // Calculate new end date from current end date
      $current_end_date = new \DateTime($existing_membership['end_date']);
      $new_end_date = clone $current_end_date;
      
      $duration_unit = $membership_type['duration_unit'] ?? 'year';
      $duration_interval = $membership_type['duration_interval'] ?? 1;
      
      switch ($duration_unit) {
        case 'day':
          $new_end_date->add(new \DateInterval('P' . $duration_interval . 'D'));
          break;
        case 'month':
          $new_end_date->add(new \DateInterval('P' . $duration_interval . 'M'));
          break;
        case 'year':
        default:
          $new_end_date->add(new \DateInterval('P' . $duration_interval . 'Y'));
          break;
      }

      // Update membership
        $result = civicrm_api4('Membership', 'update', [
          'where' => [['id', '=', $membership_id]],
          'values' => [
            'end_date' => $new_end_date->format('Y-m-d'),
            'status_id' => $this->getMembershipStatusId('Current'),
            'source' => 'Commerce Order #' . $order->id() . ' (Renewal)',
          ],
        ]);      if (!empty($result[0]['id'])) {
        $this->logger->info('Renewed CiviCRM membership @membership_id until @end_date', [
          '@membership_id' => $existing_membership['id'],
          '@end_date' => $new_end_date->format('Y-m-d'),
        ]);
        return $existing_membership['id'];
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error renewing CiviCRM membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets available membership types.
   *
   * @return array
   *   Array of membership types keyed by ID.
   */
  public function getMembershipTypes() {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting membership types');
      return [];
    }

    try {
      $result = civicrm_api4('MembershipType', 'get', [
        'select' => ['id', 'name', 'description'],
        'where' => [['is_active', '=', TRUE]],
        'orderBy' => ['name' => 'ASC'],
      ]);

      $membership_types = ['' => t('- Select a membership type -')];
      foreach ($result as $membership_type) {
        $membership_types[$membership_type['id']] = $membership_type['name'];
      }

      return $membership_types;
    } catch (\Exception $e) {
      $this->logger->error('Error getting membership types: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => t('Error loading membership types')];
    }
  }

  /**
   * Cancels a membership.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function cancelMembership($contact_id, $membership_type_id, OrderInterface $order) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for membership cancellation');
      return FALSE;
    }

    try {
      $existing_membership = $this->findExistingMembership($contact_id, $membership_type_id);
      if (!$existing_membership) {
        $this->logger->warning('No existing membership found for cancellation');
        return FALSE;
      }

      $result = civicrm_api4('Membership', 'update', [
        'where' => [['id', '=', $existing_membership['id']]],
        'values' => [
          'status_id' => $this->getMembershipStatusId('Cancelled'),
          'source' => 'Commerce Order #' . $order->id() . ' (Cancelled)',
        ],
      ]);

      if (!empty($result[0]['id'])) {
        $this->logger->info('Cancelled CiviCRM membership @membership_id', [
          '@membership_id' => $existing_membership['id'],
        ]);
        return TRUE;
      }

      return FALSE;
    } catch (\Exception $e) {
      $this->logger->error('Error cancelling CiviCRM membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets membership status options.
   *
   * @return array
   *   Array of membership status options keyed by ID.
   */
  public function getMembershipStatuses() {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting membership statuses');
      return [];
    }

    try {
      $result = civicrm_api4('MembershipStatus', 'get', [
        'select' => ['id', 'name', 'label'],
        'where' => [['is_active', '=', TRUE]],
        'orderBy' => ['weight' => 'ASC'],
      ]);

      $statuses = [];
      foreach ($result as $status) {
        $statuses[$status['id']] = $status['label'] ?? $status['name'];
      }

      return $statuses;
    } catch (\Exception $e) {
      $this->logger->error('Error getting membership statuses: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Creates a pending membership from an order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The Commerce Order Item entity.
   *
   * @return int|null
   *   The membership ID if successful, NULL otherwise.
   */
  public function createPendingMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for pending membership creation');
      return NULL;
    }

    try {
      $this->logger->info('Creating pending CiviCRM membership for contact @contact_id, type @type_id', [
        '@contact_id' => $contact_id,
        '@type_id' => $membership_type_id,
      ]);

      // Check if membership already exists
      $existing_membership = $this->findExistingMembership($contact_id, $membership_type_id);
      if ($existing_membership) {
        $this->logger->info('Existing membership found, updating to pending status');
        return $this->updateMembershipToPending($existing_membership['id'], $order, $order_item);
      }

      // Get membership type details
      $membership_type = $this->getMembershipTypeDetails($membership_type_id);
      if (!$membership_type) {
        $this->logger->error('Membership type @type_id not found', [
          '@type_id' => $membership_type_id,
        ]);
        return NULL;
      }

      // Calculate membership dates
      $dates = $this->calculateMembershipDates($membership_type);
      
      // Get proper status ID for pending memberships
      $pending_status_id = $this->getMembershipStatusId('Pending');
      if (!$pending_status_id) {
        // Fallback to 'New' status if 'Pending' doesn't exist
        $pending_status_id = $this->getMembershipStatusId('New');
      }
      
      if (!$pending_status_id) {
        $this->logger->error('Could not find valid membership status for pending membership');
        return NULL;
      }
      
      // Prepare membership data with pending status
      $membership_data = [
        'contact_id' => $contact_id,
        'membership_type_id' => $membership_type_id,
        'join_date' => $dates['join_date'],
        'start_date' => $dates['start_date'],
        'end_date' => $dates['end_date'],
        'source' => 'Commerce Order #' . $order->id() . ' (Pending)',
        'status_id' => $pending_status_id,
      ];

      // Add custom fields if configured
      $membership_data = $this->addCustomFieldsToMembership($membership_data, $order, $order_item);

      $this->logger->info('Creating membership with data: @data', [
        '@data' => json_encode($membership_data),
      ]);

      // Create the membership
      $result = civicrm_api4('Membership', 'create', [
        'values' => $membership_data,
      ]);

      if (!empty($result[0]['id'])) {
        $membership_id = $result[0]['id'];
        $this->logger->info('Created pending CiviCRM membership @membership_id for contact @contact_id', [
          '@membership_id' => $membership_id,
          '@contact_id' => $contact_id,
        ]);
        return $membership_id;
      }

      $this->logger->error('Failed to create pending CiviCRM membership: empty result');
      return NULL;

    } catch (\Exception $e) {
      $this->logger->error('Error creating pending CiviCRM membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Updates an existing membership to pending status.
   *
   * @param int $membership_id
   *   The membership ID to update.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The Commerce Order Item entity.
   *
   * @return int|null
   *   The membership ID if successful, NULL otherwise.
   */
  protected function updateMembershipToPending($membership_id, OrderInterface $order, OrderItemInterface $order_item) {
    try {
      // Get proper status ID for pending memberships
      $pending_status_id = $this->getMembershipStatusId('Pending');
      if (!$pending_status_id) {
        // Fallback to 'New' status if 'Pending' doesn't exist
        $pending_status_id = $this->getMembershipStatusId('New');
      }
      
      if (!$pending_status_id) {
        $this->logger->error('Could not find valid membership status for pending update');
        return NULL;
      }

      $update_data = [
        'status_id' => $pending_status_id,
        'source' => 'Commerce Order #' . $order->id() . ' (Pending)',
      ];

      // Add custom fields if configured
      $update_data = $this->addCustomFieldsToMembership($update_data, $order, $order_item);

      $result = civicrm_api4('Membership', 'update', [
        'where' => [['id', '=', $membership_id]],
        'values' => $update_data,
      ]);

      if (!empty($result[0]['id'])) {
        $this->logger->info('Updated CiviCRM membership @membership_id to pending status', [
          '@membership_id' => $membership_id,
        ]);
        return $membership_id;
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error updating CiviCRM membership to pending: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Cancels a membership from an order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The Commerce Order Item entity.
   *
   * @return int|null
   *   The cancelled membership ID if successful, NULL otherwise.
   */
  public function cancelMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item) {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for membership cancellation');
      return NULL;
    }

    try {
      $this->logger->info('Cancelling CiviCRM membership for contact @contact_id, type @type_id', [
        '@contact_id' => $contact_id,
        '@type_id' => $membership_type_id,
      ]);

      $existing_membership = $this->findExistingMembership($contact_id, $membership_type_id);
      if (!$existing_membership) {
        $this->logger->warning('No existing membership found for cancellation for contact @contact_id, type @type_id', [
          '@contact_id' => $contact_id,
          '@type_id' => $membership_type_id,
        ]);
        return NULL;
      }

      $result = civicrm_api4('Membership', 'update', [
        'where' => [['id', '=', $existing_membership['id']]],
        'values' => [
          'status_id' => $this->getMembershipStatusId('Cancelled'),
          'source' => 'Commerce Order #' . $order->id() . ' (Cancelled)',
        ],
      ]);

      if (!empty($result[0]['id'])) {
        $this->logger->info('Cancelled CiviCRM membership @membership_id for contact @contact_id', [
          '@membership_id' => $existing_membership['id'],
          '@contact_id' => $contact_id,
        ]);
        return $existing_membership['id'];
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('Error cancelling CiviCRM membership: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets membership status ID by name.
   *
   * @param string $status_name
   *   The status name (e.g., 'Pending', 'New', 'Current').
   *
   * @return int|null
   *   The status ID if found, NULL otherwise.
   */
  protected function getMembershipStatusId($status_name) {
    try {
      $result = civicrm_api4('MembershipStatus', 'get', [
        'select' => ['id'],
        'where' => [
          ['name', '=', $status_name],
          ['is_active', '=', TRUE],
        ],
        'limit' => 1,
      ]);

      if (!empty($result[0]['id'])) {
        return $result[0]['id'];
      }
    } catch (\Exception $e) {
      $this->logger->error('Error getting membership status ID for @status: @error', [
        '@status' => $status_name,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

}
