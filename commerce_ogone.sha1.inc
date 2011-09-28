<?php

/**
 * @file
 *
 * SHA-1 security hash specific functionality
 */

/**
 * Validate the feedback with the SHA-1 signature
 */
function commerce_ogone_feedback_valid_sha1($feedback, $sha1out) {
  $incoming_sha1 = '';
  foreach (commerce_ogone_sha1_out_parameters() as $param) {
    if (isset($feedback[$param]) && $feedback[$param] !== '') {
      $incoming_sha1 .= $param . '=' . $feedback[$param] . $sha1out;
    }
  }

  return strtoupper(sha1($incoming_sha1)) === $feedback['SHASIGN'];
}

/**
 * Get a list of valid SHA-1 out parameters
 */
function commerce_ogone_sha1_out_parameters() {
  return array('AAVADDRESS', 'AAVCHECK', 'AAVZIP', 'ACCEPTANCE', 'ALIAS', 'AMOUNT',
    'BIN', 'BRAND', 'CARDNO', 'CCCTY', 'CN', 'COMPLUS', 'CREATION_STATUS', 'CURRENCY',
    'CVCCHECK', 'DCC_COMMPERCENTAGE', 'DCC_CONVAMOUNT', 'DCC_CONVCCY', 'DCC_EXCHRATE',
    'DCC_EXCHRATESOURCE', 'DCC_EXCHRATETS', 'DCC_INDICATOR', 'DCC_MARGINPERCENTAGE',
    'DCC_VALIDHOURS', 'DIGESTCARDNO', 'ECI', 'ED', 'ENCCARDNO', 'IP', 'IPCTY',
    'NBREMAILUSAGE', 'NBRIPUSAGE', 'NBRIPUSAGE_ALLTX', 'NBRUSAGE', 'NCERROR',
    'ORDERID', 'PAYID', 'PM', 'SCO_CATEGORY', 'SCORING', 'STATUS', 'SUBSCRIPTION_ID',
    'TRXDATE', 'VC');
}
