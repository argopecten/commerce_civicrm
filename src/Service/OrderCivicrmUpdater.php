<?php

namespace Drupal\commerce_civicrm\Service;

use Drupal\commerce_civicrm\Event\CommerceCivicrmEvents;
use Drupal\commerce_civicrm\Event\ContributionParamsEvent;
use Drupal\commerce_civicrm\Event\MembershipDatesEvent;
use Drupal\commerce_civicrm\Event\OrderItemDirectivesEvent;
use Drupal\commerce_civicrm\Event\OrderProcessedEvent;
use Drupal\commerce_civicrm\Util\AmountSplitter;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Processes Commerce orders into CiviCRM records.
 *
 * One order produces at most one CiviCRM contribution, created through the
 * CiviCRM Order API with one line item per directive: membership line items
 * (entity_table civicrm_membership), participant line items for event
 * products, and plain contribution line items. Directives are resolved from
 * each order item's product field_civicrm configuration and can be altered
 * or expanded by OrderItemDirectivesEvent subscribers. Mailing directives are
 * handled outside the contribution (GroupContact records).
 */
class OrderCivicrmUpdater {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an OrderCivicrmUpdater object.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    protected readonly CivicrmHelper $civicrmHelper,
    protected readonly ContactUpdater $contactUpdater,
    protected readonly ContributionUpdater $contributionUpdater,
    protected readonly MembershipUpdater $membershipUpdater,
    protected readonly MailingUpdater $mailingUpdater,
    protected readonly ParticipantUpdater $participantUpdater,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
  ) {
    $this->logger = $logger_factory->get('commerce_civicrm');
  }

  /**
   * Processes an order and creates the appropriate CiviCRM records.
   *
   * Idempotent per order: if a contribution linked to the order already
   * exists, no new records are created.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order to process.
   * @param array $context
   *   Processing context; transition info when triggered by a workflow
   *   transition. The 'phase' key is set to 'create' by this method.
   *
   * @return array
   *   Created (or already existing) CiviCRM record IDs keyed by type.
   */
  public function processOrder(OrderInterface $order, array $context = []): array {
    $context['phase'] = 'create';

    if (!$this->civicrmHelper->isReadyForOperations()) {
      $this->logger->error('CiviCRM is not available - skipping order @order_id processing', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    $contact_id = $this->resolveContactId($order);
    if (!$contact_id) {
      return [];
    }

    $this->civicrmHelper->ensureCommerceOrderCustomFields();

    // Idempotency: one contribution per order.
    $existing_contribution_id = $this->contributionUpdater->findExistingContributionForOrder($order);
    if ($existing_contribution_id) {
      $this->logger->info('Contribution already exists for order @order_id: @contribution_id - skipping', [
        '@order_id' => $order->id(),
        '@contribution_id' => $existing_contribution_id,
      ]);
      return ['contributions' => [$existing_contribution_id]];
    }

    $directives = $this->buildOrderDirectives($order, $contact_id, $context);
    if ($directives === []) {
      $this->logger->info('Order @order_id has no CiviCRM-enabled items - nothing to do', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    [$financial_directives, $mailing_directives] = $this->partitionDirectives($directives);

    $records = [];

    if ($financial_directives !== []) {
      $payment = $this->contributionUpdater->getLatestCompletedPayment($order);
      $records = $this->createCiviOrder($order, $contact_id, $financial_directives, $payment, $context);
    }

    foreach ($mailing_directives as $directive) {
      $group_id = $this->civicrmHelper->resolveGroupId($directive['group'] ?? NULL);
      if (!$group_id) {
        continue;
      }
      $success = $this->mailingUpdater->processMailingSubscriptionFromOrder(
        $contact_id,
        $group_id,
        $order,
        $directive['mailing_preferences'] ?? []
      );
      if ($success) {
        $records['mailings'][] = $group_id;
      }
    }

    $this->logger->info('Completed processing order @order_id. Created records: @records', [
      '@order_id' => $order->id(),
      '@records' => json_encode($records),
    ]);

    $this->eventDispatcher->dispatch(
      new OrderProcessedEvent($order, $contact_id, $records, $context),
      CommerceCivicrmEvents::ORDER_PROCESSED
    );

    return $records;
  }

  /**
   * Processes an order cancellation.
   *
   * Cancels the contribution linked to the order and the memberships and
   * participants created through its line items, and removes the contact
   * from mailing groups configured on the order's products.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The cancelled order.
   * @param array $context
   *   Processing context. The 'phase' key is set to 'cancel'.
   *
   * @return array
   *   Cancelled CiviCRM record IDs keyed by type.
   */
  public function processCancellation(OrderInterface $order, array $context = []): array {
    $context['phase'] = 'cancel';

    if (!$this->civicrmHelper->isReadyForOperations()) {
      $this->logger->error('CiviCRM is not available - skipping cancellation for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    $contact_id = $this->resolveContactId($order);
    if (!$contact_id) {
      return [];
    }

    $cancelled = [];

    // Cancel exactly what was created: walk the line items of every
    // contribution linked to the order (initial and renewals).
    $contribution_ids = $this->contributionUpdater->findAllContributionsForOrder($order);
    if ($contribution_ids !== []) {
      $seen = [];
      foreach ($contribution_ids as $contribution_id) {
        foreach ($this->getContributionLineEntities($contribution_id) as $line) {
          $key = $line['entity_table'] . ':' . $line['entity_id'];
          if (isset($seen[$key])) {
            continue;
          }
          $seen[$key] = TRUE;
          try {
            if ($line['entity_table'] === 'civicrm_membership') {
              if ($this->membershipUpdater->cancelMembershipById((int) $line['entity_id'], $order)) {
                $cancelled['memberships'][] = (int) $line['entity_id'];
              }
            }
            elseif ($line['entity_table'] === 'civicrm_participant') {
              if ($this->participantUpdater->cancelParticipant((int) $line['entity_id'], $order)) {
                $cancelled['participants'][] = (int) $line['entity_id'];
              }
            }
          }
          catch (\Throwable $e) {
            $this->logger->error('Error cancelling @table @id for order @order_id: @error', [
              '@table' => $line['entity_table'],
              '@id' => $line['entity_id'],
              '@order_id' => $order->id(),
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }

      $cancelled_contributions = $this->contributionUpdater->cancelContributionsFromOrder($order);
      if ($cancelled_contributions !== []) {
        $cancelled['contributions'] = $cancelled_contributions;
      }
    }
    else {
      $this->logger->info('No contribution found for order @order_id - nothing to cancel financially', [
        '@order_id' => $order->id(),
      ]);
    }

    // Mailing groups are not linked to the contribution; re-derive them from
    // the order's directives.
    foreach ($this->buildOrderDirectives($order, $contact_id, $context) as $directive) {
      if (($directive['type'] ?? NULL) !== 'mailing') {
        continue;
      }
      $group_id = $this->civicrmHelper->resolveGroupId($directive['group'] ?? NULL);
      if ($group_id && $this->mailingUpdater->removeContactFromMailingGroup($contact_id, $group_id)) {
        $cancelled['mailings'][] = $group_id;
      }
    }

    $this->logger->info('Completed cancellation processing for order @order_id. Cancelled records: @records', [
      '@order_id' => $order->id(),
      '@records' => json_encode($cancelled),
    ]);

    $this->eventDispatcher->dispatch(
      new OrderProcessedEvent($order, $contact_id, $cancelled, $context),
      CommerceCivicrmEvents::ORDER_CANCELLED
    );

    return $cancelled;
  }

  /**
   * Resolves the CiviCRM contact for an order.
   *
   * Uses the UFMatch of the order's customer; when contact.fallback is
   * 'match_or_create', falls back to matching/creating a contact from the
   * order's billing information.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int|null
   *   The contact ID, or NULL if it cannot be resolved.
   */
  public function resolveContactId(OrderInterface $order): ?int {
    $customer = $order->getCustomer();
    if ($customer && !$customer->isAnonymous()) {
      $contact_id = $this->contactUpdater->getContactIdByUser($customer);
      if ($contact_id) {
        return $contact_id;
      }
    }

    $fallback = $this->configFactory->get('commerce_civicrm.settings')->get('contact.fallback');
    if ($fallback === 'match_or_create') {
      $contact_id = $this->contactUpdater->matchOrCreateContactFromOrder($order);
      if ($contact_id) {
        return $contact_id;
      }
    }

    $this->logger->warning('Could not resolve a CiviCRM contact for order @order_id (customer @uid, fallback: @fallback)', [
      '@order_id' => $order->id(),
      '@uid' => $customer ? $customer->id() : 'none',
      '@fallback' => $fallback ?: 'none',
    ]);
    return NULL;
  }

  /**
   * Builds the CiviCRM directives for all items of an order.
   *
   * For each order item the default directive is derived from the product's
   * field_civicrm configuration, then OrderItemDirectivesEvent lets
   * subscribers replace or expand it (e.g. bundle component splitting).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param array $context
   *   Processing context.
   *
   * @return array
   *   A flat list of directives.
   *
   * @see \Drupal\commerce_civicrm\Event\OrderItemDirectivesEvent
   */
  public function buildOrderDirectives(OrderInterface $order, int $contact_id, array $context = []): array {
    $directives = [];
    foreach ($order->getItems() as $order_item) {
      $item_directives = $this->buildDefaultDirectives($order_item);

      $event = new OrderItemDirectivesEvent($order, $order_item, $contact_id, $item_directives, $context);
      $this->eventDispatcher->dispatch($event, CommerceCivicrmEvents::ORDER_ITEM_DIRECTIVES);

      foreach ($event->getDirectives() as $directive) {
        $directives[] = $directive;
      }
    }
    return $directives;
  }

  /**
   * Builds the default directives for one order item from field_civicrm.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return array
   *   Zero or one directive.
   */
  protected function buildDefaultDirectives(OrderItemInterface $order_item): array {
    $variation = $order_item->getPurchasedEntity();
    if (!$variation || !method_exists($variation, 'getProduct')) {
      return [];
    }
    $product = $variation->getProduct();
    if (!$product) {
      return [];
    }

    $settings = $this->getCivicrmProductSettings($product);
    if (empty($settings['enabled'])) {
      return [];
    }

    $total_price = $order_item->getTotalPrice();
    if (!$total_price) {
      return [];
    }

    $directive = [
      'type' => $settings['entity'],
      'amount' => $total_price->getNumber(),
      'currency' => $total_price->getCurrencyCode(),
      'quantity' => (int) $order_item->getQuantity(),
      'unit_price' => $order_item->getUnitPrice()?->getNumber() ?? $total_price->getNumber(),
      'label' => $order_item->getTitle(),
      'order_item_id' => (int) $order_item->id(),
    ];

    switch ($settings['entity']) {
      case 'membership':
        $directive['membership_type'] = $settings['membership_type'];
        $directive['financial_type'] = $settings['financial_type'];
        break;

      case 'contribution':
        $directive['financial_type'] = $settings['financial_type'];
        break;

      case 'event':
        $directive['event_id'] = $settings['event_id'];
        $directive['participant_role_id'] = $settings['participant_role_id'];
        $directive['financial_type'] = $settings['financial_type'];
        break;

      case 'mailing':
        $directive['group'] = $settings['group'];
        $directive['mailing_preferences'] = $settings['mailing_preferences'] ?? [];
        break;

      default:
        return [];
    }

    return [$directive];
  }

  /**
   * Splits directives into financial (contribution-producing) and mailing.
   *
   * @param array $directives
   *   The directives.
   *
   * @return array
   *   A two-element array: [financial directives, mailing directives].
   */
  protected function partitionDirectives(array $directives): array {
    $financial = [];
    $mailing = [];
    foreach ($directives as $directive) {
      if (($directive['type'] ?? NULL) === 'mailing') {
        $mailing[] = $directive;
      }
      elseif (in_array($directive['type'] ?? NULL, ['membership', 'contribution', 'event'], TRUE)) {
        $financial[] = $directive;
      }
    }
    return [$financial, $mailing];
  }

  /**
   * Creates one CiviCRM contribution (with line items) via the Order API.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param array $directives
   *   The financial directives.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface|null $payment
   *   The Commerce payment this contribution reflects, if any.
   * @param array $context
   *   Processing context.
   * @param string|null $total_override
   *   Optional total amount overriding the directive sum (used for renewals
   *   where the charge may differ from the catalog prices).
   *
   * @return array
   *   Created CiviCRM record IDs keyed by type.
   */
  public function createCiviOrder(OrderInterface $order, int $contact_id, array $directives, ?PaymentInterface $payment, array $context = [], ?string $total_override = NULL): array {
    $is_renewal = ($context['phase'] ?? NULL) === 'renewal';

    if ($total_override !== NULL) {
      $directives = $this->scaleDirectiveAmounts($directives, $total_override);
    }

    $line_items = [];
    $total = '0';
    $currency = NULL;
    $header_financial_type_id = NULL;

    foreach ($directives as $directive) {
      $line = $this->buildLineItem($order, $contact_id, $directive, $is_renewal);
      if ($line === NULL) {
        continue;
      }
      $line_items[] = $line;
      $total = bcadd($total, $line['line_item']['line_total'], 2);
      $currency = $currency ?? $directive['currency'] ?? NULL;
      $header_financial_type_id = $header_financial_type_id ?? $line['line_item']['financial_type_id'];
    }

    if ($line_items === []) {
      $this->logger->warning('No processable financial directives for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return [];
    }

    $timestamp = $payment?->getCompletedTime()
      ?: ($order->getCompletedTime() ?: $this->time->getRequestTime());

    $contribution_values = [
      'contact_id' => $contact_id,
      'financial_type_id' => $header_financial_type_id,
      'total_amount' => $total,
      'currency' => $currency ?: 'EUR',
      'receive_date' => DrupalDateTime::createFromTimestamp($timestamp)->format('Y-m-d H:i:s'),
      'source' => 'Commerce Order #' . $order->id() . ($is_renewal ? ' (Renewal)' : ''),
      'contribution_status_id:name' => $this->isOrderPaid($order) ? 'Completed' : 'Pending',
      'Commerce_Order.commerce_order_id' => (int) $order->id(),
    ];

    if ($payment) {
      $contribution_values['Commerce_Order.commerce_payment_id'] = (int) $payment->id();
      if ($payment->getRemoteId()) {
        $contribution_values['trxn_id'] = $payment->getRemoteId();
      }
      $instrument_id = $this->contributionUpdater->getPaymentInstrumentId($payment);
      if ($instrument_id) {
        $contribution_values['payment_instrument_id'] = $instrument_id;
      }
    }

    $event = new ContributionParamsEvent($order, $contribution_values, $line_items, $payment, $context);
    $this->eventDispatcher->dispatch($event, CommerceCivicrmEvents::CONTRIBUTION_PARAMS);
    $contribution_values = $event->getContributionValues();
    $line_items = $event->getLineItems();

    try {
      $api = \Civi\Api4\Order::create(FALSE)
        ->setContributionValues($contribution_values);
      foreach ($line_items as $line) {
        $api->addLineItem($line);
      }
      $result = $api->execute();

      if ($result->count() === 0) {
        $this->logger->warning('Order::create returned no results for order @order_id', [
          '@order_id' => $order->id(),
        ]);
        return [];
      }

      $created = $result->first();
      $contribution_id = is_array($created) ? ($created['id'] ?? $created['contribution_id'] ?? NULL) : NULL;

      $records = [];
      if ($contribution_id) {
        $records['contributions'][] = (int) $contribution_id;
        foreach ($this->getContributionLineEntities((int) $contribution_id) as $line) {
          if ($line['entity_table'] === 'civicrm_membership') {
            $records['memberships'][] = (int) $line['entity_id'];
          }
          elseif ($line['entity_table'] === 'civicrm_participant') {
            $records['participants'][] = (int) $line['entity_id'];
          }
        }
        $records['memberships'] = array_values(array_unique($records['memberships'] ?? []));
        $records['participants'] = array_values(array_unique($records['participants'] ?? []));
        $records = array_filter($records);
      }

      return $records;
    }
    catch (\Throwable $e) {
      $this->logger->error('Error creating CiviCRM order for Commerce order @order_id: @error', [
        '@order_id' => $order->id(),
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds one Order API line item from a directive.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $contact_id
   *   The CiviCRM contact ID.
   * @param array $directive
   *   The directive.
   * @param bool $is_renewal
   *   Whether this line belongs to a renewal contribution.
   *
   * @return array|null
   *   The line item ['line_item' => [...], 'params' => [...]], or NULL when
   *   the directive cannot be processed.
   */
  protected function buildLineItem(OrderInterface $order, int $contact_id, array $directive, bool $is_renewal): ?array {
    $source = 'Commerce Order #' . $order->id() . ($is_renewal ? ' (Renewal)' : '');
    $qty = max(1, (int) ($directive['quantity'] ?? 1));
    $line_total = $directive['amount'] ?? '0';
    $unit_price = $directive['unit_price'] ?? bcdiv($line_total, (string) $qty, 2);

    switch ($directive['type']) {
      case 'membership':
        $membership_type_id = $this->civicrmHelper->resolveMembershipTypeId($directive['membership_type'] ?? NULL);
        if (!$membership_type_id) {
          $this->logger->warning('Cannot resolve membership type "@type" (order @order_id) - skipping directive', [
            '@type' => $directive['membership_type'] ?? '',
            '@order_id' => $order->id(),
          ]);
          return NULL;
        }
        $membership_type = $this->membershipUpdater->getMembershipTypeDetails($membership_type_id);
        if (!$membership_type) {
          return NULL;
        }

        $financial_type_id = $this->civicrmHelper->resolveFinancialTypeId($directive['financial_type'] ?? NULL)
          ?: ($membership_type['financial_type_id'] ?? NULL);
        if (!$financial_type_id) {
          $this->logger->warning('No financial type for membership directive (order @order_id) - skipping', [
            '@order_id' => $order->id(),
          ]);
          return NULL;
        }

        $existing_membership = $this->membershipUpdater->findExistingMembership($contact_id, $membership_type_id);

        $params = [
          'membership_type_id' => $membership_type_id,
          'contact_id' => $contact_id,
          'source' => $source,
        ];
        if ($existing_membership) {
          $params['membership_id'] = $existing_membership['id'];
        }

        foreach ($this->resolveMembershipDates($order, $directive, $membership_type, $existing_membership, $is_renewal || (bool) $existing_membership) as $key => $value) {
          if ($value !== NULL) {
            $params[$key] = $value;
          }
        }

        return [
          'line_item' => [
            'entity_table' => 'civicrm_membership',
            'financial_type_id' => $financial_type_id,
            'label' => $directive['label'] ?? $membership_type['name'],
            'qty' => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
          ],
          'params' => $params,
        ];

      case 'event':
        $event_id = $directive['event_id'] ?? NULL;
        if (!$event_id) {
          $this->logger->warning('Event directive without event_id (order @order_id) - skipping', [
            '@order_id' => $order->id(),
          ]);
          return NULL;
        }
        $financial_type_id = $this->civicrmHelper->resolveFinancialTypeId($directive['financial_type'] ?? NULL)
          ?: $this->civicrmHelper->resolveFinancialTypeId('Event Fee');
        if (!$financial_type_id) {
          $this->logger->warning('No financial type for event directive (order @order_id) - skipping', [
            '@order_id' => $order->id(),
          ]);
          return NULL;
        }

        $params = [
          'event_id' => (int) $event_id,
          'contact_id' => $contact_id,
          'status_id:name' => 'Registered',
          'source' => $source,
        ];
        if (!empty($directive['participant_role_id'])) {
          $params['role_id'] = (int) $directive['participant_role_id'];
        }

        return [
          'line_item' => [
            'entity_table' => 'civicrm_participant',
            'financial_type_id' => $financial_type_id,
            'label' => $directive['label'] ?? ('Event #' . $event_id),
            'qty' => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
          ],
          'params' => $params,
        ];

      case 'contribution':
        $financial_type_id = $this->civicrmHelper->resolveFinancialTypeId($directive['financial_type'] ?? NULL);
        if (!$financial_type_id) {
          $this->logger->warning('Cannot resolve financial type "@type" (order @order_id) - skipping directive', [
            '@type' => $directive['financial_type'] ?? '',
            '@order_id' => $order->id(),
          ]);
          return NULL;
        }
        return [
          'line_item' => [
            'financial_type_id' => $financial_type_id,
            'label' => $directive['label'] ?? '',
            'qty' => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
          ],
          'params' => [],
        ];
    }

    return NULL;
  }

  /**
   * Resolves membership dates according to the configured date mode.
   *
   * @return array
   *   join_date / start_date / end_date; NULL values are omitted by callers.
   */
  protected function resolveMembershipDates(OrderInterface $order, array $directive, array $membership_type, ?array $existing_membership, bool $is_renewal): array {
    $date_mode = $this->configFactory->get('commerce_civicrm.settings')->get('membership.date_mode');
    if ($date_mode !== 'dispatch') {
      // Let CiviCRM compute the end date from the membership type; on renewal
      // pass no dates at all so the renewal logic extends the membership.
      if ($is_renewal) {
        return [];
      }
      $today = (new \DateTimeImmutable())->format('Y-m-d');
      return ['join_date' => $today, 'start_date' => $today];
    }

    $event = new MembershipDatesEvent($order, $directive, $membership_type, $existing_membership, $is_renewal);
    $this->eventDispatcher->dispatch($event, CommerceCivicrmEvents::MEMBERSHIP_DATES);
    return $event->getDates();
  }

  /**
   * Scales directive amounts proportionally so they sum to a target total.
   *
   * @param array $directives
   *   The financial directives.
   * @param string $total
   *   The target total (decimal string).
   *
   * @return array
   *   The directives with adjusted amounts.
   */
  protected function scaleDirectiveAmounts(array $directives, string $total): array {
    $weights = [];
    foreach ($directives as $key => $directive) {
      $weights[$key] = $directive['amount'] ?? '0';
    }
    $amounts = AmountSplitter::splitProportionally($total, $weights);
    foreach ($amounts as $key => $amount) {
      $directives[$key]['amount'] = $amount;
      $directives[$key]['unit_price'] = bcdiv($amount, (string) max(1, (int) ($directives[$key]['quantity'] ?? 1)), 2);
    }
    return $directives;
  }

  /**
   * Whether the order counts as paid for contribution status purposes.
   */
  protected function isOrderPaid(OrderInterface $order): bool {
    if (in_array($order->getState()->getId(), ['completed', 'shipped', 'paid'], TRUE)) {
      return TRUE;
    }
    $balance = $order->getBalance();
    return $balance !== NULL && ($balance->isZero() || $balance->isNegative());
  }

  /**
   * Gets the entity references of a contribution's line items.
   *
   * @param int $contribution_id
   *   The contribution ID.
   *
   * @return array
   *   List of ['entity_table' => ..., 'entity_id' => ...] rows, excluding
   *   the contribution's own line items.
   */
  public function getContributionLineEntities(int $contribution_id): array {
    try {
      $result = \Civi\Api4\LineItem::get(FALSE)
        ->addSelect('entity_table', 'entity_id')
        ->addWhere('contribution_id', '=', $contribution_id)
        ->execute();

      $lines = [];
      foreach ($result as $row) {
        if (!empty($row['entity_table']) && $row['entity_table'] !== 'civicrm_contribution') {
          $lines[] = ['entity_table' => $row['entity_table'], 'entity_id' => $row['entity_id']];
        }
      }
      return $lines;
    }
    catch (\CRM_Core_Exception $e) {
      $this->logger->error('Error loading line items of contribution @cid: @error', [
        '@cid' => $contribution_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Gets CiviCRM settings from a product's field_civicrm value.
   *
   * Expected JSON shape (type references may be names or numeric IDs):
   * @code
   * {"enabled": true, "entity": "membership",
   *  "membership_type": "...", "financial_type": "..."}
   * {"enabled": true, "entity": "contribution", "financial_type": "..."}
   * {"enabled": true, "entity": "event", "event_id": 3,
   *  "participant_role_id": 1}
   * {"enabled": true, "entity": "mailing", "group": "...",
   *  "mailing_preferences": {...}}
   * @endcode
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return array
   *   The settings with defaults applied.
   */
  public function getCivicrmProductSettings(ProductInterface $product): array {
    $defaults = [
      'enabled' => FALSE,
      'entity' => 'contribution',
      'membership_type' => NULL,
      'financial_type' => NULL,
      'event_id' => NULL,
      'participant_role_id' => NULL,
      'group' => NULL,
      'mailing_preferences' => [],
    ];

    if (!$product->hasField('field_civicrm') || $product->get('field_civicrm')->isEmpty()) {
      return $defaults;
    }

    $settings = json_decode($product->get('field_civicrm')->value ?? '', TRUE);
    if (!is_array($settings)) {
      return $defaults;
    }

    return array_merge($defaults, $settings);
  }

}
