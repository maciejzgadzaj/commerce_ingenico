<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;

trait ConfigurationTrait {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'pspid' => '',
      'userid' => '',
      'password' => '',
      'sha_algorithm' => '',
      'sha_in' => '',
      'sha_out' => '',
      'api_logging' => [
        'request' => 'request',
        'response' => 'response',
      ],
//      '3d_secure' => '',
      'whitelabel' => [
        'base_url' => [
          'test' => '',
          'live' => '',
        ],
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['pspid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PSPID'),
      '#description' => $this->t('Your Ingenico PSPID login username'),
      '#default_value' => $this->configuration['pspid'],
      '#required' => TRUE,
    ];

    $form['userid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('USERID'),
      '#description' => $this->t('Your API username.'),
      '#default_value' => $this->configuration['userid'],
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PSWD'),
      '#description' => $this->t('Your API password.'),
      '#default_value' => $this->configuration['password'],
      '#required' => TRUE,
    ];

    $form['sha_algorithm'] = [
      '#type' => 'select',
      '#title' => $this->t('SHA algorithm type'),
      '#description' => $this->t('You can choose from SHA-1, SHA-256 and SHA-512 algorithm types to hash your data.'),
      '#options' => [
        'SHA-1' => 'SHA-1',
        'SHA-256' => 'SHA-256',
        'SHA-512' => 'SHA-512',
      ],
      '#default_value' => $this->configuration['sha_algorithm'],
      '#required' => TRUE,
    ];

    $form['sha_in'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SHA-IN passphrase'),
      '#description' => $this->t('The SHA-IN Pass phrase as entered in Ingenico technical settings - "Data and origin verification" tab.'),
      '#default_value' => $this->configuration['sha_in'],
      '#required' => TRUE,
    ];

    $form['sha_out'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SHA-OUT passphrase'),
      '#description' => $this->t('The SHA-OUT Pass phrase as entered in Ingenico technical settings - "Transaction feedback" tab.'),
      '#default_value' => $this->configuration['sha_out'],
      '#required' => TRUE,
    ];

    $form['api_logging'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Log the following messages for debugging'),
      '#options' => array(
        'request' => $this->t('API request messages'),
        'response' => $this->t('API response messages'),
      ),
      '#default_value' => $this->configuration['api_logging'],
    ];

//    $form['3d_secure'] = [
//      '#type' => 'radios',
//      '#title' => $this->t('3D Secure security check of customers cards.'),
//      '#options' => [
//        '0' => $this->t('Request 3-D Secure authentication when available.'),
//        '1' => $this->t('No 3-D Secure authentication required.'),
//      ],
//      '#default_value' => $this->configuration['3d_secure'],
//    ];

    // Settings for white label clones of Ingenico, for example BarclayCard:
    // https://www.barclaycard.co.uk/business/accepting-payments/website-payments/web-developer-resources#tabbox1
    // https://mdepayments.epdq.co.uk/Ncol/Test/BackOffice/login/index
    $form['whitelabel'] = [
      '#type' => 'details',
      '#title' => $this->t('White label'),
      '#description' => $this->t('Only provide these URLs when using a white label clone of Ingenico payment gateway.'),
      '#open' => !empty($this->configuration['whitelabel']['base_url']['test']) || !empty($this->configuration['whitelabel']['base_url']['live']),
    ];

    $form['whitelabel']['base_url_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test API base URL'),
      '#description' => $this->t('Including trailing slash. For example: <em>https://mdepayments.epdq.co.uk/</em>'),
      '#default_value' => $this->configuration['whitelabel']['base_url']['test'],
    ];

    $form['whitelabel']['base_url_live'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live API base URL'),
      '#description' => $this->t('Including trailing slash. For example: <em>https://payments.epdq.co.uk/</em>'),
      '#default_value' => $this->configuration['whitelabel']['base_url']['live'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['pspid'] = $values['pspid'];
      $this->configuration['userid'] = $values['userid'];
      $this->configuration['password'] = $values['password'];
      $this->configuration['sha_algorithm'] = $values['sha_algorithm'];
      $this->configuration['sha_in'] = $values['sha_in'];
      $this->configuration['sha_out'] = $values['sha_out'];
      $this->configuration['api_logging'] = $values['api_logging'];
//      $this->configuration['3d_secure'] = $values['3d_secure'];
      $this->configuration['whitelabel']['base_url']['test'] = $values['whitelabel']['base_url_test'];
      $this->configuration['whitelabel']['base_url']['live'] = $values['whitelabel']['base_url_live'];
    }
  }

  /**
   * Returns Ingenico API URL for current mode and whitelist settings.
   *
   * @param CreateAliasRequest|DirectLinkPaymentRequest|DirectLinkMaintenanceRequest $request
   *   Ingenico request object used to create the request.
   *
   * @return string
   *   The Ingenico API URL.
   */
  public function getOgoneUri($request) {
    $mode = $this->getMode();
    $ogone_uri = $mode == 'live' ? $request::PRODUCTION : $request::TEST;

    if (!empty($this->configuration['whitelabel']['base_url'][$mode])) {
      $ogone_uri = str_replace('https://secure.ogone.com/', $this->configuration['whitelabel']['base_url'][$mode], $ogone_uri);
    }

    return $ogone_uri;
  }

}
