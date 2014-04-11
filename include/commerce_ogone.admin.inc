<?php
/**
 * @file
 * Provide admin functionalities such as manual capture, refund, cancel and delete transactions.
 */

/**
 * Capture transactions.
 */
function commerce_ogone_capture_form($form, &$form_state, $order, $transaction) {
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

  $description =
    t('Authorization: @amount,', array('@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code))) .
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
function commerce_ogone_capture_form_validate($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $amount = $form_state['values']['amount'];
  // Ensure a positive numeric amount has been entered for capture.
  if (!is_numeric($amount) || $amount <= 0) {
    form_set_error('amount', t('You must specify a positive numeric amount to capture.'));
  }

  // Ensure the amount is less than or equal to the authorization amount.
  if ($amount > commerce_currency_amount_to_decimal($transaction->amount, $transaction->currency_code)) {
    form_set_error('amount', t('You cannot capture more than you authorized through Ogone.'));
  }

}

/**
 * Submit handler: process a prior authorization capture via Ogone.
 */
function commerce_ogone_capture_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];

  $order = commerce_order_load($transaction->order_id);
  $payment_methods = commerce_payment_method_instance_load($transaction->instance_id);

  $amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
  $description = t('Authorization capture for order #@order_id at @site_name', array(
    '@order_id' => $order->order_id,
    '@site_name' => variable_get('site_name', ''),
  ));
  $api = commerce_ogone_api_object();
  if ($transaction->amount == commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    $result = $api->crossPayment($order, $transaction, $type = 'SAS', $operation = 'capture');
    $data = $api->get_response_data($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      $transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $transaction->status = COMMERCE_PAYMENT_STATUS_OGONE_CAPTURED;
      $transaction->message .= '<br />' . t('Captured: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($transaction);
      drupal_goto('admin/commerce/orders');
    }
  }
  elseif ($transaction->amount > commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    $charge = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);

    $amount = new stdClass();
    $amount->amount = $charge;
    $amount->currency_code = $transaction->currency_code;
    $result = $api->crossPayment($order, $transaction, $type = 'SAL', $operation = 'capture', $pay_id = '', $sub_id = '', $amount);
    //Recover the amount transaction in case of failure of the capture.
    $data = $api->get_response_data($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      //Create new trasaction for the captured amount.
      $new_transaction = commerce_payment_transaction_new($transaction->payment_method, $order->order_id);
      $new_transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $new_transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $new_transaction->status = COMMERCE_PAYMENT_STATUS_OGONE_CAPTURED;
      $new_transaction->currency_code = $transaction->currency_code;
      $new_transaction->instance_id = 'ogone_direct|commerce_payment_ogone_direct';
      $new_transaction->message .= '<br />' . t('Captured: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($new_transaction);

      $transaction->amount = $transaction->amount - $new_transaction->amount;
      $transaction->status = COMMERCE_PAYMENT_STATUS_PENDING;
      commerce_payment_transaction_save($transaction);
      drupal_goto('admin/commerce/orders');
    }
  }

}

/**
 * Provide refund functionality.
 */
function commerce_ogone_refund_form($form, &$form_state, $order, $transaction) {
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
  $description =
    t('Authorization: @amount,', array('@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code))) .
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
function commerce_ogone_refund_form_validate($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $amount = $form_state['values']['amount'];
  // Ensure a positive numeric amount has been entered for capture.
  if (!is_numeric($amount) || $amount <= 0) {
    form_set_error('amount', t('You must specify a positive numeric amount to refund.'));
  }

  // Ensure the amount is less than or equal to the authorization amount.
  if ($amount > commerce_currency_amount_to_decimal($transaction->amount, $transaction->currency_code)) {
    form_set_error('amount', t('You refund more than you captured through Ogone.'));
  }

}

/**
 * Submit handler: process a prior authorization capture via Ogone.
 */
function commerce_ogone_refund_form_submit($form, &$form_state) {

  $transaction = $form_state['transaction'];
  $order = commerce_order_load($transaction->order_id);
  $amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
  $description = t('Refund order #@order_id at @site_name', array(
    '@order_id' => $order->order_id,
    '@site_name' => variable_get('site_name', ''),
  ));
  $api = commerce_ogone_api_object();

  if ($transaction->amount == commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    //Search for sub pay id.
    if (strstr($transaction->remote_id, '/') != FALSE ) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $result = $api->crossPayment($order, $transaction, $type = 'RFS', $operation = 'refund', $pay_id);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'RFS', $operation = 'refund');
    }
    $data = $api->get_response_data($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      $transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $transaction->status = COMMERCE_PAYMENT_STATUS_OGONE_REFUND;
      $transaction->instance_id = $transaction->instance_id;
      $transaction->message .= '<br />' . t('Refunded: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($transaction);
      drupal_goto('admin/commerce/orders');
    }
  }
  elseif ($transaction->amount != commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code)) {
    $charge = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
    $amount = new stdClass();
    $amount->amount = $charge;
    $amount->currency_code = $transaction->currency_code;
      //Search for sub pay id.
    if (strstr($transaction->remote_id, '/') != FALSE ) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $result = $api->crossPayment($order, $transaction, $type = 'RFS', $operation = 'refund', $pay_id);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'RFS', $operation = 'refund');
    }

    $transaction->amount = $transaction->amount  - commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);

    //Recover the amount transaction in case of failure of the capture.
    $transaction->amount = $transaction->amount + commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
    $data = $api->get_response_data($result->data);

    if ($result->status_message == 'OK' and $result->code == 200 and $data['NCERROR'] == 0) {
      $new_transaction = commerce_payment_transaction_new($transaction->payment_method, $order->order_id);
      $new_transaction->amount = commerce_currency_decimal_to_amount($form_state['values']['amount'], $transaction->currency_code);
      $new_transaction->remote_id = !empty($data['PAYIDSUB']) ? $data['PAYID'] . '/' . $data['PAYIDSUB'] : $data['PAYID'];
      $new_transaction->status = COMMERCE_PAYMENT_STATUS_OGONE_REFUND;
      $new_transaction->instance_id = $transaction->instance_id;
      $new_transaction->message .= '<br />' . t('Refunded: @date', array('@date' => format_date(REQUEST_TIME, 'short')));
      commerce_payment_transaction_save($new_transaction);
      $transaction->amount = $transaction->amount - $new_transaction->amount;
      commerce_payment_transaction_save($transaction);
      drupal_goto('admin/commerce/orders');
    }
  }

}

