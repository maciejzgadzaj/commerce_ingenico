<?php

/**
 * @file
 * commerce_abandoned_cart_notification.rules.inc
 */


/**
 * Implements hook_rules_action_info().
 */
function commerce_ingenico_rules_action_info() {
  $actions = array();

  // The action for sync transactions between ingenico site and drupal.
  $actions['commerce_ingenico_perform_query'] = array(
    'label' => t('Compare status of remote transactions'),
    'parameter' => array(
      'transaction' => array(
        'type' => 'commerce_payment_transaction',
        'label' => t('Transaction'),
      ),
    ),
    'group' => t('Commerce'),
    'callbacks' => array(
      'execute' => 'commerce_ingenico_query_update',
    ),
  );

  // The batch rule for capturing transactions.
  $actions['commerce_ingenico_batch_capture'] = array(
    'label' => t('Automated capturing process.'),
    'parameter' => array(
      'date' => array(
        'type' => 'date',
        'label' => t('Enter date from which to capture.'),
        'description' => t('You can enter date from which to start capturing transactions or enter negative number of days to start capturing from the days before today (ex. -1 day, -2 days, -3 days …) or enter "now" to capture all transactions from today.'),
      ),
    ),
    'group' => t('Ingenico'),
    'callbacks' => array(
      'execute' => 'commerce_ingenico_batch_rule',
    ),
  );

  return $actions;
}
