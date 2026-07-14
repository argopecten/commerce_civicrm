<?php

namespace Drupal\commerce_civicrm\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event fired after a renewal payment has been recorded in CiviCRM.
 */
class RenewalRecordedEvent extends Event {

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order the renewal payment belongs to.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The Commerce payment that was recorded.
   * @param array $records
   *   The created/updated CiviCRM record IDs keyed by type ('contributions',
   *   'memberships').
   */
  public function __construct(
    protected readonly OrderInterface $order,
    protected readonly PaymentInterface $payment,
    protected readonly array $records,
  ) {}

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the payment.
   */
  public function getPayment(): PaymentInterface {
    return $this->payment;
  }

  /**
   * Gets the affected CiviCRM record IDs keyed by type.
   */
  public function getRecords(): array {
    return $this->records;
  }

}
