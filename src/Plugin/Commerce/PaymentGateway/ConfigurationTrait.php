<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use Ogone\Ecommerce\EcommercePaymentRequest;
use Ogone\HashAlgorithm;
use Ogone\Passphrase;
use Ogone\ShaComposer\AllParametersShaComposer;

trait ConfigurationTrait {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'pspid' => '',
      'userid' => '',
      'password' => '',
      'sha_algorithm' => HashAlgorithm::HASH_SHA512,
      'sha_in' => '',
      'sha_out' => '',
      'tp' => '',
      'language' => 'en_US',
      'api_logging' => [
        'request' => 'request',
        'response' => 'response',
      ],
      '3ds' => [
        '3d_secure' => 1,
        '3d_secure_ecommerce_gateway' => '',
      ],
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
        HashAlgorithm::HASH_SHA1 => 'SHA-1',
        HashAlgorithm::HASH_SHA256 => 'SHA-256',
        HashAlgorithm::HASH_SHA512 => 'SHA-512',
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

    $form['tp'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Template URL'),
      '#description' => $this->t('The full URL of the Template Page hosted on the merchant\'s site and containing the "payment string"'),
      '#default_value' => $this->configuration['tp'],
    ];

    $shaComposer = new AllParametersShaComposer(new Passphrase(''));
    $ecommercePaymentRequest = new EcommercePaymentRequest($shaComposer);
    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $ecommercePaymentRequest->allowedlanguages,
      '#default_value' => $this->configuration['language'],
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

    // 3-D Secure authentication works only with DirectLink payment solution.
    if ($this->getPluginId() == 'ingenico_directlink') {
      $form['3ds'] = [
        '#type' => 'details',
        '#title' => $this->t('3D-Secure'),
        '#open' => TRUE,
      ];

      // The principle of the integration of DirectLink with 3-D Secure is to
      // initiate a payment in DirectLink mode and end it in e-Commerce mode
      // if a cardholder authentication is requested. Therefore to be able to
      // use 3-D Secure, we need to have a e-Commerce payment gateway defined.
      // @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink-3-d
      $gateways = $this->entityTypeManager->getStorage('commerce_payment_gateway')->loadByProperties(['plugin' => 'ingenico_ecommerce']);
      $options = [];
      foreach ($gateways as $id => $gateway) {
        $options[$id] = $gateway->label();
      }

      if (!empty($options)) {
        $form['3ds']['3d_secure'] = [
          '#type' => 'radios',
          '#options' => [
            '1' => $this->t('Request 3-D Secure authentication when available'),
            '0' => $this->t('No 3-D Secure authentication required'),
          ],
          '#default_value' => $this->configuration['3ds']['3d_secure'],
        ];

        $form['3ds']['3d_secure_ecommerce_gateway'] = [
          '#type' => 'select',
          '#title' => $this->t('e-Commerce gateway for 3-D Secure'),
          '#description' => $this->t('If a cardholder 3-D Secure authentication is requested, the payments initiated in DirectLink mode will end in e-Commerce mode.'),
          '#options' => $options,
          '#default_value' => $this->configuration['3ds']['3d_secure_ecommerce_gateway'],
          '#states' => [
            'visible' => [
              ':input[name="configuration[3ds][3d_secure]"]' => array('value' => 1),
            ],
          ],
        ];
      }
      else {
        $form['3ds']['info'] = [
          '#markup' => $this->t('To use 3-D Secure, you must first add and configure Ingenico e-Commerce payment gateway.'),
        ];
      }

    }

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
      $this->configuration['tp'] = $values['tp'];
      $this->configuration['language'] = $values['language'];
      $this->configuration['api_logging'] = $values['api_logging'];
      if (isset($values['3ds'])) {
        $this->configuration['3ds'] = $values['3ds'];
      }
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
