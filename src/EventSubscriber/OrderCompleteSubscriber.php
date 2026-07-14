<?php

namespace Drupal\commerce_civicrm\EventSubscriber;

use Drupal\commerce_civicrm\Service\OrderCivicrmUpdater;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Routes Commerce order workflow transitions to CiviCRM processing.
 *
 * Subscribes to the group-level commerce_order.post_transition event (fired
 * for every transition of every order workflow) and matches the transition
 * ID against the configured commerce_civicrm.settings order.create_transitions
 * and order.cancel_transitions lists. This works with custom workflows and
 * with order-save flows that chain several transitions into a single save
 * (where only the last transition dispatches an event).
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
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly OrderCivicrmUpdater $orderCivicrmUpdater,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.post_transition' => ['onTransition', -50],
    ];
  }

  /**
   * Handles order workflow transitions.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onTransition(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($order->getEntityTypeId() !== 'commerce_order') {
      return;
    }

    $settings = $this->configFactory->get('commerce_civicrm.settings');

    $workflow_id = $event->getWorkflow()->getId();
    $workflows = $settings->get('order.workflows') ?: [];
    if ($workflows !== [] && !in_array($workflow_id, $workflows, TRUE)) {
      return;
    }

    $transition_id = $event->getTransition()->getId();
    $context = [
      'transition' => $transition_id,
      'from_state' => $event->getFromState()->getId(),
      'to_state' => $event->getToState()->getId(),
      'workflow' => $workflow_id,
    ];

    if (in_array($transition_id, $settings->get('order.create_transitions') ?: [], TRUE)) {
      $this->logger->info('Processing order @order_id transition @transition (@from → @to, workflow @workflow) for CiviCRM record creation', [
        '@order_id' => $order->id(),
        '@transition' => $transition_id,
        '@from' => $context['from_state'],
        '@to' => $context['to_state'],
        '@workflow' => $workflow_id,
      ]);
      $this->orderCivicrmUpdater->processOrder($order, $context);
    }
    elseif (in_array($transition_id, $settings->get('order.cancel_transitions') ?: [], TRUE)) {
      $this->logger->info('Processing order @order_id transition @transition for CiviCRM cancellation', [
        '@order_id' => $order->id(),
        '@transition' => $transition_id,
      ]);
      $this->orderCivicrmUpdater->processCancellation($order, $context);
    }
  }

}
