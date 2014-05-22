<?php

/**
 * @file
 * commerce_abandoned_cart_notification.rules.inc
 */


/**
 * Implements hook_rules_action_info().
 */
function commerce_ogone_example_rules_action_info() {
  $actions = array();

  $actions['perform_query'] = array(
    'label' => t('Compare status of remote transactions'),
    'parameter' => array(
      'transaction' => array(
        'type' => 'commerce_payment_transaction',
        'label' => t('Transaction'),
      ),
    ),
    'group' => t('Commerce'),
    'callbacks' => array(
      'execute' => 'commerce_ogone_example_query',
    ),
  );

  return $actions;
}