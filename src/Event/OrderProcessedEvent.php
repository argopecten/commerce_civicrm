<?php

namespace Drupal\commerce_civicrm\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event fired after an order has been processed (or cancelled) in CiviCRM.
 *
 * Dispatched as CommerceCivicrmEvents::ORDER_PROCESSED after record creation
 * and as CommerceCivicrmEvents::ORDER_CANCELLED after cancellation.
 */
class OrderProcessedEvent extends Event {

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The processed order.
   * @param int $contactId
   *   The CiviCRM contact ID.
   * @param array $records
   *   The affected CiviCRM record IDs keyed by type ('contributions',
   *   'memberships', 'participants', 'mailings').
   * @param array $context
   *   Processing context (see OrderItemDirectivesEvent).
   */
  public function __construct(
    protected readonly OrderInterface $order,
    protected readonly int $contactId,
    protected readonly array $records,
    protected readonly array $context = [],
  ) {}

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the CiviCRM contact ID.
   */
  public function getContactId(): int {
    return $this->contactId;
  }

  /**
   * Gets the affected CiviCRM record IDs keyed by type.
   */
  public function getRecords(): array {
    return $this->records;
  }

  /**
   * Gets the processing context.
   */
  public function getContext(): array {
    return $this->context;
  }

}
