<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\MembershipStatus;

/**
 * Service for updating CiviCRM memberships based on Commerce Order data.
 */
class MembershipUpdater {

  use StringTranslationTrait;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a MembershipUpdater object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
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
  public function createMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item): ?int {
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

      // Calculate membership dates.
      $dates = $this->calculateMembershipDates($membership_type);
      
      // Prepare membership data
      $membership_data = [
        'contact_id' => $contact_id,
        'membership_type_id' => $membership_type_id,
        'join_date' => $dates['join_date'],
        'start_date' => $dates['start_date'],
        'source' => 'Commerce Order #' . $order->id(),
        'status_id' => $this->getMembershipStatusId('New'), // Will be updated based on payment status
      ];

      // Add custom fields if configured
      $membership_data = $this->addCustomFieldsToMembership($membership_data, $order, $order_item);

      // Create the membership
      $result = Membership::create(FALSE)
        ->setValues($membership_data)
        ->execute();

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

    } catch (\CRM_Core_Exception $e) {
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
  protected function findExistingMembership($contact_id, $membership_type_id): ?array {
    try {
      $result = Membership::get(FALSE)
        ->addSelect('id', 'status_id', 'end_date')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('membership_type_id', '=', $membership_type_id)
        ->addWhere('status_id:name', 'IN', ['New', 'Current', 'Grace'])
        ->setLimit(1)
        ->execute();

      return !empty($result[0]) ? $result[0] : NULL;
    } catch (\CRM_Core_Exception $e) {
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
  protected function updateMembership($membership_id, OrderInterface $order, OrderItemInterface $order_item): ?int {
    try {
      $update_data = [
        'source' => 'Commerce Order #' . $order->id() . ' (Renewal)',
      ];

      // Add custom fields if configured
      $update_data = $this->addCustomFieldsToMembership($update_data, $order, $order_item);

      $result = Membership::update(FALSE)
        ->addWhere('id', '=', $membership_id)
        ->setValues($update_data)
        ->execute();

      if (!empty($result[0]['id'])) {
        $this->logger->info('Updated CiviCRM membership @membership_id', [
          '@membership_id' => $membership_id,
        ]);
        return $membership_id;
      }

      return NULL;
    } catch (\CRM_Core_Exception $e) {
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
  protected function getMembershipTypeDetails($membership_type_id): ?array {
    try {
      $result = MembershipType::get(FALSE)
        ->addSelect('id', 'name', 'duration_unit', 'duration_interval', 'period_type')
        ->addWhere('id', '=', $membership_type_id)
        ->setLimit(1)
        ->execute();

      return !empty($result[0]) ? $result[0] : NULL;
    } catch (\CRM_Core_Exception $e) {
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
   *   Array with join_date and start_date. The end_date is intentionally
   *   omitted so CiviCRM can calculate it based on the membership type.
   */
  protected function calculateMembershipDates($membership_type): array {
    $today = new \DateTimeImmutable();

    return [
      'join_date' => $today->format('Y-m-d'),
      'start_date' => $today->format('Y-m-d'),
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
  protected function addCustomFieldsToMembership($membership_data, OrderInterface $order, OrderItemInterface $order_item): array {
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
   *
   * @return void
   */
  protected function updateMembershipStatus($membership_id, OrderInterface $order): void {
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

      Membership::update(FALSE)
        ->addWhere('id', '=', $membership_id)
        ->setValues(['status_id' => $this->getMembershipStatusId($status_name)])
        ->execute();

      $this->logger->info('Updated membership @membership_id status to @status', [
        '@membership_id' => $membership_id,
        '@status' => $status_name,
      ]);
    } catch (\CRM_Core_Exception $e) {
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
  public function renewMembership($contact_id, $membership_type_id, OrderInterface $order): ?int {
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

      // Get membership type details for renewal context.
      $membership_type = $this->getMembershipTypeDetails($membership_type_id);
      if (!$membership_type) {
        return NULL;
      }

      // Update membership without setting end_date so CiviCRM can calculate it.
      $result = Membership::update(FALSE)
        ->addWhere('id', '=', $existing_membership['id'])
        ->setValues([
          'status_id' => $this->getMembershipStatusId('Current'),
          'source' => 'Commerce Order #' . $order->id() . ' (Renewal)',
        ])
        ->execute();

      if (!empty($result[0]['id'])) {
        $this->logger->info('Renewed CiviCRM membership @membership_id', [
          '@membership_id' => $existing_membership['id'],
        ]);
        return $existing_membership['id'];
      }

      return NULL;
    } catch (\CRM_Core_Exception $e) {
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
  public function getMembershipTypes(): array {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting membership types');
      return [];
    }

    try {
      $result = MembershipType::get(FALSE)
        ->addSelect('id', 'name', 'label', 'description')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('label', 'ASC')
        ->execute();

      $membership_types = ['' => $this->t('- Select a membership type -')];
      foreach ($result as $membership_type) {
        $membership_types[$membership_type['id']] = $membership_type['label'];
      }

      return $membership_types;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error getting membership types: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => $this->t('Error loading membership types')];
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
  public function cancelMembership($contact_id, $membership_type_id, OrderInterface $order): bool {
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

      $result = Membership::update(FALSE)
        ->addWhere('id', '=', $existing_membership['id'])
        ->setValues([
          'status_id' => $this->getMembershipStatusId('Cancelled'),
          'source' => 'Commerce Order #' . $order->id() . ' (Cancelled)',
        ])
        ->execute();

      if (!empty($result[0]['id'])) {
        $this->logger->info('Cancelled CiviCRM membership @membership_id', [
          '@membership_id' => $existing_membership['id'],
        ]);
        return TRUE;
      }

      return FALSE;
    } catch (\CRM_Core_Exception $e) {
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
  public function getMembershipStatuses(): array {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting membership statuses');
      return [];
    }

    try {
      $result = MembershipStatus::get(FALSE)
        ->addSelect('id', 'name', 'label')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('weight', 'ASC')
        ->execute();

      $statuses = [];
      foreach ($result as $status) {
        $statuses[$status['id']] = $status['label'] ?? $status['name'];
      }

      return $statuses;
    } catch (\CRM_Core_Exception $e) {
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
  public function createPendingMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item): ?int {
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

      // Calculate membership dates.
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
        'source' => 'Commerce Order #' . $order->id() . ' (Pending)',
        'status_id' => $pending_status_id,
      ];

      // Add custom fields if configured
      $membership_data = $this->addCustomFieldsToMembership($membership_data, $order, $order_item);

      $this->logger->info('Creating membership with data: @data', [
        '@data' => json_encode($membership_data),
      ]);

      // Create the membership
      $result = Membership::create(FALSE)
        ->setValues($membership_data)
        ->execute();

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

    } catch (\CRM_Core_Exception $e) {
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
  protected function updateMembershipToPending($membership_id, OrderInterface $order, OrderItemInterface $order_item): ?int {
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

      $result = Membership::update(FALSE)
        ->addWhere('id', '=', $membership_id)
        ->setValues($update_data)
        ->execute();

      if (!empty($result[0]['id'])) {
        $this->logger->info('Updated CiviCRM membership @membership_id to pending status', [
          '@membership_id' => $membership_id,
        ]);
        return $membership_id;
      }

      return NULL;
    } catch (\CRM_Core_Exception $e) {
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
  public function cancelMembershipFromOrder($contact_id, $membership_type_id, OrderInterface $order, OrderItemInterface $order_item): ?int {
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

      $result = Membership::update(FALSE)
        ->addWhere('id', '=', $existing_membership['id'])
        ->setValues([
          'status_id' => $this->getMembershipStatusId('Cancelled'),
          'source' => 'Commerce Order #' . $order->id() . ' (Cancelled)',
        ])
        ->execute();

      if (!empty($result[0]['id'])) {
        $this->logger->info('Cancelled CiviCRM membership @membership_id for contact @contact_id', [
          '@membership_id' => $existing_membership['id'],
          '@contact_id' => $contact_id,
        ]);
        return $existing_membership['id'];
      }

      return NULL;
    } catch (\CRM_Core_Exception $e) {
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
  protected function getMembershipStatusId($status_name): ?int {
    try {
      $result = MembershipStatus::get(FALSE)
        ->addSelect('id')
        ->addWhere('name', '=', $status_name)
        ->addWhere('is_active', '=', TRUE)
        ->setLimit(1)
        ->execute();

      if (!empty($result[0]['id'])) {
        return $result[0]['id'];
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error getting membership status ID for @status: @error', [
        '@status' => $status_name,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

}
