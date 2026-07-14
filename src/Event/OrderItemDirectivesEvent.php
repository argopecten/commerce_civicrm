<?php

namespace Drupal\commerce_civicrm\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event fired while resolving an order item into CiviCRM directives.
 *
 * A directive is an associative array describing one CiviCRM record to create
 * for the order item:
 * - type: 'membership' | 'contribution' | 'event' | 'mailing'.
 * - membership_type: membership type name or ID (membership directives).
 * - financial_type: financial type name or ID (falls back to the membership
 *   type's financial type for membership directives, 'Event Fee' for events).
 * - event_id: CiviCRM event ID (event directives).
 * - participant_role_id: participant role (event directives).
 * - group: group name or ID (mailing directives).
 * - mailing_preferences: array of mailing options (mailing directives).
 * - amount: decimal string, the line total attributed to this directive.
 * - currency: currency code.
 * - quantity: int.
 * - unit_price: decimal string.
 * - label: line item label.
 * - order_item_id: the source Commerce order item ID.
 */
class OrderItemDirectivesEvent extends Event {

  /**
   * Constructs the event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being processed.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $orderItem
   *   The order item the directives were built from.
   * @param int $contactId
   *   The CiviCRM contact ID resolved for the order.
   * @param array $directives
   *   The default directives built from the product's field_civicrm value.
   * @param array $context
   *   Processing context: 'phase' => 'create' | 'cancel' | 'renewal', plus
   *   transition info ('transition', 'from_state', 'to_state', 'workflow')
   *   when triggered by a workflow transition.
   */
  public function __construct(
    protected readonly OrderInterface $order,
    protected readonly OrderItemInterface $orderItem,
    protected readonly int $contactId,
    protected array $directives,
    protected readonly array $context = [],
  ) {}

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the order item.
   */
  public function getOrderItem(): OrderItemInterface {
    return $this->orderItem;
  }

  /**
   * Gets the CiviCRM contact ID.
   */
  public function getContactId(): int {
    return $this->contactId;
  }

  /**
   * Gets the directives.
   */
  public function getDirectives(): array {
    return $this->directives;
  }

  /**
   * Replaces the directives.
   */
  public function setDirectives(array $directives): void {
    $this->directives = $directives;
  }

  /**
   * Gets the processing context.
   */
  public function getContext(): array {
    return $this->context;
  }

}
