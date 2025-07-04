<?php

namespace Drupal\commerce_civicrm\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Civi\Api4\UFMatch;
use Civi\Api4\Membership;
use Civi\Api4\Contribution;

/**
 * Processes paid Commerce orders to create CiviCRM records.
 *
 * This subscriber listens for an order to be "placed" (i.e., completed)
 * and then processes any CiviCRM-linked products in the order.
 */
class OrderCompleteSubscriber implements EventSubscriberInterface {

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new OrderCompleteSubscriber object.
   *
   * @param \Psr\Log\LoggerInterfaceFactory $logger_factory
   * The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   */
  public function __construct($logger_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->logger = $logger_factory->get('commerce_civicrm');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Subscribe to the event that fires after an order is placed.
    $events['commerce_order.place.post_transition'] = ['onOrderPlace'];
    return $events;
  }

  /**
   * Handles the event when an order is placed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   * The workflow transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // Ensure the order is fully paid before processing.
    if (!$order->isPaid()) {
      return;
    }

    \Drupal::service('civicrm')->initialize();
    $customer = $order->getCustomer();

    // Anonymous users cannot be processed as they don't have a linked CiviCRM contact.
    if ($customer->isAnonymous()) {
        $this->logger->warning('Order @oid was placed by an anonymous user and cannot be processed for CiviCRM.', ['@oid' => $order->id()]);
        return;
    }

    try {
      // Find the CiviCRM contact ID for the Drupal user.
      $ufMatch = UFMatch::get(FALSE)
        ->addWhere('uf_id', '=', $customer->id())
        ->setLimit(1)
        ->execute()
        ->first();
      
      if (empty($ufMatch['contact_id'])) {
        $this->logger->warning('Could not find CiviCRM contact for user ID @uid.', ['@uid' => $customer->id()]);
        return;
      }
      $contact_id = $ufMatch['contact_id'];
    } catch (\Exception $e) {
      $this->logger->error('Error finding CiviCRM contact for user @uid: @message', ['@uid' => $customer->id(), '@message' => $e->getMessage()]);
      return;
    }

    // Process each item in the order.
    foreach ($order->getItems() as $order_item) {
      /** @var \Drupal\commerce_product_variation\Entity\ProductVariationInterface $purchased_entity */
      $purchased_entity = $order_item->getPurchasedEntity();
      if (!$purchased_entity) {
        continue;
      }
      $product = $purchased_entity->getProduct();

      if (!$product || !$product->hasField('field_civicrm')) {
        continue;
      }

      $settings_raw = $product->get('field_civicrm')->value;
      if (empty($settings_raw)) {
        continue;
      }
      
      $settings = json_decode($settings_raw, TRUE);

      if (empty($settings['enabled']) || empty($settings['entity_id'])) {
        continue;
      }

      // Process based on the configured entity type.
      if ($settings['entity'] === 'membership') {
        $this->processMembership($order, $contact_id, $settings['entity_id']);
      } elseif ($settings['entity'] === 'contribution') {
        $this->processContribution($order, $contact_id, $settings['entity_id'], $order_item->getTotalPrice());
      }
    }
  }

  /**
   * Creates or renews a CiviCRM membership.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * The commerce order.
   * @param int $contact_id
   * The CiviCRM contact ID.
   * @param int $membership_type_id
   * The CiviCRM membership type ID.
   */
  private function processMembership(OrderInterface $order, $contact_id, $membership_type_id) {
    try {
      Membership::create(FALSE)
        ->addValue('contact_id', $contact_id)
        ->addValue('membership_type_id', $membership_type_id)
        ->addValue('source', 'Drupal Commerce Order ' . $order->id())
        ->addValue('status_id', 'New') // The API handles renewals vs new correctly.
        ->execute();
      $this->logger->info('Successfully processed membership for contact @cid from order @oid.', ['@cid' => $contact_id, '@oid' => $order->id()]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM membership: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Creates a CiviCRM contribution.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   * The commerce order.
   * @param int $contact_id
   * The CiviCRM contact ID.
   * @param int $financial_type_id
   * The CiviCRM financial type ID.
   * @param \Drupal\commerce_price\Price $price
   * The price of the order item.
   */
  private function processContribution(OrderInterface $order, $contact_id, $financial_type_id, $price) {
    try {
      Contribution::create(FALSE)
        ->addValue('contact_id', $contact_id)
        ->addValue('financial_type_id', $financial_type_id)
        ->addValue('total_amount', $price->getNumber())
        ->addValue('currency', $price->getCurrencyCode())
        ->addValue('source', 'Drupal Commerce Order ' . $order->id())
        ->addValue('contribution_status_id', 'Completed')
        ->execute();
      $this->logger->info('Successfully created contribution for contact @cid from order @oid.', ['@cid' => $contact_id, '@oid' => $order->id()]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to create CiviCRM contribution: @message', ['@message' => $e->getMessage()]);
    }
  }
}
