<?php

/**
 * @file
 * Provides page callback functionality for Commerce Ogone module
 */

function commerce_ogone_process_feedback() {
  // Check which request method was used
  if (isset($_REQUEST['SHASIGN'])) {
    $feedback = array();
    $feedback = array_map('check_plain', $_REQUEST);
    dsm($feedback);
    if (commerce_ogone_feedback_valid($feedback)) {
      drupal_set_message('valid feedback');
    }
    else {
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
 * Validate the feedback with the SHA-1 signature
 */
function commerce_ogone_feedback_valid($feedback) {
  $sha1out = '6E974A568AF503CF410DF0FC50FE8D4166A827412343';
  $values = array(
    'ACCEPTANCE' => $feedback['ACCEPTANCE'],
    'AMOUNT' => $feedback['amount'],
    'BRAND' => $feedback['BRAND'],
    'CARDNO' => $feedback['CARDNO'],
    'CURRENCY' => $feedback['currency'],
    'NCERROR' => $feedback['NCERROR'],
    'ORDERID' => $feedback['orderID'],
    'PAYID' => $feedback['PAYID'],
    'PM' => $feedback['PM'],
    'STATUS' => $feedback['STATUS'],
    'ED' => $feedback['ED'],
    'CN' => $feedback['CN'],
    'TRXDATE' => $feedback['TRXDATE'],
    'IP' => $feedback['IP'],
  );
  //$values = array_filter($values); // remove blank entries
  ksort($values); // sort alphabetically

  $incoming_sha1 = '';
  foreach ($values as $key => $val) {
    $incoming_sha1 .= $key . '=' . $val . $sha1out;
  }
  if (sha1($incoming_sha1) == $feedback['SHASIGN']) {
    return TRUE;
  }
  return FALSE;
}