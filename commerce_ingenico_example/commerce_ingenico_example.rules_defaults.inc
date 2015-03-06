<?php
/**
 * @file
 * Defines default example rule for Ingenico.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_ingenico_example_default_rules_configuration() {
  // Component.
  $rule = rule(array(
      'component_transactions' => array('label' => 'component transactions', 'type' => 'commerce_payment_transaction'),
    )
  );
  $rule->label = t('Get the pending transactions and capture them.');
  $rule->tags = array('Commerce Order');
  $rule->active = TRUE;
  $rule->condition(rules_condition('data_is', array(
      'data:select' => 'component-transactions:created',
      'op' => '<',
      'value' => '-1 day',
    ))
  );

  $rule->action('data_set', array(
    'data:select' => 'component-transactions',
    'value:select' => 'component-transactions',
  ));

  $rule->action('entity_fetch', array(
    'type' => 'commerce_order',
    'id:select' => 'component-transactions:order-id',
    'value:select' => 'component-transactions',
    'entity_fetched:label' => 'fetched_order_to_pass',
    'entity_fetched:var' => 'fetched_order_to_pass',
  ));

  $rule->action('data_set', array(
    'data:select' => 'fetched-order-to-pass',
    'value:select' => 'fetched-order-to-pass',
  ))
  ->action('commerce_payment_capture', array(
    'commerce_order:select' => 'fetched-order-to-pass',
  ));

  $rules['capture_transaction'] = $rule;

  // Reaction rule.
  $rule = rules_reaction_rule();
  $rule->label = t('Test capture transaction');
  $rule->tags = array('Commerce Order');
  $rule->active = TRUE;
  $rule
    ->event('cron')
    ->action('entity_fetch', array(
      'type' => 'user',
      'id' => '1',
      'entity_fetched:label' => 'Fetched entity',
      'entity_fetched:var' => 'entity_fetched',
    ))
    ->action('views_bulk_operations_action_load_list', array(
      'view' => 'test_capture_order|page',
      'view_user:select' => 'entity-fetched',
      'entity_list:label' => t('A list of entities'),
      'entity_list:var' => 'entity_list',
    ));

  $loop = rules_loop(array(
    'list:select' => 'entity-list',
    'item:label' => t('Current list item'),
    'item:var' => 'list_item',
  ));

  $loop->action('entity_fetch', array(
    'type' => 'commerce_order',
    'id:select' => 'list-item:order-id',
    'entity_fetched:label' => 'fetched order',
    'entity_fetched:var' => 'fetched_order',
  ))
  ->action('entity_save', array(
    'data:select' => 'fetched-order',
  ))
  ->action('entity_query', array(
    'type' => 'commerce_payment_transaction',
    'property' => 'order_id',
    'value:select' => 'fetched-order:order-id',
    'limit' => '1',
    'entity_fetched:label' => 'fetched_transaction',
    'entity_fetched:var' => 'fetched_transaction',
  ))
  ->action('component_capture_transaction', array(
    'component_transactions:select' => 'fetched-transaction:0',
  ));

  $rule->action($loop);

  $rules['rules_get_orders_n_capture_transactions'] = $rule;

  return $rules;
}
