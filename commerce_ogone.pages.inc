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

    if (commerce_ogone_feedback_valid_sha1($feedback)) {
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