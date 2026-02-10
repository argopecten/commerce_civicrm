<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_civicrm\Service\CivicrmHelper;

/**
 * Service for managing CiviCRM mailing group subscriptions.
 */
class MailingUpdater {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a MailingUpdater object.
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
   * Adds a contact to a CiviCRM mailing group.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM group ID.
   * @param array $preferences
   *   Optional preferences: 'double_opt_in', 'send_welcome', 'update_existing',
   *   'source'.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function addContactToMailingGroup(int $contact_id, int $mailing_group_id, array $preferences = []): bool {
    if (!is_array($preferences)) {
      $preferences = [];
    }

    try {
      if (!$this->civicrmHelper->initialize()) {
        $this->logger->error('Failed to initialize CiviCRM for mailing group subscription');
        return FALSE;
      }

      $existing_membership = $this->checkGroupMembership($contact_id, $mailing_group_id);
      $existing_status = $existing_membership['status'] ?? NULL;

      if ($existing_membership && $existing_status !== 'Removed' && empty($preferences['update_existing'])) {
        $this->logger->info('Contact @contact_id is already in mailing group @group_id; update not requested', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
        return TRUE;
      }

      $status = !empty($preferences['double_opt_in']) ? 'Pending' : 'Added';

      if ($existing_membership) {
        $operation = \Civi\Api4\GroupContact::update(FALSE)
          ->addWhere('contact_id', '=', $contact_id)
          ->addWhere('group_id', '=', $mailing_group_id)
          ->addValue('status', $status);
      }
      else {
        $operation = \Civi\Api4\GroupContact::create(FALSE)
          ->addValue('contact_id', $contact_id)
          ->addValue('group_id', $mailing_group_id)
          ->addValue('status', $status);
      }

      if (!empty($preferences['source'])) {
        $operation->addValue('source', $preferences['source']);
      }

      $result = $operation->execute();

      if ($result->count() === 0) {
        $this->logger->warning('Mailing group subscription returned no results for contact @contact_id, group @group_id', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
        return FALSE;
      }

      if (!empty($preferences['double_opt_in'])) {
        $this->sendDoubleOptInEmail($contact_id, $mailing_group_id);
      }

      if (!empty($preferences['send_welcome']) && $status === 'Added') {
        $this->sendWelcomeMessage($contact_id, $mailing_group_id);
      }

      $this->logger->info('Mailing group subscription saved for contact @contact_id, group @group_id with status @status', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@status' => $status,
      ]);

      return TRUE;
    } catch (\CRM_Core_Exception $e) {
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
   *   The CiviCRM group ID.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function removeContactFromMailingGroup(int $contact_id, int $mailing_group_id): bool {
    try {
      if (!$this->civicrmHelper->initialize()) {
        $this->logger->error('Failed to initialize CiviCRM for mailing group removal');
        return FALSE;
      }

      $existing_membership = $this->checkGroupMembership($contact_id, $mailing_group_id);
      $existing_status = $existing_membership['status'] ?? NULL;

      if (!$existing_membership) {
        $this->logger->info('No mailing group membership found for contact @contact_id, group @group_id', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
        return TRUE;
      }

      if ($existing_status === 'Removed') {
        $this->logger->info('Contact @contact_id already removed from mailing group @group_id', [
          '@contact_id' => $contact_id,
          '@group_id' => $mailing_group_id,
        ]);
        return TRUE;
      }

      \Civi\Api4\GroupContact::update(FALSE)
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('group_id', '=', $mailing_group_id)
        ->addValue('status', 'Removed')
        ->execute();

      $this->logger->info('Removed contact @contact_id from mailing group @group_id', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
      ]);

      return TRUE;
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error removing contact @contact_id from mailing group @group_id: @error', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Processes a mailing subscription from an order.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM group ID.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being processed.
   * @param array $preferences
   *   Optional preferences.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function processMailingSubscriptionFromOrder(int $contact_id, int $mailing_group_id, OrderInterface $order, array $preferences = []): bool {
    if (!is_array($preferences)) {
      $preferences = [];
    }

    if (empty($preferences['source'])) {
      $preferences['source'] = 'Commerce Order #' . $order->id();
    }

    return $this->addContactToMailingGroup($contact_id, $mailing_group_id, $preferences);
  }

  /**
   * Checks if a contact is already in a group.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM group ID.
   *
   * @return array|null
   *   The membership record if found, NULL otherwise.
   */
  public function checkGroupMembership(int $contact_id, int $mailing_group_id): ?array {
    try {
      if (!$this->civicrmHelper->initialize()) {
        return NULL;
      }

      $result = \Civi\Api4\GroupContact::get(FALSE)
        ->addSelect('id', 'status')
        ->addWhere('contact_id', '=', $contact_id)
        ->addWhere('group_id', '=', $mailing_group_id)
        ->setLimit(1)
        ->execute();

      if ($result->count() > 0) {
        return $result->first();
      }
    } catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error checking mailing group membership for contact @contact_id, group @group_id: @error', [
        '@contact_id' => $contact_id,
        '@group_id' => $mailing_group_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Sends a double opt-in confirmation email.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM group ID.
   *
   * @todo Implement full double opt-in flow: token generation, Drupal route
   *   for confirmation, token validation, status update.
   */
  protected function sendDoubleOptInEmail(int $contact_id, int $mailing_group_id): void {
    $this->logger->info('Double opt-in email requested for contact @contact_id, group @group_id -- not yet implemented', [
      '@contact_id' => $contact_id,
      '@group_id' => $mailing_group_id,
    ]);
  }

  /**
   * Sends a welcome message after subscription.
   *
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param int $mailing_group_id
   *   The CiviCRM group ID.
   *
   * @todo Implement via CiviCRM MessageTemplate API.
   */
  protected function sendWelcomeMessage(int $contact_id, int $mailing_group_id): void {
    $this->logger->info('Welcome message requested for contact @contact_id, group @group_id -- not yet implemented', [
      '@contact_id' => $contact_id,
      '@group_id' => $mailing_group_id,
    ]);
  }

}
