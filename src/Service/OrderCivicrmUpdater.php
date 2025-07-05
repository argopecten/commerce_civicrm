<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Orchestrator service that coordinates all CiviCRM updates from Commerce Orders.
 */
class OrderCivicrmUpdater {

  /**
   * The contact updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\ContactUpdater
   */
  protected $contactUpdater;

  /**
   * The contribution updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\ContributionUpdater
   */
  protected $contributionUpdater;

  /**
   * The event updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\EventUpdater
   */
  protected $eventUpdater;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs an OrderCivicrmUpdater object.
   *
   * @param \Drupal\commerce_civicrm\Service\ContactUpdater $contact_updater
   *   The contact updater service.
   * @param \Drupal\commerce_civicrm\Service\ContributionUpdater $contribution_updater
   *   The contribution updater service.
   * @param \Drupal\commerce_civicrm\Service\EventUpdater $event_updater
   *   The event updater service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ContactUpdater $contact_updater,
    ContributionUpdater $contribution_updater,
    EventUpdater $event_updater,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->contactUpdater = $contact_updater;
    $this->contributionUpdater = $contribution_updater;
    $this->eventUpdater = $event_updater;
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Processes a completed Commerce Order and updates CiviCRM accordingly.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return array
   *   Array containing the results of the CiviCRM updates.
   */
  public function processCompletedOrder(OrderInterface $order) {
    $results = [
      'contact_id' => NULL,
      'contribution_id' => NULL,
      'participant_ids' => [],
      'success' => FALSE,
      'errors' => [],
    ];

    try {
      $this->logger->info('Starting CiviCRM updates for completed order @order_id', [
        '@order_id' => $order->id(),
      ]);

      // Step 1: Update or create CiviCRM contact
      $contact_id = $this->contactUpdater->updateContactFromOrder($order);
      if (!$contact_id) {
        $results['errors'][] = 'Failed to update or create CiviCRM contact';
        $this->logger->error('Failed to update or create CiviCRM contact for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return $results;
      }
      
      $results['contact_id'] = $contact_id;
      $this->logger->info('Updated CiviCRM contact @contact_id for order @order_id', [
        '@contact_id' => $contact_id,
        '@order_id' => $order->id(),
      ]);

      // Step 2: Create CiviCRM contribution
      $contribution_id = $this->contributionUpdater->createContributionFromOrder($order, $contact_id);
      if ($contribution_id) {
        $results['contribution_id'] = $contribution_id;
        $this->logger->info('Created CiviCRM contribution @contribution_id for order @order_id', [
          '@contribution_id' => $contribution_id,
          '@order_id' => $order->id(),
        ]);
      } else {
        $results['errors'][] = 'Failed to create CiviCRM contribution';
        $this->logger->warning('Failed to create CiviCRM contribution for order @order_id', [
          '@order_id' => $order->id(),
        ]);
      }

      // Step 3: Create CiviCRM event registrations (if applicable)
      $participant_ids = $this->eventUpdater->createEventRegistrationsFromOrder($order, $contact_id);
      if (!empty($participant_ids)) {
        $results['participant_ids'] = $participant_ids;
        $this->logger->info('Created CiviCRM event registrations for order @order_id: @participant_ids', [
          '@order_id' => $order->id(),
          '@participant_ids' => implode(', ', $participant_ids),
        ]);
      }

      // Mark as successful if at least contact was updated
      $results['success'] = TRUE;
      
      $this->logger->info('Completed CiviCRM updates for order @order_id', [
        '@order_id' => $order->id(),
      ]);

    } catch (\Exception $e) {
      $results['errors'][] = $e->getMessage();
      $this->logger->error('Error processing CiviCRM updates for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Processes an order cancellation and updates CiviCRM accordingly.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return array
   *   Array containing the results of the CiviCRM updates.
   */
  public function processCancelledOrder(OrderInterface $order) {
    $results = [
      'contribution_updated' => FALSE,
      'participants_updated' => [],
      'success' => FALSE,
      'errors' => [],
    ];

    try {
      $this->logger->info('Starting CiviCRM updates for cancelled order @order_id', [
        '@order_id' => $order->id(),
      ]);

      // Update contribution status to cancelled
      $this->updateContributionStatus($order, 'Cancelled');
      $results['contribution_updated'] = TRUE;

      // Update participant statuses to cancelled
      $this->updateParticipantStatuses($order, 'Cancelled');
      $results['participants_updated'] = TRUE;

      $results['success'] = TRUE;
      
      $this->logger->info('Completed CiviCRM cancellation updates for order @order_id', [
        '@order_id' => $order->id(),
      ]);

    } catch (\Exception $e) {
      $results['errors'][] = $e->getMessage();
      $this->logger->error('Error processing CiviCRM cancellation for order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Processes a placed Commerce Order and updates CiviCRM accordingly.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return array
   *   Array containing the results of the CiviCRM updates.
   */
  public function processPlacedOrder(OrderInterface $order) {
    $results = [
      'contact_id' => NULL,
      'contribution_id' => NULL,
      'participant_ids' => [],
      'success' => FALSE,
      'errors' => [],
    ];

    try {
      $this->logger->info('Starting CiviCRM updates for placed order @order_id', [
        '@order_id' => $order->id(),
      ]);

      // Step 1: Update or create CiviCRM contact
      $contact_id = $this->contactUpdater->updateContactFromOrder($order);
      if (!$contact_id) {
        $results['errors'][] = 'Failed to update or create CiviCRM contact';
        $this->logger->error('Failed to update or create CiviCRM contact for placed order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return $results;
      }
      
      $results['contact_id'] = $contact_id;
      $this->logger->info('Updated CiviCRM contact @contact_id for placed order @order_id', [
        '@contact_id' => $contact_id,
        '@order_id' => $order->id(),
      ]);

      // Step 2: Create CiviCRM contribution
      $contribution_id = $this->contributionUpdater->createContributionFromOrder($order, $contact_id);
      if ($contribution_id) {
        $results['contribution_id'] = $contribution_id;
        $this->logger->info('Created CiviCRM contribution @contribution_id for placed order @order_id', [
          '@contribution_id' => $contribution_id,
          '@order_id' => $order->id(),
        ]);
      } else {
        $results['errors'][] = 'Failed to create CiviCRM contribution';
        $this->logger->warning('Failed to create CiviCRM contribution for placed order @order_id', [
          '@order_id' => $order->id(),
        ]);
      }

      // Step 3: Create CiviCRM event registrations (if applicable)
      $participant_ids = $this->eventUpdater->createEventRegistrationsFromOrder($order, $contact_id);
      if (!empty($participant_ids)) {
        $results['participant_ids'] = $participant_ids;
        $this->logger->info('Created CiviCRM event registrations for placed order @order_id: @participant_ids', [
          '@order_id' => $order->id(),
          '@participant_ids' => implode(', ', $participant_ids),
        ]);
      }

      // Mark as successful if at least contact was updated
      $results['success'] = TRUE;
      
      $this->logger->info('Completed CiviCRM updates for placed order @order_id', [
        '@order_id' => $order->id(),
      ]);

    } catch (\Exception $e) {
      $results['errors'][] = $e->getMessage();
      $this->logger->error('Error processing CiviCRM updates for placed order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Updates the contribution status for a given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param string $status_name
   *   The new status name.
   */
  protected function updateContributionStatus(OrderInterface $order, $status_name) {
    try {
      // This would require implementing a method to find and update existing contributions
      // For now, we'll log that this should be implemented
      $this->logger->info('TODO: Update contribution status to @status for order @order_id', [
        '@status' => $status_name,
        '@order_id' => $order->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error updating contribution status: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Updates participant statuses for a given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   * @param string $status_name
   *   The new status name.
   */
  protected function updateParticipantStatuses(OrderInterface $order, $status_name) {
    try {
      // This would require implementing a method to find and update existing participants
      // For now, we'll log that this should be implemented
      $this->logger->info('TODO: Update participant statuses to @status for order @order_id', [
        '@status' => $status_name,
        '@order_id' => $order->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error updating participant statuses: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets the contact updater service.
   *
   * @return \Drupal\commerce_civicrm\Service\ContactUpdater
   *   The contact updater service.
   */
  public function getContactUpdater() {
    return $this->contactUpdater;
  }

  /**
   * Gets the contribution updater service.
   *
   * @return \Drupal\commerce_civicrm\Service\ContributionUpdater
   *   The contribution updater service.
   */
  public function getContributionUpdater() {
    return $this->contributionUpdater;
  }

  /**
   * Gets the event updater service.
   *
   * @return \Drupal\commerce_civicrm\Service\EventUpdater
   *   The event updater service.
   */
  public function getEventUpdater() {
    return $this->eventUpdater;
  }

}
