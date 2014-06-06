<?php
/**
 * @file
 * Defines default example rule for Ogone.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_ogone_default_rules_configuration() {
   //Reaction rule.
  $rule = rules_reaction_rule();
  $rule->label = t('Compare remote status with local');
  $rule->tags = array('Commerce Order');
  $rule->active = TRUE;
  $rule
    ->event('cron')
    ->action('entity_fetch', array(
      'type' => 'user',
      'id' => '1',
      'entity_fetched:label' => 'Admin user',
      'entity_fetched:var' => 'admin_user',
    ))
    ->action('views_bulk_operations_action_load_list', array(
      'view' => 'transactions_in_pending_status|default',
      'view_user:select' => 'admin-user',
      'entity_list:label' => t('All pending transactions'),
      'entity_list:var' => 'all_pending_transactions'
    ));

  $loop = rules_loop(array(
    'list:select' => 'all-pending-transactions',
    'item:label' => t('Current transaction'),
    'item:var' => 'current_transaction',
  ));

  $loop->action('perform_query', array(
    'transaction:select' => 'current-transaction',
  ));

  $rule->action($loop);
  $rule->action('drupal_message', array(
     'message' => 'Transaction statuses were updated.',
  ));
  $rules['rules_compare_remote_status_with_local'] = $rule;

  return $rules;
}