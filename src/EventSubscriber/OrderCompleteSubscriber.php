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
   * - place (draft → completed) - when order is placed from draft state
   * - cancel (any → canceled) - when order is canceled to canceled state
   * - validate (validation → any) - when order validates from validation state to any state
   * - fulfill (fulfillment → any) - when order is fulfilled from fulfillment state to any state
   * The transitions are defined in the order workflow, typically in the
   * 'commerce_order.workflow.order' configuration.
   */
  public static function getSubscribedEvents() {
    $events['commerce_order.place.post_transition'] = ['onOrderPlace', -100]; // Draft → Completed
    $events['commerce_order.cancel.post_transition'] = ['onOrderCancel', -100]; // Any → Canceled
    $events['commerce_order.validate.post_transition'] = ['onOrderValidate', -100]; // Validation → Any
    $events['commerce_order.fulfill.post_transition'] = ['onOrderFulfill', -100]; // Fulfillment → Any
    return $events;
  }

  /**
   * Reacts to the order being placed (draft → completed).
   *
   * Uses processPlacedOrder() for initial order processing including
   * contact creation, contribution recording, and event registrations.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   * The workflow transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // Check that this transition is from draft state
    $transition = $event->getTransition();
    if ($transition->getFromState()->getId() !== 'draft') {
      return;
    }

    $this->logger->info('Order @order_id has been placed (draft → completed).', ['@order_id' => $order->id()]);

    // Check if any order items have CiviCRM integration enabled
    $has_civicrm_integration = $this->hasCivicrmIntegration($order);
    
    if ($has_civicrm_integration) {
      // Process the placed order using the service
      $results = $this->orderCivicrmUpdater->processPlacedOrder($order);
      
      if (!empty($results['contact_id'])) {
        $this->logger->info('Successfully processed CiviCRM updates for placed order @order_id. Contact ID: @contact_id', [
          '@order_id' => $order->id(),
          '@contact_id' => $results['contact_id'],
        ]);
      } else {
        $this->logger->error('Failed to process CiviCRM updates for placed order @order_id', [
          '@order_id' => $order->id(),
        ]);
      }
    } else {
      $this->logger->info('No CiviCRM integration enabled for placed order @order_id', ['@order_id' => $order->id()]);
    }
  }

  /**
   * Reacts to the order being canceled (any → canceled).
   *
   * Uses processCancelledOrder() to handle cancellation-specific updates
   * including contribution status updates and participant cancellations.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   * The workflow transition event.
   */
  public function onOrderCancel(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // Check that this transition is to canceled state
    $transition = $event->getTransition();
    if ($transition->getToState()->getId() !== 'canceled') {
      return;
    }

    $this->logger->info('Order @order_id has been canceled.', ['@order_id' => $order->id()]);

    // Check if any order items have CiviCRM integration enabled
    $has_civicrm_integration = $this->hasCivicrmIntegration($order);
    
    if ($has_civicrm_integration) {
      // Handle order cancellation - update CiviCRM records accordingly
      $results = $this->orderCivicrmUpdater->processCancelledOrder($order);
      
      if (!empty($results['success'])) {
        $this->logger->info('Successfully processed CiviCRM cancellation updates for order @order_id', [
          '@order_id' => $order->id(),
        ]);
      } else {
        $this->logger->error('Failed to process CiviCRM cancellation updates for order @order_id. Errors: @errors', [
          '@order_id' => $order->id(),
          '@errors' => implode(', ', $results['errors'] ?? []),
        ]);
      }
    }
  }

  /**
   * Reacts to the order being validated (validation → any state).
   *
   * Uses processCompletedOrder() for comprehensive processing including
   * contact updates, contributions, memberships, events, and mailing subscriptions.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   * The workflow transition event.
   */
  public function onOrderValidate(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // Check that this transition is from validation state
    $transition = $event->getTransition();
    if ($transition->getFromState()->getId() !== 'validation') {
      return;
    }

    $to_state = $transition->getToState()->getId();
    $this->logger->info('Order @order_id has been validated (validation → @to_state).', [
      '@order_id' => $order->id(),
      '@to_state' => $to_state,
    ]);

    // Check if any order items have CiviCRM integration enabled
    $has_civicrm_integration = $this->hasCivicrmIntegration($order);
    
    if ($has_civicrm_integration) {
      // Process the validated order using the service
      $results = $this->orderCivicrmUpdater->processCompletedOrder($order);
      
      if (!empty($results['contact_id'])) {
        $this->logger->info('Successfully processed CiviCRM updates for validated order @order_id (validation → @to_state). Contact ID: @contact_id', [
          '@order_id' => $order->id(),
          '@to_state' => $to_state,
          '@contact_id' => $results['contact_id'],
        ]);
      } else {
        $this->logger->error('Failed to process CiviCRM updates for validated order @order_id (validation → @to_state)', [
          '@order_id' => $order->id(),
          '@to_state' => $to_state,
        ]);
      }
    } else {
      $this->logger->info('No CiviCRM integration enabled for validated order @order_id (validation → @to_state)', [
        '@order_id' => $order->id(),
        '@to_state' => $to_state,
      ]);
    }
  }

  /**
   * Reacts to the order being fulfilled (fulfillment → any state).
   *
   * Uses processCompletedOrder() for final processing including any
   * remaining CiviCRM updates like membership activations or final mailings.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   * The workflow transition event.
   */
  public function onOrderFulfill(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // Check that this transition is from fulfillment state
    $transition = $event->getTransition();
    if ($transition->getFromState()->getId() !== 'fulfillment') {
      return;
    }

    $to_state = $transition->getToState()->getId();
    $this->logger->info('Order @order_id has been fulfilled (fulfillment → @to_state).', [
      '@order_id' => $order->id(),
      '@to_state' => $to_state,
    ]);

    // Check if any order items have CiviCRM integration enabled
    $has_civicrm_integration = $this->hasCivicrmIntegration($order);
    
    if ($has_civicrm_integration) {
      // Handle order fulfillment - process any final CiviCRM updates
      $results = $this->orderCivicrmUpdater->processCompletedOrder($order);
      
      if (!empty($results['contact_id'])) {
        $this->logger->info('Successfully processed CiviCRM fulfillment updates for order @order_id (fulfillment → @to_state). Contact ID: @contact_id', [
          '@order_id' => $order->id(),
          '@to_state' => $to_state,
          '@contact_id' => $results['contact_id'],
        ]);
      } else {
        $this->logger->error('Failed to process CiviCRM fulfillment updates for order @order_id (fulfillment → @to_state)', [
          '@order_id' => $order->id(),
          '@to_state' => $to_state,
        ]);
      }
    } else {
      $this->logger->info('No CiviCRM integration enabled for fulfilled order @order_id (fulfillment → @to_state)', [
        '@order_id' => $order->id(),
        '@to_state' => $to_state,
      ]);
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