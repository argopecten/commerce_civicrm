<?php

namespace Drupal\Tests\commerce_civicrm\Unit;

use Drupal\commerce_civicrm\EventSubscriber\OrderCompleteSubscriber;
use Drupal\commerce_civicrm\Service\OrderCivicrmUpdater;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowState;
use Drupal\state_machine\Plugin\Workflow\WorkflowTransition;
use PHPUnit\Framework\TestCase;

/**
 * Tests the transition routing of the order subscriber.
 *
 * @coversDefaultClass \Drupal\commerce_civicrm\EventSubscriber\OrderCompleteSubscriber
 * @group commerce_civicrm
 */
class OrderCompleteSubscriberTest extends TestCase {

  /**
   * Builds a subscriber with mocked config and a spying updater.
   */
  protected function buildSubscriber(array $settings, &$calls): OrderCompleteSubscriber {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key) => $settings[$key] ?? NULL
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('commerce_civicrm.settings')->willReturn($config);

    $updater = $this->createMock(OrderCivicrmUpdater::class);
    $updater->method('processOrder')->willReturnCallback(
      static function () use (&$calls) {
        $calls[] = 'create';
        return [];
      }
    );
    $updater->method('processCancellation')->willReturnCallback(
      static function () use (&$calls) {
        $calls[] = 'cancel';
        return [];
      }
    );

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    return new OrderCompleteSubscriber($logger_factory, $updater, $config_factory);
  }

  /**
   * Builds a workflow transition event for an order.
   */
  protected function buildEvent(string $transition_id, string $workflow_id, string $from = 'a', string $to = 'b'): WorkflowTransitionEvent {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getEntityTypeId')->willReturn('commerce_order');
    $order->method('id')->willReturn('42');

    $from_state = new WorkflowState($from, $from);
    $to_state = new WorkflowState($to, $to);
    $transition = new WorkflowTransition($transition_id, $transition_id, [$from_state], $to_state);

    $workflow = $this->createMock(WorkflowInterface::class);
    $workflow->method('getId')->willReturn($workflow_id);
    $workflow->method('getState')->willReturnCallback(
      static fn (string $id) => $id === $from ? $from_state : $to_state
    );

    $event = $this->createMock(WorkflowTransitionEvent::class);
    $event->method('getEntity')->willReturn($order);
    $event->method('getWorkflow')->willReturn($workflow);
    $event->method('getTransition')->willReturn($transition);
    $event->method('getFromState')->willReturn($from_state);
    $event->method('getToState')->willReturn($to_state);

    return $event;
  }

  /**
   * @covers ::onTransition
   */
  public function testConfiguredCreateTransitionTriggersProcessing(): void {
    $calls = [];
    $subscriber = $this->buildSubscriber([
      'order.create_transitions' => ['paid', 'completed'],
      'order.cancel_transitions' => ['cancel'],
      'order.workflows' => [],
    ], $calls);

    $subscriber->onTransition($this->buildEvent('completed', 'magyar_hang_workflow'));
    $this->assertSame(['create'], $calls);
  }

  /**
   * @covers ::onTransition
   */
  public function testUnconfiguredTransitionIsIgnored(): void {
    $calls = [];
    $subscriber = $this->buildSubscriber([
      'order.create_transitions' => ['place'],
      'order.cancel_transitions' => ['cancel'],
      'order.workflows' => [],
    ], $calls);

    $subscriber->onTransition($this->buildEvent('fulfill', 'order_default'));
    $this->assertSame([], $calls);
  }

  /**
   * @covers ::onTransition
   */
  public function testCancelTransitionTriggersCancellation(): void {
    $calls = [];
    $subscriber = $this->buildSubscriber([
      'order.create_transitions' => ['place'],
      'order.cancel_transitions' => ['cancel'],
      'order.workflows' => [],
    ], $calls);

    $subscriber->onTransition($this->buildEvent('cancel', 'order_default', 'pending', 'canceled'));
    $this->assertSame(['cancel'], $calls);
  }

  /**
   * @covers ::onTransition
   */
  public function testWorkflowAllowlistFiltersOtherWorkflows(): void {
    $calls = [];
    $subscriber = $this->buildSubscriber([
      'order.create_transitions' => ['paid'],
      'order.cancel_transitions' => ['cancel'],
      'order.workflows' => ['magyar_hang_workflow'],
    ], $calls);

    $subscriber->onTransition($this->buildEvent('paid', 'order_default'));
    $this->assertSame([], $calls);

    $subscriber->onTransition($this->buildEvent('paid', 'magyar_hang_workflow'));
    $this->assertSame(['create'], $calls);
  }

}
