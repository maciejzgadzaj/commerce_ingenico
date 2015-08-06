<?php
/**
 * @file
 * Provide admin functionalities such as manual capture, refund, cancel
 * and delete transactions.
 */

/**
 * Capture transactions.
 */
function commerce_ingenico_capture_form($form, &$form_state, $order, $transaction) {
  $form_state['order'] = $order;
  $form_state['transaction'] = $transaction;

  // Load and store the payment method instance for this transaction.
  $payment_method = commerce_payment_method_instance_load($transaction->instance_id);
  $form_state['payment_method'] = $payment_method;

  $balance = commerce_payment_order_balance($order);

  if ($balance['amount'] > 0 && $balance['amount'] < $transaction->amount) {
    $default_amount = $balance['amount'];
  }
  else {
    $default_amount = $transaction->amount;
  }

  $default_amount = number_format(commerce_currency_amount_to_decimal($default_amount, $transaction->currency_code), 2, '.', '');

  $description = t('Authorization: @amount,', array('@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code))) .
    t('Order balance: @balance', array('@balance' => commerce_currency_format($balance['amount'], $balance['currency_code'])));

  $form['amount'] = array(
    '#type' => 'textfield',
    '#title' => t('Capture amount'),
    '#description' => t('@description', array('@description' => $description)),
    '#default_value' => $default_amount,
    '#field_suffix' => check_plain($transaction->currency_code),
    '#size' => 16,
  );

  $form = confirm_form($form,
      t('What amount do you want to capture?'),
      'admin/commerce/orders/' . $order->order_id . '/payment',
      '',
      t('Capture'),
      t('Cancel'),
      'confirm'
  );

  return $form;
}

/**
 * Validate handler: ensure a valid amount is given.
 */
function commerce_ingenico_capture_form_validate($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $amount = $form_state['values']['amount'];
  // Ensure a positive numeric amount has been entered for capture.
  if (!is_numeric($amount) || $amount <= 0) {
    form_set_error('amount', t('You must specify a positive numeric amount to capture.'));
  }

  // Ensure the amount is less than or equal to the authorization amount.
  if ($amount > commerce_currency_amount_to_decimal($transaction->amount, $transaction->currency_code)) {
    form_set_error('amount', t('You cannot capture more than you authorized through Ingenico.'));
  }

}

/**
 * Submit handler: process a prior authorization capture via Ingenico.
 */
function commerce_ingenico_capture_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];

  $order = commerce_order_load($transaction->order_id);
  $payment_methods = commerce_payment_method_instance_load($transaction->instance_id);

  $amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
  $description = t('Authorization capture for order #@order_id at @site_name', array(
    '@order_id' => $order->order_id,
    '@site_name' => variable_get('site_name', ''),
  ));
  $api = commerce_ingenico_api_object($payment_methods['settings'], $payment_methods['instance_id']);

  if ($transaction->amount == commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    $result = $api->crossPayment($order, $transaction, $type = 'SAL', $operation = 'capture');

    $data = $api->getResponseData($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      $transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $transaction->status = COMMERCE_INGENICO_PAYMENT_STATUS_CAPTURED;
      $transaction->message .= '<br />' . t('Captured: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($transaction);
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
    elseif ($data['NCERROR'] == '50001127') {
      $transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $transaction->status = COMMERCE_INGENICO_PAYMENT_STATUS_CAPTURED;
      $transaction->message .= '<br />' . t('Captured: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($transaction);
      $query = $api->query($transaction, $pay_id = $data['PAYID'], $sub_id = $data['PAYIDSUB'] - 1);
      drupal_set_message(t('This order has been captured previously.'));
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
    else {
      drupal_set_message(t('There was an error processing your request: @error_plus', array('@error_plus' => $data['NCERRORPLUS'])), 'error');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
  }
  elseif ($transaction->amount > commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    $charge = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);

    $amount = new stdClass();
    $amount->amount = $charge;
    $amount->currency_code = $transaction->currency_code;
    $result = $api->crossPayment($order, $transaction, $type = 'SAL', $operation = 'capture', $pay_id = '', $sub_id = '', $amount);

    // Recover the amount transaction in case of failure of the capture.
    $data = $api->getResponseData($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      // Create new trasaction for the captured amount.
      $new_transaction = commerce_payment_transaction_new($transaction->payment_method, $order->order_id);
      $new_transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $new_transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $new_transaction->status = COMMERCE_INGENICO_PAYMENT_STATUS_CAPTURED;
      $new_transaction->currency_code = $transaction->currency_code;
      $new_transaction->instance_id = $payment_methods['instance_id'];
      $new_transaction->message = t('Captured: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($new_transaction);

      $transaction->amount = $transaction->amount - $new_transaction->amount;
      $transaction->status = COMMERCE_PAYMENT_STATUS_PENDING;
      commerce_payment_transaction_save($transaction);
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
    else {
      drupal_set_message(t('There was an error processing your request: @error_plus', array('@error_plus' => $data['NCERRORPLUS'])), 'error');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
  }

}

/**
 * Provide refund functionality.
 */
function commerce_ingenico_refund_form($form, &$form_state, $order, $transaction) {
  $form_state['order'] = $order;
  $form_state['transaction'] = $transaction;

  $balance = commerce_payment_order_balance($order);
  if ($balance['amount'] > 0 && $balance['amount'] < $transaction->amount) {
    $default_amount = $balance['amount'];
  }
  else {
    $default_amount = $transaction->amount;
  }
  $default_amount = number_format(commerce_currency_amount_to_decimal($default_amount, $transaction->currency_code), 2, '.', '');
  $description = t('Authorization: @amount,', array('@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code))) .
    t('Order balance: @balance', array('@balance' => commerce_currency_format($balance['amount'], $balance['currency_code'])));

  $form['amount'] = array(
    '#type' => 'textfield',
    '#title' => t('Refund amount'),
    '#description' => t('@description', array('@description' => $description)),
    '#default_value' => $default_amount,
    '#field_suffix' => check_plain($transaction->currency_code),
    '#size' => 16,
  );

  $form = confirm_form($form,
      t('What amount do you want to refund?'),
      'admin/commerce/orders/' . $order->order_id . '/payment',
      '',
      t('Refund'),
      t('Cancel'),
      'confirm'
  );

  return $form;
}

/**
 * Validate handler: ensure a valid amount is given.
 */
function commerce_ingenico_refund_form_validate($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $amount = $form_state['values']['amount'];
  // Ensure a positive numeric amount has been entered for capture.
  if (!is_numeric($amount) || $amount <= 0) {
    form_set_error('amount', t('You must specify a positive numeric amount to refund.'));
  }

  // Ensure the amount is less than or equal to the authorization amount.
  if ($amount > commerce_currency_amount_to_decimal($transaction->amount, $transaction->currency_code)) {
    form_set_error('amount', t('You refund more than you captured through Ingenico.'));
  }

}

/**
 * Submit handler: process a prior authorization capture via Ingenico.
 */
function commerce_ingenico_refund_form_submit($form, &$form_state) {

  $transaction = $form_state['transaction'];
  $order = commerce_order_load($transaction->order_id);
  $amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
  $description = t('Refund order #@order_id at @site_name', array(
    '@order_id' => $order->order_id,
    '@site_name' => variable_get('site_name', ''),
  ));

  $payment_method_instance = commerce_payment_method_instance_load($transaction->instance_id);
  $api = commerce_ingenico_api_object($payment_method_instance['settings'], $payment_method_instance['instance_id']);

  if ($transaction->amount == commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    // Search for sub pay id.
    if (strstr($transaction->remote_id, '/') != FALSE) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $result = $api->crossPayment($order, $transaction, $type = 'RFD', $operation = 'refund', $pay_id);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'RFD', $operation = 'refund');
    }
    $data = $api->getResponseData($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      $transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $transaction->status = COMMERCE_INGENICO_PAYMENT_STATUS_REFUND;
      $transaction->message .= '<br />' . t('Refunded: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($transaction);
      drupal_set_message(t('Refund was successful.'), 'status');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
    else {
      drupal_set_message(t('There was an error processing your request: @error_plus', array('@error_plus' => $data['NCERRORPLUS'])), 'error');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
  }
  elseif ($transaction->amount != commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    $charge = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
    $amount = new stdClass();
    $amount->amount = $charge;
    $amount->currency_code = $transaction->currency_code;
    // Search for sub pay id.
    if (strstr($transaction->remote_id, '/') != FALSE) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $result = $api->crossPayment($order, $transaction, $type = 'RFD', $operation = 'refund', $pay_id, '', $amount);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'RFD', $operation = 'refund');
    }

    $transaction->amount = $transaction->amount - commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);

    // Recover the amount transaction in case of failure of the capture.
    $transaction->amount = $transaction->amount + commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
    $data = $api->getResponseData($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      $new_transaction = commerce_payment_transaction_new($transaction->payment_method, $order->order_id);
      $new_transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $new_transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $new_transaction->status = COMMERCE_INGENICO_PAYMENT_STATUS_REFUND;
      $new_transaction->instance_id = $transaction->instance_id;
      $new_transaction->message .= '<br />' . t('Refunded: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($new_transaction);
      $transaction->amount = $transaction->amount - $new_transaction->amount;
      commerce_payment_transaction_save($transaction);
      drupal_set_message(t('Refund was successful.'), 'status');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
    else {
      drupal_set_message(t('There was an error processing your request: @error_plus', array('@error_plus' => $data['NCERRORPLUS'])), 'error');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
  }

}

/**
 * Provide cancel transaction functionality.
 */
function commerce_ingenico_cancel_form($form, &$form_state, $order, $transaction) {
  $form_state['order'] = $order;
  $form_state['transaction'] = $transaction;
  $form['flag'] = array(
    '#type' => 'hidden',
    '#value' => 1,
  );

  $form = confirm_form($form,
      t('Are you sure you want to cancel this transaction?'),
      'admin/commerce/orders/' . $order->order_id . '/payment',
      '',
      t('Cancel'),
      t('Back'),
      'confirm'
  );

  return $form;
}

/**
 * Cancel transaction.
 */
function commerce_ingenico_cancel_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $order = $form_state['order'];

  $instance = commerce_payment_method_instance_load($transaction->instance_id);
  $api = commerce_ingenico_api_object($instance['settings'], $instance['instance_id']);

  // Perform maintenance request.
  // Search for sub pay id.
  if (strstr($transaction->remote_id, '/') != FALSE) {
    $pay_id_pieces = explode('/', $transaction->remote_id);
    $pay_id = $pay_id_pieces[0];
    $result = $api->crossPayment($order, $transaction, $type = 'DEL', $operation = 'cancel', $pay_id);
  }
  else {
    $result = $api->crossPayment($order, $transaction, $type = 'DEL', $operation = 'cancel');
  }

  $response_data = $api->getResponseData($result->data);
  if ($response_data['NCERROR'] != 0 and empty($response_data['ACCEPTANCE'])) {
    drupal_set_message(t('There was an error processing your request: @error_plus', array('@error_plus' => $response_data['NCERRORPLUS'])), 'error');
    drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
  }
  else {
    if ($result->status_message == 'OK' and $result->code == 200 and $response_data['NCERROR'] == 0) {
      $transaction->status = COMMERCE_INGENICO_PAYMENT_STATUS_CANCELLED;
      $transaction->message .= '<br/>' . 'The transaction have been cancelled.';
      commerce_payment_transaction_save($transaction);
      drupal_set_message(t('The transaction has been cancelled.'), 'status');
    }
    drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
  }
}

/**
 * Provides delete transaction functionality.
 */
function commerce_ingenico_transaction_delete_form($form, &$form_state, $order, $transaction) {
  $form_state['transaction'] = $transaction;
  $form_state['order'] = $order;

  $payment_method = commerce_payment_method_load($transaction->payment_method);
  $form = confirm_form($form,
    t('Are you sure you want to delete this transaction?'),
    'admin/commerce/orders/' . $order->order_id . '/payment',
    '<p>' . t('@amount paid via %method on @date. Deleting this transaction cannot be undone.', array(
      '@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code),
      '%method' => $payment_method['title'],
      '@date' => format_date($transaction->created, 'short'),
    )) . '</p>',
    t('Delete'),
    t('Cancel'),
    'confirm'
  );

  return $form;
}

/**
 * Delete transaction.
 */
function commerce_ingenico_transaction_delete_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $order = $form_state['order'];
  $api = commerce_ingenico_api_object();
  // Perform maintenance request.
  // Search for sub pay id.
  // Check if the transaction is Ingenico transaction.
  if ($transaction->payment_method == 'ingenico_direct' or $transaction->payment_method == 'ingenico_offsite') {
    if (strstr($transaction->remote_id, '/') != FALSE) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $sub_id = $pay_id_pieces[1];
      $result = $api->crossPayment($order, $transaction, $type = 'DES', $operation = 'cancel', $pay_id, $sub_id);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'DES', $operation = 'cancel');
    }

    $response_data = $api->getResponseData($result->data);
    if ($result->status_message == 'OK' and $result->code == 200 and $response_data['NCERROR'] == 0) {
      drupal_set_message(t('The transaction has been deleted.'), 'status');
      if (commerce_payment_transaction_delete($transaction->transaction_id)) {
        drupal_set_message(t('Payment transaction deleted.'));
        watchdog('commerce_payment', 'Deleted payment transaction @transaction.', array('@transaction' => $transaction->transaction_id), WATCHDOG_NOTICE);
      }
      else {
        drupal_set_message(t('The payment transaction could not be deleted.'), 'error');
      }
    }
    else {
      drupal_set_message(t('There was an error processing your request: @error_plus', array('@error_plus' => $response_data['NCERRORPLUS'])), 'error');
      drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment');
    }
  }
  // Because we modifited the menu item for delition
  // we should be able to delete any other transactions.
  else {
    if (commerce_payment_transaction_delete($transaction->transaction_id)) {
      drupal_set_message(t('Payment transaction deleted.'));
      watchdog('commerce_payment', 'Deleted payment transaction @transaction.', array('@transaction' => $transaction->transaction_id), WATCHDOG_NOTICE);
    }
    else {
      drupal_set_message(t('The payment transaction could not be deleted.'), 'error');
    }
  }
  drupal_goto('admin/commerce/orders');
}

/**
 * Automatically submits the order and transaction for an update.
 */
function commerce_ingenico_update_redirect($order, $transaction) {
  $form_state['values']['order'] = $order;
  $form_state['values']['transaction'] = $transaction;
  drupal_form_submit('commerce_ingenico_update_form', $form_state);
}

/**
 * Form to update transaction.
 */
function commerce_ingenico_update_form($form, &$form_state) {
  $form['order'] = array(
    '#type' => 'hidden',
    '#title' => t('Order'),
  );

  $form['transaction'] = array(
    '#type' => 'hidden',
    '#title' => t('Transaction'),
  );

  return $form;
}

/**
 * Submit the query request for a single transaction update.
 */
function commerce_ingenico_update_form_submit($form, &$form_state) {
  $order = $form_state['values']['order'];
  $transaction = $form_state['values']['transaction'];
  $result = commerce_ingenico_query_update($transaction, 1);
  if ($result == TRUE) {
    drupal_set_message(t('The transaction was updated.'), 'status');
  }
  elseif ($result == FALSE) {
    drupal_set_message(t('The transaction was not updated'), 'error');
  }
  drupal_goto(t('admin/commerce/orders/@order/payment', array('@order' => $order->order_id)));
}
