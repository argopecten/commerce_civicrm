<?php

namespace Drupal\commerce_civicrm\Util;

/**
 * Splits a monetary total proportionally across weighted parts.
 *
 * Used to distribute a paid amount over bundle components (or to scale
 * directive amounts to a renewal charge) so the parts sum exactly to the
 * total: every part but the last is rounded, the last takes the remainder.
 */
final class AmountSplitter {

  /**
   * Splits a total proportionally to the given weights.
   *
   * @param string $total
   *   The total amount as a decimal string.
   * @param string[] $weights
   *   Positive weights (e.g. component standalone prices) as decimal strings.
   * @param int $scale
   *   Number of decimal places of the result amounts.
   *
   * @return string[]
   *   Amounts (decimal strings, same keys as $weights) summing to $total.
   */
  public static function splitProportionally(string $total, array $weights, int $scale = 2): array {
    if ($weights === []) {
      return [];
    }

    $weight_sum = '0';
    foreach ($weights as $weight) {
      $weight_sum = bcadd($weight_sum, $weight, 6);
    }

    $keys = array_keys($weights);
    $amounts = [];
    $allocated = '0';

    if (bccomp($weight_sum, '0', 6) === 0) {
      // Degenerate case (all weights zero): split evenly.
      $count = count($weights);
      $even = bcdiv($total, (string) $count, $scale);
      foreach ($keys as $i => $key) {
        if ($i === $count - 1) {
          $amounts[$key] = bcsub($total, $allocated, $scale);
        }
        else {
          $amounts[$key] = $even;
          $allocated = bcadd($allocated, $even, $scale);
        }
      }
      return $amounts;
    }

    $last = count($keys) - 1;
    foreach ($keys as $i => $key) {
      if ($i === $last) {
        $amounts[$key] = bcsub($total, $allocated, $scale);
      }
      else {
        $share = bcdiv(bcmul($total, $weights[$key], 6), $weight_sum, $scale);
        $amounts[$key] = $share;
        $allocated = bcadd($allocated, $share, $scale);
      }
    }

    return $amounts;
  }

}
