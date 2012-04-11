<?php
/* @file
 * API and hooks documentation for the Commerce Ogone module.
 */

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
