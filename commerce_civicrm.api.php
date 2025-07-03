<?php
<?php

/**
 * Example: Alter CiviCRM contact params before sending to CiviCRM.
 *
 * @param array $params
 *   CiviCRM contact update params array (by reference).
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   Commerce order entity.
 * @param int $cid
 *   CiviCRM contact ID.
 */
function commerce_civicrm_example_alter_contact_params(array &$params, \Drupal\commerce_order\Entity\OrderInterface $order, $cid) {
  // Get the billing profile from the order.
  $profile = $order->getBillingProfile();
  if (!$profile) {
    return;
  }

  // Example: Add custom fields from the billing profile to CiviCRM params.
  if ($profile->hasField('field_job_title')) {
    $params['job_title'] = $profile->get('field_job_title')->value;
  }
  if ($profile->hasField('field_organisation')) {
    $params['current_employer'] = $profile->get('field_organisation')->value;
  }
  if ($profile->hasField('field_phone')) {
    $params['phone'] = [
      [
        'is_primary' => TRUE,
        'phone' => $profile->get('field_phone')->value,
        'phone_type_id' => 1,
        'location_type' => 'Home',
        'sequential' => 0,
      ],
    ];
  }

  // Update the contact in CiviCRM.
  $params['id'] = $params['contact_id'] ?? $cid;
  try {
    civicrm_api3('Contact', 'create', $params);
  }
  catch (\Exception $e) {
    \Drupal::logger('commerce_civicrm')->error('CiviCRM contact update failed: @message', ['@message' => $e->getMessage()]);
  }
}

/**
 * Example: Alter CiviCRM contribution params before sending to CiviCRM.
 *
 * @param array $params
 *   CiviCRM contribution params array (by reference).
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   Commerce order entity.
 * @param int $cid
 *   CiviCRM contact ID.
 * @param \Drupal\commerce_payment\Entity\PaymentInterface|null $payment
 *   Commerce payment entity (optional).
 */
function commerce_civicrm_example_alter_contribution_params(array &$params, \Drupal\commerce_order\Entity\OrderInterface $order, $cid, $payment = NULL) {
  $params['contact_id'] = $cid;
  $params['receive_date'] = date('Y-m-d');
  $params['total_amount'] = $order->getTotalPrice()->getNumber();
  $params['currency'] = $order->getTotalPrice()->getCurrencyCode();
  $params['source'] = 'Drupal Commerce Order ' . $order->id();
  $params['contribution_status_id'] = 'Completed';

  // Add payment details if available.
  if ($payment) {
    $params['trxn_id'] = $payment->getRemoteId();
    $params['payment_instrument_id'] = 1; // Adjust as needed.
  }

  // Example: Call CiviCRM API to create the contribution.
  try {
    civicrm_api3('Contribution', 'create', $params);
  }
  catch (\Exception $e) {
    \Drupal::logger('commerce_civicrm')->error('CiviCRM contribution creation failed: @message', ['@message' => $e->getMessage()]);
  }
}