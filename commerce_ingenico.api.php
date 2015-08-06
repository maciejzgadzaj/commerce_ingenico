<?php

/**
 * @file
 * API and hooks documentation for the Commerce Ingenico module.
 */

use Ogone\Passphrase;
use Ogone\ShaComposer\AllParametersShaComposer;
use Ogone\HashAlgorithm;

/**
 * Api class providing functions to make api calls.
 */
class IngenicoApi {
  const DOMAIN = 'secure.ogone.com';

  /**
   * Set merchant credentials.
   */
  public function __construct($settings, $payment_method = '') {
	$this->payment_method = trim($payment_method);  
    $this->merchant_id = trim($settings['pspid']);
    $this->user_id = trim($settings['userid']);
    $this->password = trim($settings['password']);
    $this->sha_in = trim($settings['sha_in']);
    $this->sha_out = trim($settings['sha_out']);
    $this->api_logs = $settings['api_logs'];
  }

  /**
   * Prepare the phrase that will be used for the sha algorithm.
   */
  public function preparePhraseToHash($sha_type, $sha_algorithm = NULL) {
    // Get the sha istance from the library.
    $load_library = libraries_load('ogone');
    libraries_load_files($load_library);
    if ($sha_type == 'sha_in') {
      $pass_phrase = new Passphrase(trim($this->sha_in));
    }
    elseif ($sha_type == 'sha_out') {
      $pass_phrase = new Passphrase(trim($this->sha_out));
    }
    if (empty($sha_algorithm)) {
      $payment_methods = commerce_payment_method_instance_load($this->payment_method);
      $sha_algorithm = $payment_methods['settings']['sha_algorithm'];
    }
    switch ($sha_algorithm) {
      case 'SHA-1':
        $sha = new HashAlgorithm('sha1');
        break;

      case 'SHA-256':
        $sha = new HashAlgorithm('sha256');
        break;

      case 'SHA-512':
        $sha = new HashAlgorithm('sha512');
        break;
    }
    return $sha_composer = new AllParametersShaComposer($pass_phrase, $sha);
  }

  /**
   * Perform direct payments.
   */
  public function directPayments($customer_profile, $order, $card_info, $type = 'SAL', $amount = '') {
    global $base_root;
    $currency_code = empty($amount->currency_code) ? $order->commerce_order_total['und'][0]['currency_code'] : $amount->currency_code;
    $charge_amount = empty($amount->amount) ? $order->commerce_order_total['und'][0]['amount'] : $amount->amount;

    $payment_methods = commerce_payment_method_instance_load($this->payment_method);

    // Get the hash algorithm.
    $sha_composer = self::preparePhraseToHash('sha_in');

    if ($customer_profile[0]->commerce_customer_address['und'][0]['name_line'] != $card_info['credit_card']['owner']) {
      $card_owner_name = $card_info['credit_card']['owner'];
    }
    else {
      $card_owner_name = $customer_profile[0]->commerce_customer_address['und'][0]['name_line'];
    }

    // All of the billing data needed for the request.
    $billing_data = array(
      'PSPID' => trim($this->merchant_id),
      'ORDERID' => trim($order->order_id . '-' . time()),
      'USERID' => trim($this->user_id),
      'PSWD' => trim($this->password),
      'AMOUNT' => trim($charge_amount),
      'CURRENCY' => trim($currency_code),
      'CARDNO' => trim($card_info['credit_card']['number']),
      'ED' => trim($card_info['credit_card']['exp_month'] . substr($card_info['credit_card']['exp_year'], 2, 4)),
      'COM' => trim(t('Order')),
      'CN' => trim($card_owner_name),
      'EMAIL' => trim($order->mail),
      'CVC' => trim($card_info['credit_card']['code']),
      'OWNERADDRESS' => trim($customer_profile[0]->commerce_customer_address['und'][0]['thoroughfare']),
      'OWNERZIP' => trim($customer_profile[0]->commerce_customer_address['und'][0]['postal_code']),
      'OWNERTOWN' => trim($customer_profile[0]->commerce_customer_address['und'][0]['dependent_locality']),
      'OWNERCTY' => trim($customer_profile[0]->commerce_customer_address['und'][0]['country']),
      'OPERATION' => (!empty($payment_methods['settings']['transaction_type_process']) and $payment_methods['settings']['transaction_type_process'] == 'sale') ? 'VEN' : 'RES',
      'REMOTE_ADDR' => ip_address(),
      'RTIMEOUT' => trim(30),
      'ECI' => trim('7'),
      'ORIG' => 'OGDC140415',
      'BRAND' => $card_info['credit_card']['type'],
      'PM' => 'CreditCard',
    );
    // 3d secure check.
    if (!empty($payment_methods['settings']['3d_secure']) and $payment_methods['settings']['3d_secure'] == 0) {
      $billing_data['FLAG3D'] = 'Y';
      $billing_data['HTTP_ACCEPT'] = $_SERVER['HTTP_ACCEPT'];
      $billing_data['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'];
      $billing_data['WIN3DS'] = 'MAINW';
      $billing_data['ACCEPTURL'] = $base_root . '/commerce_ingenico/3ds/callback';
      $billing_data['DECLINEURL'] = $base_root . '/commerce_ingenico/3ds/callback';
      $billing_data['EXCEPTIONURL'] = $base_root . '/commerce_ingenico/3ds/callback';
      $billing_data['PARAMPLUS'] = 'ORDERID=' . $order->order_id;
      $billing_data['COMPLUS'] = 'SUCCESS';
      $billing_data['LANGUAGE'] = $payment_methods['settings']['language_list']['default_language'];
    }

    // Hash the sha phrace with the billing data.
    $shasign = $sha_composer->compose($billing_data);
    $billing_data['SHASIGN'] = $shasign;

    // Url encode.
    foreach ($billing_data as $key => $value) {
      $data[$key] = urlencode($value);
    }
    $payment_method_account = explode('|', $payment_methods['settings']['account']);
    $payment_account_type = $payment_method_account[0];

    return $this->request($payment_account_type, $data, $operation = 'direct');

  }

