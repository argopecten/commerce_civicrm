<?php

namespace Drupal\commerce_civicrm\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\commerce_order\Entity\OrderInterface;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_civicrm\Service\OrderCivicrmUpdater;


/**
 * Subscribes to Commerce order completion to create CiviCRM contact/contribution.
 */
class OrderCompleteSubscriber implements EventSubscriberInterface {

  protected $logger;
  protected $entityTypeManager;
  protected $orderCivicrmUpdater;

  public function __construct(LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager, OrderCivicrmUpdater $order_civicrm_updater) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->entityTypeManager = $entity_type_manager;
    $this->orderCivicrmUpdater = $order_civicrm_updater;
  }

  /**
   * Defines the events to which this subscriber listens.
   *
   * The transitions are:
   * - place (cart → completed)
   * - complete (checkout → completed)
   * - cancel (any → canceled)
   * The transitions are defined in the order workflow, typically in the
   * 'commerce_order.workflow.order' configuration.
   */
  public static function getSubscribedEvents() {
   // This subscriber listens to the 'commerce_order.place.post_transition' event,
   // which is triggered after an order is placed and transitioned to the 'completed' state.
   // Subscribe to the post-transition event for the 'place' transition.
    $events['commerce_order.complete.post_transition'] = ['onOrderComplete', -100]; // Lower weight to run after other handlers
    $events['commerce_order.place.post_transition'] = ['onOrderPlace', -100]; // Lower weight to run after other handlers
    return $events;
  }

  public function onOrderComplete(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    
    $this->logger->info('Order @order_id has been completed.', ['@order_id' => $order->id()]);

    // Check if any order items have CiviCRM integration enabled
    $has_civicrm_integration = $this->hasCivicrmIntegration($order);
    
    if ($has_civicrm_integration) {
      // Process the completed order using the service
      $results = $this->orderCivicrmUpdater->processCompletedOrder($order);
      
      if ($results['success']) {
        $this->logger->info('Successfully processed CiviCRM updates for completed order @order_id. Contact ID: @contact_id, Contribution ID: @contribution_id', [
          '@order_id' => $order->id(),
          '@contact_id' => $results['contact_id'],
          '@contribution_id' => $results['contribution_id'],
        ]);
      } else {
        $this->logger->error('Failed to process CiviCRM updates for completed order @order_id. Errors: @errors', [
          '@order_id' => $order->id(),
          '@errors' => implode(', ', $results['errors']),
        ]);
      }
    } else {
      $this->logger->info('No CiviCRM integration enabled for completed order @order_id', ['@order_id' => $order->id()]);
    }
  }

  /**
   * Reacts to the order being placed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   * The workflow transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    $this->logger->info('Order @order_id has been placed.', ['@order_id' => $order->id()]);

    // Check if any order items have CiviCRM integration enabled
    $has_civicrm_integration = $this->hasCivicrmIntegration($order);
    
    if ($has_civicrm_integration) {
      // Process the placed order using the service
      $results = $this->orderCivicrmUpdater->processPlacedOrder($order);
      
      if ($results['success']) {
        $this->logger->info('Successfully processed CiviCRM updates for placed order @order_id. Contact ID: @contact_id, Contribution ID: @contribution_id', [
          '@order_id' => $order->id(),
          '@contact_id' => $results['contact_id'],
          '@contribution_id' => $results['contribution_id'],
        ]);
      } else {
        $this->logger->error('Failed to process CiviCRM updates for placed order @order_id. Errors: @errors', [
          '@order_id' => $order->id(),
          '@errors' => implode(', ', $results['errors']),
        ]);
      }
    } else {
      $this->logger->info('No CiviCRM integration enabled for placed order @order_id', ['@order_id' => $order->id()]);
    }
  }

  /**
   * Checks if any order items have CiviCRM integration enabled.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Commerce Order entity.
   *
   * @return bool
   *   TRUE if CiviCRM integration is enabled, FALSE otherwise.
   */
  private function hasCivicrmIntegration(OrderInterface $order) {
    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity && $purchased_entity->hasField('product_id')) {
        $product = $purchased_entity->get('product_id')->entity;
        if ($product && $product->hasField('field_civicrm') && !$product->get('field_civicrm')->isEmpty()) {
          $civicrm_settings_raw = $product->get('field_civicrm')->value;
          $civicrm_settings = json_decode($civicrm_settings_raw, TRUE);
          
          if (!empty($civicrm_settings['enabled'])) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }
}