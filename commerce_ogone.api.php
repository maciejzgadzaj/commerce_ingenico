<?php

/**
 * @file
 * API and hooks documentation for the Commerce Ogone module.
 */

use Ogone\Passphrase;
use Ogone\ShaComposer\AllParametersShaComposer;
use Ogone\HashAlgorithm;

/**
 * Api class providing functions to make api calls.
 */
class OgoneApi {
  const DOMAIN = 'secure.ogone.com';

  /**
   * Set merchant credentials.
   */
  public function __construct($settings) {
    $this->merchant_id = trim($settings['pspid']);
    $this->user_id = trim($settings['userid']);
    $this->password = trim($settings['password']);
    $this->sha_in = trim($settings['sha_in']);
    $this->sha_out = trim($settings['sha_out']);
  }

  /**
   * Prepare the phrase that will be used for the sha algorithm.
   */
  public function prepare_phrase_to_hash($sha_type) {
    //Get the sha istance from the library.
    $library = libraries_info('ogone');
    $load_library = libraries_load('ogone');
    libraries_load_files($load_library);
    if ($sha_type = 'sha_in') {
      $pass_phrase = new Passphrase(trim($this->sha_in));
    }
    elseif ($sha_type == 'sha_out') {
      $pass_phrase = new Passphrase(trim($this->sha_out));
    }
    $payment_methods = commerce_payment_method_instance_load('ogone_direct|commerce_payment_ogone_direct');
    $sha_algorithm = $payment_methods['settings']['sha_algorithm'];
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
    $site_name = variable_get('site_name');
    //$currency = currency_load(empty($amount->currency_code) ? $order->commerce_order_total['und'][0]['currency_code'] : $amount->currency_code );
    //$currency_code = $currency->ISO4217Code;
    $currency_code = empty($amount->currency_code) ? $order->commerce_order_total['und'][0]['currency_code'] : $amount->currency_code;
    $charge_amount = empty($amount->amount) ? $order->commerce_order_total['und'][0]['amount'] : $amount->amount;

    $payment_methods = commerce_payment_method_instance_load('ogone_direct|commerce_payment_ogone_direct');

    //Get the hash algorithm.
    $sha_composer = self::prepare_phrase_to_hash('sha_in');

    //All of the billing data needed for the request.
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
      'CN' =>  trim($customer_profile[0]->commerce_customer_address['und'][0]['name_line']),
      'EMAIL' => trim($order->mail),
      'CVC' => trim($card_info['credit_card']['code']),
      'OWNERADDRESS' => trim($customer_profile[0]->commerce_customer_address['und'][0]['thoroughfare']),
      'OWNERZIP' => trim($customer_profile[0]->commerce_customer_address['und'][0]['postal_code']),
      'OWNERTOWN' => trim($customer_profile[0]->commerce_customer_address['und'][0]['dependent_locality']),
      'OWNERCTY' => trim($customer_profile[0]->commerce_customer_address['und'][0]['country']),
      'OPERATION' => ($payment_methods['settings']['transaction_type_process'] == 'capture_manual') ? trim('RES') : trim($type),
      'REMOTE_ADDR' => ip_address(),
      'RTIMEOUT' => trim(30),
      'ECI' => trim('7'),
      );

   //3d secure check.
    if ($payment_methods['settings']['3d_secure'] == 0) {
      $billing_data['FLAG3D'] = 'Y';
      $billing_data['HTTP_ACCEPT'] = $_SERVER['HTTP_ACCEPT'];
      $billing_data['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'];
      $billing_data['WIN3DS'] = 'MAINW';
      $billing_data['ACCEPTURL'] = $base_root . '/commerce_ogone/3ds/callback';
      $billing_data['DECLINEURL'] = $base_root . '/commerce_ogone/3ds/callback';
      $billing_data['EXCEPTIONURL'] = $base_root . '/commerce_ogone/3ds/callback';
      $billing_data['PARAMPLUS'] = 'ORDERID=' . $order->order_id;
      $billing_data['COMPLUS'] = 'SUCCESS';
      $billing_data['LANGUAGE'] = $payment_methods['settings']['language_list']['selected'];
    }

    //Hash the sha phrace with the billing data.
    $shasign = $sha_composer->compose($billing_data);
    $billing_data['SHASIGN'] = $shasign;

    //Url encode
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
    //Build the sha algorithm.
    $sha_composer = self::prepare_phrase_to_hash('sha_in');

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
      'OPERATION' => empty($type) ? 'SAL' : trim($type),
    );
    if (!empty($sub_id)) {
      $billing_data['PAYIDSUB'] = $sub_id;
    }
    if (!empty($pay_id)) {
      $billing_data['PAYID'] = $pay_id;
    }
    //Hash the sha phrace with the billing data.
    $shasign = $sha_composer->compose($billing_data);
    $billing_data['SHASIGN'] = $shasign;

      //Url encode
    foreach ($billing_data as $key => $value) {
      $data[$key] = urlencode($value);
    }

    return $this->request($payment_account_type, $data, $operation);
  }

  /**
   * Create the actual http request.
   */
  public function request($payment_account_type, $data, $operation) {
    $build_result = $this->build_url($payment_account_type, $data, $operation);
    $result = drupal_http_request($build_result['url'], $build_result['parameters']);

    return $result;
  }

  /**
   * Build the url needed for the http request.
   */
  public function build_url($payment_account_type, $data, $operation) {
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
        $url = 'https://' . self::DOMAIN . '/ncol/prod/maintenancedirect.asp.';
      }
      elseif ($operation == 'query') {
        $url = 'https://' . self::DOMAIN . '/ncol/prod/querydirect.asp';
      }
    }

    //build the url parameters.
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
    //Url encode.
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
  public function get_response_data($result_data) {
    $xml = simplexml_load_string($result_data);
    if (!empty($xml))
    foreach ($xml->attributes() as $key => $value) {
        $data[$key] = (string)$value;
    }
    return $data;
  }

}

/**
 * Alter payment data before it is sent to Ogone.
 *
 * Allows modules to alter the payment data before the data is signed and sent
 * to Ogone.
 *
 * @param &$data
 *   The data that is to be sent to Ogone as an associative array.
 * @param $order
 *   The commerce order object being processed.
 * @param $settings
 *   The configuration settings.
 *
 * @return
 *   No return value.
 */
function hook_commerce_ogone_data_alter(&$data, $order, $settings) {
  global $language;

  // Set the dynamic template to be used by Ogone.
  $data['TP'] = url('checkout/ogone', array('absolute' => TRUE));

  // For multilingual sites, attempt to use the site's active language rather
  // than the language configured through the payment method settings form.
  $language_mapping = array(
    'nl' => 'nl_BE',
    'fr' => 'fr_FR',
    'en' => 'en_US',
  );
  $data['LANGUAGE'] = isset($language_mapping[$language->language]) ? $language_mapping[$language->language] : $settings['language'];
}