  /**
   * Perform cross payment.
   */
  public function crossPayment($order, $transaction, $type = '', $operation = 'capture', $pay_id = '', $sub_id = '', $amount = '') {
    // Build the sha algorithm.
    $sha_composer = self::preparePhraseToHash('sha_in');

    $payment_methods = commerce_payment_method_instance_load($transaction->instance_id);
    $payment_method_account = explode('|', $payment_methods['settings']['account']);
    $payment_account_type = $payment_method_account[0];
    $billing_data = array(
      'PSPID' => trim($this->merchant_id),
      'USERID' => trim($this->user_id),
      'PSWD' => trim($this->password),
      'PAYID' => trim($transaction->remote_id),
      'AMOUNT' => empty($amount->amount) ? $transaction->amount : $amount->amount,
      'CURRENCY' => $transaction->currency_code,
      'OPERATION' => empty($type) ? 'VEN' : trim($type),
    );
    if (!empty($sub_id)) {
      $billing_data['PAYIDSUB'] = $sub_id;
    }
    if (!empty($pay_id)) {
      $billing_data['PAYID'] = $pay_id;
    }
    // Hash the sha phrace with the billing data.
    $shasign = $sha_composer->compose($billing_data);
    $billing_data['SHASIGN'] = $shasign;

    // Url encode.
    foreach ($billing_data as $key => $value) {
      $data[$key] = urlencode($value);
    }

    return $this->request($payment_account_type, $data, $operation);
  }

  /**
   * Create the actual http request.
   */
  public function request($payment_account_type, $data, $operation) {
    $build_result = $this->buildUrl($payment_account_type, $data, $operation);

    $this->logRequest(array(
      '@type' => 'Request',
      '@operation' => $operation,
      '%value' => $build_result,
    ));

    $result = drupal_http_request($build_result['url'], $build_result['parameters']);
    $this->logRequest(array(
      '@type' => 'Response',
      '@operation' => $operation,
      '%value' => $result,
    ));

    return $result;
  }

