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

    if ($sha1out = commerce_ogone_load_sha1out($feedback)) {
      if (commerce_ogone_feedback_valid_sha1($feedback, $sha1out)) {
        drupal_set_message('valid feedback');
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
 *  Get the SHA-1-OUT pass phrase as defined in the payment method settings
 */
function commerce_ogone_load_sha1out($feedback) {
  // Load the order
  $orderid = $feedback['ORDERID'];
  $order = commerce_order_load($orderid);
  if (isset($order) && is_object($order) && isset($order->data['payment_method'])) {
    // Load the payment method
    $payment_method = commerce_payment_method_instance_load($order->data['payment_method']);
    if (isset($payment_method) && is_array($payment_method)) {
      if (isset($payment_method['base']) && $payment_method['base'] == 'commerce_ogone') {
        if (isset($payment_method['settings']['sha_out'])) {
          return $payment_method['settings']['sha_out'];
        }
      }

    }
  }
}