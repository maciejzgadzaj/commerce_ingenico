<?php
/**
 * @file
 * Defines default example rule for Ingenico.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_ingenico_default_rules_configuration() {
  // Reaction rule triggered on cron run that updates local transaction statuses
  // with their remote statuses.
  if (module_exists('views_bulk_operations')) {
    $rule = rules_reaction_rule();
    $rule->label = t('Compare remote status with local');
    $rule->tags = array('Commerce Order');
    $rule->active = FALSE;
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
        'entity_list:var' => 'all_pending_transactions',
      ));

    $loop = rules_loop(array(
      'list:select' => 'all-pending-transactions',
      'item:label' => t('Current transaction'),
      'item:var' => 'current_transaction',
    ));

    $loop->action('commerce_ingenico_perform_query', array(
      'transaction:select' => 'current-transaction',
    ));

    $rule->action($loop);
    $rule->action('drupal_message', array(
       'message' => 'Transaction statuses were updated.',
    ));
    $rules['commerce_ingenico_compare_remote_status_with_local'] = $rule;
  }

  // Reaction rule triggered on cron run that captures pending transactions.
  $rule = rules_reaction_rule();
  $rule->label = t('Capture transactions on cron run.');
  $rule->tags = array('Commerce Order');
  $rule->active = FALSE;
  $rule
    ->event('cron')
    ->action('commerce_ingenico_batch_capture', array(
      'date' => '-1 day',
    ));

  $rules['commerce_ingenico_capture_transactions_on_cron_run'] = $rule;

  return $rules;
}
