<?php

namespace Drupal\Tests\commerce_civicrm\Unit;

use Drupal\commerce_civicrm\Util\AmountSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Tests the proportional amount splitter.
 *
 * @coversDefaultClass \Drupal\commerce_civicrm\Util\AmountSplitter
 * @group commerce_civicrm
 */
class AmountSplitterTest extends TestCase {

  /**
   * @covers ::splitProportionally
   * @dataProvider providerSplit
   */
  public function testSplitProportionally(string $total, array $weights, array $expected): void {
    $this->assertSame($expected, AmountSplitter::splitProportionally($total, $weights));
  }

  /**
   * Data provider for testSplitProportionally().
   */
  public static function providerSplit(): array {
    return [
      'single part gets everything' => [
        '2990.00', ['a' => '2990.00'], ['a' => '2990.00'],
      ],
      'proportional two-way split' => [
        '3000.00', ['print' => '1000.00', 'pdf' => '2000.00'],
        ['print' => '1000.00', 'pdf' => '2000.00'],
      ],
      'discounted bundle keeps proportions and exact sum' => [
        '2500.00', ['print' => '2000.00', 'pdf' => '1000.00'],
        ['print' => '1666.66', 'pdf' => '833.34'],
      ],
      'rounding remainder lands on the last part' => [
        '100.00', ['a' => '1', 'b' => '1', 'c' => '1'],
        ['a' => '33.33', 'b' => '33.33', 'c' => '33.34'],
      ],
      'zero weights split evenly' => [
        '90.00', ['a' => '0', 'b' => '0', 'c' => '0'],
        ['a' => '30.00', 'b' => '30.00', 'c' => '30.00'],
      ],
      'empty weights yield empty result' => [
        '100.00', [], [],
      ],
    ];
  }

  /**
   * The split must always sum exactly to the total.
   *
   * @covers ::splitProportionally
   */
  public function testSumInvariant(): void {
    $weights = ['a' => '1234.56', 'b' => '78.90', 'c' => '0.01', 'd' => '999.99'];
    $amounts = AmountSplitter::splitProportionally('5990.00', $weights);
    $sum = '0';
    foreach ($amounts as $amount) {
      $sum = bcadd($sum, $amount, 2);
    }
    $this->assertSame('5990.00', $sum);
  }

}