  /**
   * Build the url needed for the http request.
   */
  public function buildUrl($payment_account_type, $data, $operation) {
    if ($payment_account_type == 'test') {
      if ($operation == 'direct') {
        $url = 'https://' . self::DOMAIN . '/ncol/test/orderdirect.asp';
      }
      elseif ($operation == 'capture' or $operation == 'refund' or $operation == 'cancel') {
        $url = 'https://' . self::DOMAIN . '/ncol/test/maintenancedirect.asp';
      }
      elseif ($operation == 'query') {
        $url = 'https://' . self::DOMAIN . '/ncol/test/querydirect.asp';
      }
    }
    else {
      $url = 'https://' . self::DOMAIN . '/ncol/prod/orderdirect.asp';
      if ($operation == 'direct') {
        $url = 'https://' . self::DOMAIN . '/ncol/prod/orderdirect.asp';
      }
      elseif ($operation == 'capture' or $operation == 'refund' or $operation == 'cancel') {
        $url = 'https://' . self::DOMAIN . '/ncol/prod/maintenancedirect.asp';
      }
      elseif ($operation == 'query') {
        $url = 'https://' . self::DOMAIN . '/ncol/prod/querydirect.asp';
      }
    }

    // Build the url parameters.
    foreach ($data as $key => $value) {
      $params[] = $key . '=' . $value;
    }
    $options = '';
    foreach ($params as $key => $value) {
      if ($key === count($params) - 1) {
        $options .= $value;
      }
      else {
        $options .= $value . '&';
      }
    }

    $options_array = array(
      'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
      'method' => 'POST',
      'data' => $options,
      'timeout' => 100,
    );

    $result = array(
      'url' => $url,
      'parameters' => $options_array,
    );
    return $result;
  }

  /**
   * Perform query request.
   */
  public function query($transaction, $pay_id, $sub_id = '', $order = '') {
    $data = array(
      'PSPID' => trim($this->merchant_id),
      'USERID' => trim($this->user_id),
      'PSWD' => trim($this->password),
      'PAYID' => trim($pay_id),
    );
    if (!empty($sub_id)) {
      $data['PAYIDSUB'] = $sub_id;
    }
    // Url encode.
    foreach ($data as $key => $value) {
      $encoded_data[$key] = urlencode($value);
    }

    $payment_methods = commerce_payment_method_instance_load($transaction->instance_id);
    if (empty($payment_methods['settings']['account'])) {
      $payment_account_type = 'test';
    }
    else {
      $payment_method_account = explode('|', $payment_methods['settings']['account']);
      $payment_account_type = $payment_method_account[0];
    }

    return $this->request($payment_account_type, $encoded_data, $operation = 'query');
  }

  /**
   * Extract the response data and convert it from xml to array.
   */
  public function getResponseData($result_data) {
    $xml = simplexml_load_string($result_data);
    if (!empty($xml)) {
      foreach ($xml->attributes() as $key => $value) {
        $data[$key] = (string) $value;
      }
    }
    return $data;
  }

  /**
   * Simple logger for saving both requests and responses in Drupal dblog.
   *
   * @param array $variables
   *   An array of variables to construct dblog message from.
   */
  public function logRequest($variables) {
    // $type will be either 'request' or 'response'. It should match 'api_logs'
    // array keys defined in commerce_ingenico_settings_form().
    $type = strtolower($variables['@type']);
    if (!empty($this->api_logs[$type])) {
      // This could be an array, or an object, or who knows what - let's wrap
      // it then in <pre> and output a parsable string.
      $variables['%value'] = var_export($variables['%value'], TRUE);

      watchdog('commerce_ingenico', '@type (@operation): <pre>%value</pre>', $variables, WATCHDOG_DEBUG);
    }
  }

}

/**
 * Alter payment data before it is sent to Ingenico.
 *
 * Allows modules to alter the payment data before the data is signed and sent
 * to Ingenico.
 *
 * @param array &$data
 *   The data that is to be sent to Ingenico as an associative array.
 * @param object $order
 *   The commerce order object being processed.
 * @param array $settings
 *   The configuration settings.
 */
function hook_commerce_ingenico_data_alter(&$data, $order, $settings) {
  global $language;

  // Set the dynamic template to be used by Ingenico.
  $data['TP'] = url('checkout/ingenico', array('absolute' => TRUE));

  // For multilingual sites, attempt to use the site's active language rather
  // than the language configured through the payment method settings form.
  $language_mapping = array(
    'nl' => 'nl_BE',
    'fr' => 'fr_FR',
    'en' => 'en_US',
  );
  $data['LANGUAGE'] = isset($language_mapping[$language->language]) ? $language_mapping[$language->language] : $settings['language'];
}
