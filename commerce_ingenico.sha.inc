<?php

/**
 * @file
 * SHA security hash specific functionality
 */

/**
 * Validate the feedback with the SHA signature.
 */
function commerce_ingenico_feedback_valid_sha($feedback) {
  $incoming_sha = '';
  foreach (commerce_ingenico_sha_out_parameters() as $param) {
    if (isset($feedback[$param]) && $feedback[$param] !== '') {
      $incoming_sha[$param] = $feedback[$param];
    }
  }
  // Get the right sha algorithm.
  $api = commerce_ingenico_api_object();
  $sha_composer = $api->preparePhraseToHash('sha_out');
  $sha_out = $sha_composer->compose($incoming_sha);
  return strtoupper($sha_out) === $feedback['SHASIGN'];
}

/**
 * Get a list of valid SHA out parameters.
 */
function commerce_ingenico_sha_out_parameters() {
  return array('AAVADDRESS', 'AAVCHECK', 'AAVZIP', 'ACCEPTANCE', 'ALIAS', 'AMOUNT',
    'BIN', 'BRAND', 'CARDNO', 'CCCTY', 'CN', 'COMPLUS', 'CREATION_STATUS', 'CURRENCY',
    'CVCCHECK', 'DCC_COMMPERCENTAGE', 'DCC_CONVAMOUNT', 'DCC_CONVCCY', 'DCC_EXCHRATE',
    'DCC_EXCHRATESOURCE', 'DCC_EXCHRATETS', 'DCC_INDICATOR', 'DCC_MARGINPERCENTAGE',
    'DCC_VALIDHOURS', 'DIGESTCARDNO', 'ECI', 'ED', 'ENCCARDNO', 'IP', 'IPCTY',
    'NBREMAILUSAGE', 'NBRIPUSAGE', 'NBRIPUSAGE_ALLTX', 'NBRUSAGE', 'NCERROR',
    'ORDERID', 'PAYID', 'PM', 'SCO_CATEGORY', 'SCORING', 'STATUS', 'SUBSCRIPTION_ID',
    'TRXDATE', 'VC');
}
