<?php

namespace Drupal\commerce_civicrm\Event;

/**
 * Defines the events dispatched by the Commerce CiviCRM module.
 */
final class CommerceCivicrmEvents {

  /**
   * Fired while resolving an order item into CiviCRM directives.
   *
   * Subscribers may inspect, replace or expand the directives built from the
   * product's field_civicrm configuration — e.g. split a bundle product into
   * one directive per component.
   *
   * @Event
   *
   * @see \Drupal\commerce_civicrm\Event\OrderItemDirectivesEvent
   */
  const ORDER_ITEM_DIRECTIVES = 'commerce_civicrm.order_item_directives';

  /**
   * Fired to determine membership dates when date_mode is 'dispatch'.
   *
   * @Event
   *
   * @see \Drupal\commerce_civicrm\Event\MembershipDatesEvent
   */
  const MEMBERSHIP_DATES = 'commerce_civicrm.membership_dates';

  /**
   * Fired immediately before the CiviCRM Order/Contribution API call.
   *
   * @Event
   *
   * @see \Drupal\commerce_civicrm\Event\ContributionParamsEvent
   */
  const CONTRIBUTION_PARAMS = 'commerce_civicrm.contribution_params';

  /**
   * Fired after an order has been processed into CiviCRM records.
   *
   * @Event
   *
   * @see \Drupal\commerce_civicrm\Event\OrderProcessedEvent
   */
  const ORDER_PROCESSED = 'commerce_civicrm.order_processed';

  /**
   * Fired after an order cancellation has been processed.
   *
   * @Event
   *
   * @see \Drupal\commerce_civicrm\Event\OrderProcessedEvent
   */
  const ORDER_CANCELLED = 'commerce_civicrm.order_cancelled';

  /**
   * Fired after a renewal payment has been recorded.
   *
   * @Event
   *
   * @see \Drupal\commerce_civicrm\Event\RenewalRecordedEvent
   */
  const RENEWAL_RECORDED = 'commerce_civicrm.renewal_recorded';

}