/**
 * Provide cancel transaction functionality.
 */
function commerce_ogone_cancel_form($form, &$form_state, $order, $transaction) {
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
function commerce_ogone_cancel_form_submit($form, &$form_state) {
    $transaction = $form_state['transaction'];
    $order = $form_state['order'];
    $api = commerce_ogone_api_object();
    //Perform maintenance request.
    //Search for sub pay id.
    if (strstr($transaction->remote_id, '/') != FALSE ) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $result = $api->crossPayment($order, $transaction, $type = 'DEL', $operation = 'cancel', $pay_id);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'DEL', $operation = 'cancel');
    }

    $response_data = $api->get_response_data($result->data);
    if ($response_data['NCERROR'] != 0 and empty($response_data['ACCEPTANCE'])) {
      drupal_set_message(t('@error', array('@error' => $response_data['NCERRORPLUS'])), 'error');
      return;
    }
    else {
      if ($result->status_message == 'OK' and $result->code == 200 and $response_data['NCERROR'] == 0) {
        $transaction->status = COMMERCE_PAYMENT_STATUS_OGONE_CANCELED;
        $transaction->message .= '<br/>' . 'The transaction have been canceled.';
        commerce_payment_transaction_save($transaction);
        drupal_set_message(t('The transaction has been canceled.'), 'status');
      }
      drupal_goto('admin/commerce/orders');
  }
}

/**
 * Provides delete transaction functionality.
 */
function commerce_ogone_transaction_delete_form($form, &$form_state, $order, $transaction) {
  $form_state['transaction'] = $transaction;
  $form_state['order'] = $order;

  $payment_method = commerce_payment_method_load($transaction->payment_method);
  $form = confirm_form($form,
    t('Are you sure you want to delete this transaction?'),
    'admin/commerce/orders/' . $order->order_id . '/payment',
    '<p>' . t('@amount paid via %method on @date. Deleting this transaction cannot be undone.', array('@amount' => commerce_currency_format($transaction->amount, $transaction->currency_code), '%method' => $payment_method['title'], '@date' => format_date($transaction->created, 'short'))) . '</p>',
    t('Delete'),
    t('Cancel'),
    'confirm'
  );

  return $form;
}

/**
 * Delete transaction.
 */
function commerce_ogone_transaction_delete_form_submit($form, &$form_state) {
  $transaction = $form_state['transaction'];
  $order = $form_state['order'];
  $api = commerce_ogone_api_object();
  //Perform maintenance request.
  //Search for sub pay id.
  //Check if the transaction is Ogone transaction.
  if ($transaction->payment_method == 'ogone_direct' or $transaction->payment_method == 'ogone_offsite') {
    if (strstr($transaction->remote_id, '/') != FALSE ) {
      $pay_id_pieces = explode('/', $transaction->remote_id);
      $pay_id = $pay_id_pieces[0];
      $sub_id = $pay_id_pieces[1];
      $result = $api->crossPayment($order, $transaction, $type = 'DES', $operation = 'cancel', $pay_id, $sub_id);
    }
    else {
      $result = $api->crossPayment($order, $transaction, $type = 'DES', $operation = 'cancel');
    }

    $response_data = $api->get_response_data($result->data);
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
      drupal_set_message(t('The operation was not successful: @error_message', array('@error_message' => $response_data['NCERRORPLUS'])), 'error');
    }
  }
  //Because we modifited the menu item for delition we should be able to delete any other transactions.
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