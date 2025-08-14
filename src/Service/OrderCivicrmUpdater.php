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
      $civicrm_enabled_items = 0;
      foreach ($order->getItems() as $order_item) {
        // Get the product variation first
        $purchased_entity = $order_item->getPurchasedEntity();
        
        if (!$purchased_entity) {
          continue;
        }
        
        // Get the actual product that contains the field_civicrm configuration
        $product = NULL;
        if ($purchased_entity->getEntityTypeId() === 'commerce_product_variation') {
          // If purchased entity is a variation, get the parent product
          $product = $purchased_entity->getProduct();
        } elseif ($purchased_entity->getEntityTypeId() === 'commerce_product') {
          // If purchased entity is already a product
          $product = $purchased_entity;
        }
        
        if (!$product || !$this->contactUpdater->isProductCivicrmEnabled($product)) {
          continue;
        }

        $civicrm_enabled_items++;
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
      'contact_id' => NULL,
      'contributions_cancelled' => [],
      'memberships_cancelled' => [],
      'events_cancelled' => [],
      'mailings_removed' => [],
      'success' => FALSE,
      'errors' => [],
    ];

    try {
      $this->logger->info('Starting CiviCRM cancellation updates for order @order_id', [
        '@order_id' => $order->id(),
      ]);

      // Step 1: Get or create CiviCRM contact (needed for lookups)
      $contact_id = $this->contactUpdater->updateContactFromOrder($order);
      if (!$contact_id) {
        $results['errors'][] = 'Failed to get CiviCRM contact for cancellation processing';
        $this->logger->error('Failed to get CiviCRM contact for cancelled order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return $results;
      }
      
      $results['contact_id'] = $contact_id;
      $this->logger->info('Found CiviCRM contact @contact_id for cancelled order @order_id', [
        '@contact_id' => $contact_id,
        '@order_id' => $order->id(),
      ]);

      // Step 2: Process each order item for cancellation-specific CiviCRM updates
      foreach ($order->getItems() as $order_item) {
        // Get the product variation first
        $purchased_entity = $order_item->getPurchasedEntity();
        
        if (!$purchased_entity) {
          continue;
        }
        
        // Get the actual product that contains the field_civicrm configuration
        $product = NULL;
        if ($purchased_entity->getEntityTypeId() === 'commerce_product_variation') {
          // If purchased entity is a variation, get the parent product
          $product = $purchased_entity->getProduct();
        } elseif ($purchased_entity->getEntityTypeId() === 'commerce_product') {
          // If purchased entity is already a product
          $product = $purchased_entity;
        }
        
        if (!$product || !$this->contactUpdater->isProductCivicrmEnabled($product)) {
          continue;
        }

        $settings = $this->contactUpdater->getProductSettings($product);
        
        switch ($settings['entity']) {
          case 'contribution':
            $cancelled_contribution = $this->contributionUpdater->cancelContributionFromOrder($order, $contact_id);
            if ($cancelled_contribution) {
              $results['contributions_cancelled'][] = $cancelled_contribution;
              $this->logger->info('Cancelled CiviCRM contribution @contribution_id for product @product_id', [
                '@contribution_id' => $cancelled_contribution,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to cancel contribution for product {$product->id()}";
            }
            break;

          case 'membership':
            $cancelled_membership = $this->membershipUpdater->cancelMembershipFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order, 
              $order_item
            );
            if ($cancelled_membership) {
              $results['memberships_cancelled'][] = $cancelled_membership;
              $this->logger->info('Cancelled membership @membership_id for product @product_id', [
                '@membership_id' => $cancelled_membership,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to cancel membership for product {$product->id()}";
            }
            break;

          case 'event':
            $participant_role_id = $settings['participant_role_id'] ?? NULL;
            $cancelled_participant = $this->eventUpdater->cancelEventRegistrationFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order,
              $participant_role_id
            );
            if ($cancelled_participant) {
              $results['events_cancelled'][] = $cancelled_participant;
              $this->logger->info('Cancelled event registration @participant_id for product @product_id', [
                '@participant_id' => $cancelled_participant,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to cancel event registration for product {$product->id()}";
            }
            break;

          case 'mailing':
            $mailing_preferences = $settings['mailing_preferences'] ?? [];
            $removed_from_group = $this->mailingUpdater->removeFromMailingGroupFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order,
              $mailing_preferences
            );
            if ($removed_from_group) {
              $results['mailings_removed'][] = $settings['entity_id'];
              $this->logger->info('Removed contact from mailing group @group_id for product @product_id', [
                '@group_id' => $settings['entity_id'],
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to remove contact from mailing group for product {$product->id()}";
            }
            break;
        }
      }

      // Mark as successful if at least contact was found and no errors
      $results['success'] = empty($results['errors']);
      
      $this->logger->info('Completed CiviCRM cancellation updates for order @order_id: @results', [
        '@order_id' => $order->id(),
        '@results' => json_encode($results),
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
      'contributions' => [],
      'memberships' => [],
      'events' => [],
      'mailings' => [],
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

      // Step 2: Process each order item for initial CiviCRM integration
      $civicrm_enabled_items = 0;
      foreach ($order->getItems() as $order_item) {
        // Get the product variation first
        $purchased_entity = $order_item->getPurchasedEntity();
        
        if (!$purchased_entity) {
          continue;
        }
        
        // Get the actual product that contains the field_civicrm configuration
        $product = NULL;
        if ($purchased_entity->getEntityTypeId() === 'commerce_product_variation') {
          // If purchased entity is a variation, get the parent product
          $product = $purchased_entity->getProduct();
        } elseif ($purchased_entity->getEntityTypeId() === 'commerce_product') {
          // If purchased entity is already a product
          $product = $purchased_entity;
        }

        if (!$product || !$this->contactUpdater->isProductCivicrmEnabled($product)) {
          continue;
        }

        $civicrm_enabled_items++;
        $settings = $this->contactUpdater->getProductSettings($product);
        $this->logger->info('Product settings for product @product_id: @settings', [
          '@product_id' => $product->id(),
          '@settings' => json_encode($settings),
        ]);

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
            // For placed orders, create pending memberships that will be activated later
            $membership_id = $this->membershipUpdater->createPendingMembershipFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order, 
              $order_item
            );
            if ($membership_id) {
              $results['memberships'][] = $membership_id;
              $this->logger->info('Created pending membership @membership_id for product @product_id', [
                '@membership_id' => $membership_id,
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to create pending membership for product {$product->id()}";
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
            // For placed orders, only subscribe to essential/immediate mailing lists
            $mailing_preferences = $settings['mailing_preferences'] ?? [];
            $success = $this->mailingUpdater->processInitialMailingSubscriptionFromOrder(
              $contact_id, 
              $settings['entity_id'], 
              $order,
              $mailing_preferences
            );
            if ($success) {
              $results['mailings'][] = $settings['entity_id'];
              $this->logger->info('Added contact to initial mailing group @group_id for product @product_id', [
                '@group_id' => $settings['entity_id'],
                '@product_id' => $product->id(),
              ]);
            } else {
              $results['errors'][] = "Failed to add contact to initial mailing group for product {$product->id()}";
            }
            break;
        }
      }

      // Mark as successful if at least contact was updated and no errors
      $results['success'] = empty($results['errors']);
      
      $this->logger->info('Completed CiviCRM updates for placed order @order_id: @results', [
        '@order_id' => $order->id(),
        '@results' => json_encode($results),
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

}
