<?php

namespace Drupal\commerce_civicrm\Service;

use Civi\Api4\Membership;
use Civi\Api4\MembershipStatus;
use Civi\Api4\MembershipType;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Membership lookups and cancellation for Commerce orders.
 *
 * Membership creation and renewal go through the CiviCRM Order API
 * (membership line items built by OrderCivicrmUpdater); this service
 * provides the supporting lookups and cancellation.
 */
class MembershipUpdater {

  use StringTranslationTrait;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a MembershipUpdater object.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Finds an existing renewable membership for a contact and type.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   *
   * @return array|null
   *   The membership record if found, NULL otherwise.
   */
  public function findExistingMembership(int $contact_id, int $membership_type_id): ?array {
    try {
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }
      $result = Membership::get(FALSE)
        ->addSelect('id', 'status_id', 'join_date', 'start_date', 'end_date')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('membership_type_id', '=', $membership_type_id)
        ->addWhere('status_id:name', 'IN', ['New', 'Current', 'Grace'])
        ->setLimit(1)
        ->execute();

      return $result->count() > 0 ? $result->first() : NULL;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error finding existing membership: @error', [
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
   *   The membership type record if found, NULL otherwise.
   */
  public function getMembershipTypeDetails(int $membership_type_id): ?array {
    try {
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }
      $result = MembershipType::get(FALSE)
        ->addSelect('id', 'name', 'financial_type_id', 'duration_unit', 'duration_interval', 'period_type')
        ->addWhere('id', '=', $membership_type_id)
        ->setLimit(1)
        ->execute();

      return $result->count() > 0 ? $result->first() : NULL;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error getting membership type details: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Cancels a membership by ID.
   *
   * @param int $membership_id
   *   The CiviCRM membership ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order the cancellation originates from (for the source note).
   *
   * @return bool
   *   TRUE on success.
   */
  public function cancelMembershipById(int $membership_id, OrderInterface $order): bool {
    if (!$this->civicrmHelper->initialize()) {
      return FALSE;
    }

    try {
      $result = Membership::update(FALSE)
        ->addWhere('id', '=', $membership_id)
        ->setValues([
          'status_id' => $this->getMembershipStatusId('Cancelled'),
          'is_override' => TRUE,
          'source' => 'Commerce Order #' . $order->id() . ' (Cancelled)',
        ])
        ->execute();

      if ($result->count() > 0) {
        $this->logger->info('Cancelled CiviCRM membership @membership_id for order @order_id', [
          '@membership_id' => $membership_id,
          '@order_id' => $order->id(),
        ]);
        return TRUE;
      }
      return FALSE;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error cancelling CiviCRM membership @membership_id: @error', [
        '@membership_id' => $membership_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Cancels the active membership of a contact for a membership type.
   *
   * Used when no line-item link to the created membership is available.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $membership_type_id
   *   The CiviCRM membership type ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order the cancellation originates from.
   *
   * @return int|null
   *   The cancelled membership ID if successful, NULL otherwise.
   */
  public function cancelMembershipFromOrder(int $contact_id, int $membership_type_id, OrderInterface $order): ?int {
    $existing_membership = $this->findExistingMembership($contact_id, $membership_type_id);
    if (!$existing_membership) {
      $this->logger->warning('No existing membership found for cancellation for contact @contact_id, type @type_id', [
        '@contact_id' => $contact_id,
        '@type_id' => $membership_type_id,
      ]);
      return NULL;
    }

    return $this->cancelMembershipById((int) $existing_membership['id'], $order) ? (int) $existing_membership['id'] : NULL;
  }

  /**
   * Gets available membership types as form options.
   *
   * Options are keyed by membership type name so product configuration stays
   * portable across sites with differing IDs.
   *
   * @return array
   *   Options array for select elements.
   */
  public function getMembershipTypes(): array {
    if (!$this->civicrmHelper->initialize()) {
      $this->logger->error('Failed to initialize CiviCRM for getting membership types');
      return [];
    }

    try {
      $result = MembershipType::get(FALSE)
        ->addSelect('name', 'label')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('label', 'ASC')
        ->execute();

      $membership_types = ['' => $this->t('- Select a membership type -')];
      foreach ($result as $membership_type) {
        $membership_types[$membership_type['name']] = $membership_type['label'];
      }

      return $membership_types;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error getting membership types: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['' => $this->t('Error loading membership types')];
    }
  }

  /**
   * Gets a membership status ID by name.
   *
   * @param string $status_name
   *   The status name (e.g. 'Current', 'Cancelled').
   *
   * @return int|null
   *   The status ID if found, NULL otherwise.
   */
  protected function getMembershipStatusId(string $status_name): ?int {
    try {
      $result = MembershipStatus::get(FALSE)
        ->addSelect('id')
        ->addWhere('name', '=', $status_name)
        ->addWhere('is_active', '=', TRUE)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return (int) $result->first()['id'];
      }
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error getting membership status ID for @status: @error', [
        '@status' => $status_name,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

}
