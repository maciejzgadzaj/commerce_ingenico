<?php
/**
 * @file
 * commerce_ingenico_direct.inc
 * Functions for Direct integration.
 */

/**
 * Payment method callback: checkout form.
 */
function commerce_ingenico_submit_form($payment_method, $pane_values, $checkout_pane, $order) {
  if ($payment_method['method_id'] == 'ingenico_offsite' && !empty($payment_method['settings']['modify_card_types']) && $payment_method['settings']['modify_card_types'] === 1) {
    $all_payment_methods = commerce_ingenico_payment_methods();
    foreach ($payment_method['settings']['payment_method_types'] as $key => $value) {
      if (!empty($value)) {
        $cards[$key] = $all_payment_methods[$key]['BRAND'];
      }
    }
    $cards['other'] = 'Other payment method';
    $form['credit_card']['type'] = array(
      '#type' => 'select',
      '#title' => t('Payment method'),
      '#options' => $cards,
    );

    return $form;
  }
  elseif ($payment_method['method_id'] == 'ingenico_direct') {
    module_load_include('inc', 'commerce_payment', 'includes/commerce_payment.credit_card');
    $card_types = commerce_ingenico_direct_all_card_names();
    foreach ($card_types as $key => $value) {
      $cards_id[] = $key;
    }
    $credit_card_settings = array(
      'type'  => $cards_id,
      'code'  => 'CVV/CV2',
      'owner' => '',
    );

    $form = commerce_payment_credit_card_form($credit_card_settings);
    $customer_profile = commerce_customer_profile_load($order->commerce_customer_billing['und'][0]['profile_id']);
    $form['credit_card']['type']['#options'] = $card_types;
    $form['credit_card']['owner'] = array(
      '#type' => 'textfield',
      '#title' => t('Card owner'),
      '#default_value' => $customer_profile->commerce_customer_address['und'][0]['name_line'],
      '#attributes' => array('autocomplete' => 'off'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#size' => 32,
    );

    return $form;
  }
}


/**
 * Get all card types.
 */
function commerce_ingenico_direct_all_card_names() {
  return array(
    'visa' => t('Visa'),
    'mastercard' => t('Mastercard'),
    'amex' => t('American Express'),
    'maestro' => t('Maestro'),
    'BC/MC' => t('bancontact/mistercash'),
  );

}

/**
 * Payment method callback: checkout form validation.
 */
function commerce_ingenico_submit_form_validate($payment_method, $pane_form, $pane_values, $order, $form_parents = array()) {
  if ($payment_method['method_id'] == 'ingenico_offsite') {
    return;
  }
  else {
    module_load_include('inc', 'commerce_payment', 'includes/commerce_payment.credit_card');
    // Validate credit card.
    $settings = array(
      'form_parents' => array_merge($form_parents, array('credit_card')),
    );
    if (commerce_payment_credit_card_validate_bc($payment_method, $pane_values['credit_card']) != TRUE and (commerce_payment_credit_card_validate($pane_values['credit_card'], $settings) == FALSE)) {
      return FALSE;
    }
  }
}

/**
 * Validates cards for Bancontact/Mistercash cards.
 */
function commerce_payment_credit_card_validate_bc($payment_method, $card_type) {
  if ($payment_method['method_id'] == 'ingenico_direct') {
    // Check the card number.
    $first_int = intval(substr($card_type['number'], 0, 8));
    $second_int = intval(substr($card_type['number'], 8, 16));
    if ($card_type['type'] === 'bancontact/mistercash' and (strlen($card_type['number']) == 16 and is_int($first_int) and $first_int > 0 and is_int($second_int) and $second_int > 0)) {
      if (substr($first_int, 0, 4) == 6573 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 5099) {
        drupal_set_message(t('test card inside'), 'status');
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6541 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 5099) {
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6542 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 5099) {
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6543 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 2099) {
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6543 and substr($first_int, -4, 8) >= 2200 and substr($first_int, -4, 8) <= 5099) {
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6556 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 5099) {
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6558 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 5099) {
        return TRUE;
      }
      elseif (substr($first_int, 0, 4) == 6559 and substr($first_int, -4, 8) >= 0300 and substr($first_int, -4, 8) <= 5099) {
        return TRUE;
      }
      else {
        drupal_set_message(t('Invalid card number'), 'error');
        return FALSE;
      }
    }
    elseif ($card_type['type'] === 'bancontact/mistercash') {
      drupal_set_message(t('Invalid card number'), 'error');
      return FALSE;
    }

    // Check the security code.
    $int = intval($card_type['code']);
    if (strlen($card_type['code']) != 3 or !is_int($int) or $int < 0) {
      drupal_set_message(t('Invalid security code'), 'error');
      return FALSE;
    }

    // Check the expiration date.
    if ($card_type['exp_year'] < date('y') or $card_type['exp_month'] < date('m')) {
      drupal_set_message(t('You have entered an expired credit card.'), 'error');
      return FALSE;
    }
    if (!empty($return)) {
      return $return;
    }
    else {
      return TRUE;
    }
  }
}

/**
 * Submit the payment request.
 */
function commerce_ingenico_submit_form_submit($payment_method, $pane_form, $pane_values, $order, $charge) {
  global $base_root;
  if ($payment_method['method_id'] == 'ingenico_offsite') {
    if (!empty($pane_values['credit_card']['type'])) {
      $form_state_value['value']['type'] = $pane_values['credit_card']['type'];
      foreach (commerce_ingenico_payment_methods() as $key => $value) {
        if ($key == $form_state_value['value']['type']) {
          $form_state_value['value']['PM'] = $value['PM'];
        }
      }
    }
    $order->data['payment_method'] = $payment_method['instance_id'];
    commerce_order_save($order);
    $result = commerce_ingenico_redirect_alter_form($pane_form, $form_state_value, $order);
    foreach ($result as $key => $value) {
      if ($key == 'ACCEPTURL' || $key == 'ALIAS' || $key == 'ALIASUSAGE' || $key == 'AMOUNT' || $key == 'BRAND' || $key == 'CANCELURL' || $key == 'CN' || $key == 'CURRENCY' || $key == 'DECLINEURL' || $key == 'DEVICE' ||
        $key == 'EMAIL' || $key == 'EXCEPTIONURL' || $key == 'LANGUAGE' || $key == 'OPERATION' || $key == 'ORDERID' || $key == 'ORIG' || $key == 'PM' || $key == 'PSPID' || $key == 'TITLE' ||
        $key == 'OWNERADDRESS' || $key == 'OWNERZIP' || $key == 'OWNERTOWN' || $key == 'OWNERCTY' ||
        $key == 'ECOM_BILLTO_POSTAL_CITY' || $key == 'ECOM_BILLTO_POSTAL_COUNTRYCODE' || $key == 'ECOM_BILLTO_POSTAL_NAME_FIRST' || $key == 'ECOM_BILLTO_POSTAL_NAME_LAST' ||
        $key == 'ECOM_BILLTO_POSTAL_POSTALCODE' || $key == 'ECOM_BILLTO_POSTAL_STREET_LINE1' || $key == 'ECOM_BILLTO_POSTAL_STREET_LINE2' || $key == 'SHASign' ||
        $key == 'ECOM_SHIPTO_COMPANY' || $key == 'ECOM_SHIPTO_ONLINE_EMAIL' || $key == 'ECOM_SHIPTO_POSTAL_CITY' || $key == 'ECOM_SHIPTO_POSTAL_COUNTRYCODE' ||
        $key == 'ECOM_SHIPTO_POSTAL_NAME_FIRST' || $key == 'ECOM_SHIPTO_POSTAL_NAME_LAST' || $key == 'ECOM_SHIPTO_POSTAL_POSTALCODE' || $key == 'ECOM_SHIPTO_POSTAL_STREET_LINE1' ||
        $key == 'ECOM_SHIPTO_POSTAL_STREET_LINE2' || $key == 'SHASign') {
        $new_form[$key] = $value['#value'];
      }
    }
    drupal_goto($result['#action'], array('query' => $new_form));
  }
  else {
    $customer_profile = commerce_ingenico_customer_profile_load($order->commerce_customer_billing['und'][0]['profile_id']);
    $api = commerce_ingenico_api_object($payment_method['settings'], $payment_method['instance_id']);
    $result = $api->directPayments($customer_profile, $order, $pane_values);

    $data = $api->getResponseData($result->data);
    if ($data['NCERROR'] != 0) {
      $transaction = commerce_payment_transaction_new('ingenico_direct', $order->order_id);
      $query_return = $api->query($transaction, $data['PAYID'], $sub_id = '', $order = '');
      $query_data = $api->getResponseData($query_return->data);
      if ($query_data['NCERROR'] != 0) {
        if (strstr($query_data['NCERRORPLUS'], 'unknown order/1/i') != FALSE) {
          if ($payment_method['settings']['logs'] == 0) {
            watchdog('commerce_ingenico', "The IP address from which a request was sent is not an IP address the merchant had entered in the IP address field of the Data and origin verification tab. Go to your account in Ingenico and then Technical information->Data and origin verification and set your IP Address.", array(), WATCHDOG_ERROR);
          }
          drupal_set_message(t('The IP address from which a request was sent is not an IP address the merchant had entered in the IP address field of the Data and origin verification tab. Go to your account in Ingenico and then Technical information->Data and origin verification and set your IP Address.'), 'error');
          return FALSE;
        }
        elseif (strstr($query_data['NCERRORPLUS'], 'unknown order/1/s')) {
          if ($payment_method['settings']['logs'] == 0) {
            watchdog('commerce_ingenico', "The SHASIGN used in the request is different than the SHASIGN calculated on the Ingenico site please verify your parameters and SHA-IN phrases entered on your site and Ingenico site.", array(), WATCHDOG_ERROR);
          }
          drupal_set_message(t('The SHASIGN used in the request is different than the SHASIGN calculated on the Ingenico site please verify your parameters and SHA-IN phrases entered on your site and Ingenico site.'), 'error');
          return FALSE;
        }
        elseif (strstr($query_data['NCERRORPLUS'], 'unknown order/0/s')) {
          if ($payment_method['settings']['logs'] == 0) {
            watchdog('commerce_ingenico', "The SHASIGN used in the request is empty or wrong than the SHASIGN calculated on the Ingenico site. Please verify your parameters and SHA-IN phrases entered on your site and Ingenico site.", array(), WATCHDOG_ERROR);
          }
          drupal_set_message(t('The SHASIGN used in the request is empty or wrong than the SHASIGN calculated on the Ingenico site. Please verify your parameters and SHA-IN phrases entered on your site and Ingenico site.'), 'error');
          return FALSE;
        }
        else {
          if ($payment_method['settings']['logs'] == 0) {
            watchdog('commerce_ingenico', "There is a problem with your request, if the problem persist please contact your site administrator. Message sent back: %message", array('%message' => $query_data['NCERRORPLUS']), WATCHDOG_ERROR);
          }
          drupal_set_message(t('There is a problem with your request, if the problem persist please contact your site administrator. Message sent back: @message', array('@message' => $query_data['NCERRORPLUS'])), 'error');
          return FALSE;
        }
      }
    }
    if (!empty($payment_method['settings']['3d_secure']) and $payment_method['settings']['3d_secure'] == 0 and $data['STATUS'] == '5') {
      // Delete the transaction on Ingenico site.
	  $order->data['payment_method'] = $payment_method['instance_id'];
	  commerce_order_save($order);
      drupal_goto('commerce_ingenico/3ds/callback', array(
        'query' => array(
          'STATUS' => !empty($data['STATUS']) ? $data['STATUS'] : '',
          'PAYID' => !empty($data['PAYID']) ? $data['PAYID'] : '',
          'ORDERID' => !empty($order->order_id) ? $order->order_id : '',
          '3D' => 'failed',
          'REMOTE_ORDER_ID' => !empty($data['orderID']) ? $data['orderID'] : '',
          'NCERROR' => !empty($data['NCERROR']) ? $data['NCERROR'] : '',
          'NCSTATUS' => !empty($data['NCSTATUS']) ? $data['NCSTATUS'] : '',
          'NCERRORPLUS' => !empty($data['NCERRORPLUS']) ? $data['NCERRORPLUS'] : '',
        ),
      ));
    }

    if (!empty($data['NCERRORPLUS']) and strstr($data['NCERRORPLUS'], 'unknown order/1/i') != FALSE) {
      if ($payment_method['settings']['logs'] == 0) {
        watchdog('commerce_ingenico', "Your IP Address is not set on Ingenico site. Go to your account in Ingenico and then Technical information->Data and origin verification and set your IP Address.", array(), WATCHDOG_ERROR);
      }
      $error = 'Your IP Address is not set on Ingenico site. Go to your account in Ingenico and then Technical information->Data and origin verification and set your IP Address.';
      drupal_set_message(t('@error', array('@error' => $error)), 'error');
      drupal_goto($base_root);
    }
    // Check for 3d secure.
    if ($data['STATUS'] == 46) {
      $xml = simplexml_load_string($result->data);
      foreach ($xml as $key => $value) {
        $tags[$key] = (string) $value;
      }

      drupal_goto('commerce_ingenico/3d_secure', array(
        'query' => array(
          'result' => $tags['HTML_ANSWER']),
      ));

      return $result;
    }

    if ($result->status_message and $result->code == '200' and $data['NCERROR'] == 0 and ($data['STATUS'] == 5 or $data['STATUS'] == 9)) {
      $transaction = commerce_payment_transaction_new('ingenico_direct', $order->order_id);
      $transaction->instance_id = $payment_method['instance_id'];
      $transaction->remote_id = !empty($data['PAYID']) ? $data['PAYID'] : '';
      $transaction->amount = $order->commerce_order_total['und'][0]['amount'];
      $transaction->currency_code = $order->commerce_order_total['und'][0]['currency_code'];
      $transaction->status = ($payment_method['settings']['transaction_type_process'] == 'capture_manual') ? COMMERCE_PAYMENT_STATUS_PENDING : COMMERCE_INGENICO_PAYMENT_STATUS_CAPTURED;
      $transaction->message_variables = array('@name' => $customer_profile[0]->commerce_customer_address['und'][0]['name_line']);
      $transaction->message = commerce_ingenico_status_messages($data['STATUS']);
      $transaction->remote_status = $data['STATUS'];
      $transaction->payload = $data;
      commerce_payment_transaction_save($transaction);
    }
    else {
      $transaction = commerce_payment_transaction_new('ingenico_direct', $order->order_id);
      $transaction->instance_id = $payment_method['instance_id'];
      $transaction->amount = $order->commerce_order_total['und'][0]['amount'];
      $transaction->currency_code = $order->commerce_order_total['und'][0]['currency_code'];
      $transaction->status = COMMERCE_PAYMENT_STATUS_FAILURE;
      $transaction->message = commerce_ingenico_status_messages($data['STATUS']);
      $transaction->message_variables = array('@name' => $customer_profile[0]->commerce_customer_address['und'][0]['name_line']);
      $transaction->remote_status = $data['STATUS'];
      $transaction->payload = $data;
      commerce_payment_transaction_save($transaction);
    }
  }
  return $result;
}

/**
 * Loads profile by profile_id.
 */
function commerce_ingenico_customer_profile_load($profile_id) {
  $query = new EntityFieldQuery();
  $query->entityCondition('entity_type', 'commerce_customer_profile')
  ->propertyCondition('profile_id', $profile_id)
  ->propertyOrderBy('profile_id', 'DESC');
  $return = array();
  $result = $query->execute();
  if (!empty($result['commerce_customer_profile'])) {
    foreach ($result['commerce_customer_profile'] as $profile) {
      $return[] = entity_load_single('commerce_customer_profile', $profile->profile_id);
    }
  }
  return $return;
}
