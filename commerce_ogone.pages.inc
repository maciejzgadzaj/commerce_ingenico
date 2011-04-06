<?php

/**
 * @file
 * Provides page callback functionality for Commerce Ogone module
 */

function commerce_ogone_process_feedback() {
  module_load_include('inc', 'commerce_ogone', 'commerce_ogone.sha1');

  if (isset($_REQUEST['SHASIGN'])) {
    $feedback = array();
    $feedback = array_map('check_plain', $_REQUEST);
    $feedback = array_change_key_case($feedback, CASE_UPPER);

    if ($data = commerce_ogone_load_data($feedback)) {
      if (isset($data['sha1out']) && !empty($data['sha1out'])) {
        if (commerce_ogone_feedback_valid_sha1($feedback, $data['sha1out'])) {
          drupal_set_message('valid feedback');
          commerce_ogone_update_status($feedback, $data);
        }
      }
    }
    else {
      // @TODO: implement
      drupal_set_message('invalid feedback');
    }
    return '';
  }
  else {
    drupal_set_message(t('Invalid return from Ogone'), 'error');
    drupal_exit();
  }
}

/**
 *  Get the appropriate data for this order
 */
function commerce_ogone_load_data($feedback) {
  $data = array();
  // Load the order
  $orderid = $feedback['ORDERID'];
  $order = commerce_order_load($orderid);
  if (isset($order) && is_object($order) && isset($order->data['payment_method'])) {
    $data['order'] = $order;
    // Load the payment method
    $payment_method = commerce_payment_method_instance_load($order->data['payment_method']);
    if (isset($payment_method) && is_array($payment_method)) {
      $data['payment_method'] = $payment_method;
      if (isset($payment_method['base']) && $payment_method['base'] == 'commerce_ogone') {
        if (isset($payment_method['settings']['sha_out'])) {
          $data['sha1out'] = $payment_method['settings']['sha_out'];
        }
      }
    }
  }
  return $data;
}

/**
 * Process the payment status
 */
function commerce_ogone_update_status($feedback, $data) {
  $order = $data['order'];
  $payment_method = $data['payment_method'];
  $payment_status = commerce_ogone_feedback_status($feedback['STATUS']);

  // Create a new payment transaction for the order.
  $transaction = commerce_payment_transaction_new('commerce_ogone', $order->order_id);
  $transaction->instance_id = $payment_method['instance_id'];
  $transaction->remote_id = $feedback['PAYID'];
  $transaction->remote_status = $feedback['STATUS'];
  $transaction->amount = $feedback['AMOUNT'];
  $transaction->currency_code = $feedback['CURRENCY'];
  $transaction->status = $payment_status['code'];
  $transaction->message = $payment_status['message'];
  commerce_payment_transaction_save($transaction);
  watchdog('commerce_ogone', 'Payment processed for order @order_number with ID @txn_id.', array('@txn_id' => $feedback['PAYID'], '@order_number' => $order->order_number), WATCHDOG_INFO);
}