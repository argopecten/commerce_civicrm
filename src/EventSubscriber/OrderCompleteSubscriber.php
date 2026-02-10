<?php

namespace Drupal\commerce_civicrm\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\commerce_civicrm\Service\OrderCivicrmUpdater;

/**
 * Commerce CiviCRM Order Integration Event Subscriber.
 * 
 * This subscriber provides direct Commerce-to-CiviCRM integration by:
 * 1. Listening to Commerce order workflow transitions (place, validate, fulfill, cancel)
 * 2. Automatically creating CiviCRM records based on order contents
 * 3. Supporting configurable product-to-CiviCRM mappings
 * 4. Handling order cancellations and updates
 */
class OrderCompleteSubscriber implements EventSubscriberInterface {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs an OrderCompleteSubscriber object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly OrderCivicrmUpdater $orderCivicrmUpdater,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   */
  public static function getSubscribedEvents(): array {
    $events['commerce_order.place.post_transition'] = ['onOrderPlace', -50];
    $events['commerce_order.validate.post_transition'] = ['onOrderValidate', -50];
    $events['commerce_order.fulfill.post_transition'] = ['onOrderFulfill', -50];
    $events['commerce_order.cancel.post_transition'] = ['onOrderCancel', -50];
    return $events;
  }

  /**
   * Handles order place events - creates CiviCRM records.
   *
   * @return void
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $workflow = $event->getWorkflow();

    // Only process commerce orders
    if ($order->getEntityTypeId() !== 'commerce_order') {
      return;
    }
    
    $from_state = $event->getFromState()->getId();
    $to_state = $event->getToState()->getId();
    
    // Only process transitions from draft to placed state
    if ($from_state !== 'draft' || $to_state !== 'completed') {
      $this->logger->debug('Skipping order @order_id - not a draft to completed transition (@from_state → @to_state) in workflow @workflow', [
        '@order_id' => $order->id(),
        '@from_state' => $from_state,
        '@to_state' => $to_state,
        '@workflow' => $workflow->getId(),
      ]);
      return;
    }
    
    $this->logger->info('Processing order @order_id placement (@from_state → @to_state) in workflow @workflow for CiviCRM integration', [
      '@order_id' => $order->id(),
      '@from_state' => $from_state,
      '@to_state' => $to_state,
      '@workflow' => $workflow->getId(),
    ]);

    // Get the customer
    $customer = $order->getCustomer();
    if (!$customer) {
      $this->logger->warning('Order @order_id has no customer - skipping CiviCRM integration', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Process the order through the CiviCRM updater
    $this->orderCivicrmUpdater->processOrder($order);
  }

  /**
   * Handles order validate events - processes CiviCRM records for validated orders.
   *
   * @return void
   */
  public function onOrderValidate(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $workflow = $event->getWorkflow();

    // Only process commerce orders
    if ($order->getEntityTypeId() !== 'commerce_order') {
      return;
    }
    
    $from_state = $event->getFromState()->getId();
    $to_state = $event->getToState()->getId();
    
    $this->logger->info('Processing order @order_id validation (@from_state → @to_state) in workflow @workflow for CiviCRM integration', [
      '@order_id' => $order->id(),
      '@from_state' => $from_state,
      '@to_state' => $to_state,
      '@workflow' => $workflow->getId(),
    ]);

    // Get the customer
    $customer = $order->getCustomer();
    if (!$customer) {
      $this->logger->warning('Order @order_id has no customer - skipping CiviCRM integration', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Process the order through the CiviCRM updater
    $this->orderCivicrmUpdater->processOrder($order);
  }

  /**
   * Handles order fulfill events - processes CiviCRM records for fulfilled orders.
   *
   * @return void
   */
  public function onOrderFulfill(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $workflow = $event->getWorkflow();

    // Only process commerce orders
    if ($order->getEntityTypeId() !== 'commerce_order') {
      return;
    }
    
    $from_state = $event->getFromState()->getId();
    $to_state = $event->getToState()->getId();
    
    $this->logger->info('Processing order @order_id fulfillment (@from_state → @to_state) in workflow @workflow for CiviCRM integration', [
      '@order_id' => $order->id(),
      '@from_state' => $from_state,
      '@to_state' => $to_state,
      '@workflow' => $workflow->getId(),
    ]);

    // Get the customer
    $customer = $order->getCustomer();
    if (!$customer) {
      $this->logger->warning('Order @order_id has no customer - skipping CiviCRM integration', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Process the order through the CiviCRM updater
    $this->orderCivicrmUpdater->processOrder($order);
  }

  /**
   * Handles order cancel events - updates CiviCRM records.
   *
   * @return void
   */
  public function onOrderCancel(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $workflow = $event->getWorkflow();

    // Only process commerce orders
    if ($order->getEntityTypeId() !== 'commerce_order') {
      return;
    }
    
    // Only process transitions to canceled state
    $to_state = $event->getToState()->getId();
    if ($to_state !== 'canceled') {
      return;
    }

    $from_state = $event->getFromState()->getId();
    
    $this->logger->info('Processing order @order_id cancellation (@from_state → @to_state) in workflow @workflow for CiviCRM integration', [
      '@order_id' => $order->id(),
      '@from_state' => $from_state,
      '@to_state' => $to_state,
      '@workflow' => $workflow->getId(),
    ]);

    // Process the order cancellation through the CiviCRM updater
    $cancelled_records = $this->orderCivicrmUpdater->processCancellation($order);
    $this->logger->info('Order @order_id cancellation complete. Cancelled records: @records', [
      '@order_id' => $order->id(),
      '@records' => json_encode($cancelled_records),
    ]);
  }

}
