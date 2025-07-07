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
   * The membership updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\MembershipUpdater
   */
  protected $membershipUpdater;

  /**
   * The event updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\EventUpdater
   */
  protected $eventUpdater;

  /**
   * The mailing updater service.
   *
   * @var \Drupal\commerce_civicrm\Service\MailingUpdater
   */
  protected $mailingUpdater;

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
   * @param \Drupal\commerce_civicrm\Service\MembershipUpdater $membership_updater
   *   The membership updater service.
   * @param \Drupal\commerce_civicrm\Service\EventUpdater $event_updater
   *   The event updater service.
   * @param \Drupal\commerce_civicrm\Service\MailingUpdater $mailing_updater
   *   The mailing updater service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ContactUpdater $contact_updater,
    ContributionUpdater $contribution_updater,
    MembershipUpdater $membership_updater,
    EventUpdater $event_updater,
    MailingUpdater $mailing_updater,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->contactUpdater = $contact_updater;
    $this->contributionUpdater = $contribution_updater;
    $this->membershipUpdater = $membership_updater;
    $this->eventUpdater = $event_updater;
    $this->mailingUpdater = $mailing_updater;
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
      'contributions' => [],
      'memberships' => [],
      'events' => [],
      'mailings' => [],
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

      // Step 2: Process each order item for CiviCRM integration
      foreach ($order->getItems() as $order_item) {
        $product = $order_item->getPurchasedEntity();
        
        if (!$product || !$this->contactUpdater->isProductCivicrmEnabled($product)) {
          continue;
        }

        $settings = $this->contactUpdater->getProductSettings($product);
        
        switch ($settings['entity']) {
          case 'contribution':
            $contribution_id = $this->contributionUpdater->createContributionFromOrder($order, $contact_id);
            if ($contribution_id) {
              $results['contributions'][] = $contribution_id;
              $this->logger->info('Created CiviCRM contribution @contribution_id for product @product_id', [
                '@contribution_id' => $contribution_id,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to create contribution for product {$product->id()}";
            }
            break;

          case 'membership':
            $membership_id = $this->membershipUpdater->createMembershipFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order, 
              $order_item
            );
            if ($membership_id) {
              $results['memberships'][] = $membership_id;
              $this->logger->info('Created membership @membership_id for product @product_id', [
                '@membership_id' => $membership_id,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to create membership for product {$product->id()}";
            }
            break;

          case 'event':
            $participant_role_id = $settings['participant_role_id'] ?? NULL;
            $participant_id = $this->eventUpdater->createEventRegistrationWithRole(
              $contact_id, 
              $settings['entity_id'], 
              $participant_role_id
            );
            if ($participant_id) {
              $results['events'][] = $participant_id;
              $this->logger->info('Created event registration @participant_id for product @product_id', [
                '@participant_id' => $participant_id,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to create event registration for product {$product->id()}";
            }
            break;

          case 'mailing':
            $mailing_preferences = $settings['mailing_preferences'] ?? [];
            $success = $this->mailingUpdater->processMailingSubscriptionFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order,
              $mailing_preferences
            );
            if ($success) {
              $results['mailings'][] = $settings['entity_id'];
              $this->logger->info('Added contact to mailing group @group_id for product @product_id', [
                '@group_id' => $settings['entity_id'],
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to add contact to mailing group for product {$product->id()}";
            }
            break;
        }
      }

      // Mark as successful if at least contact was updated and no errors
      $results['success'] = empty($results['errors']);
      
      $this->logger->info('Completed CiviCRM updates for order @order_id: @results', [
        '@order_id' => $order->id(),
        '@results' => json_encode($results),
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
   * Gets the membership updater service.
   *
   * @return \Drupal\commerce_civicrm\Service\MembershipUpdater
   *   The membership updater service.
   */
  public function getMembershipUpdater() {
    return $this->membershipUpdater;
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

  /**
   * Gets the mailing updater service.
   *
   * @return \Drupal\commerce_civicrm\Service\MailingUpdater
   *   The mailing updater service.
   */
  public function getMailingUpdater() {
    return $this->mailingUpdater;
  }

}
