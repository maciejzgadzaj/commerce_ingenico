<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_ingenico\PluginForm\PaymentRenewAuthorizationForm;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use GuzzleHttp\ClientInterface;
use Ogone\DirectLink\Alias;
use Ogone\DirectLink\CreateAliasRequest;
use Ogone\DirectLink\CreateAliasResponse;
use Ogone\DirectLink\DirectLinkMaintenanceRequest;
use Ogone\DirectLink\DirectLinkMaintenanceResponse;
use Ogone\DirectLink\DirectLinkPaymentRequest;
use Ogone\DirectLink\DirectLinkPaymentResponse;
use Ogone\DirectLink\Eci;
use Ogone\DirectLink\MaintenanceOperation;
use Ogone\DirectLink\PaymentOperation;
use Ogone\Passphrase;
use Ogone\ShaComposer\AllParametersShaComposer;
use Ogone\ParameterFilter\AliasShaInParameterFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the HostedFields payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "ingenico_direct_link",
 *   label = "Ingenico DirectLink",
 *   display_label = "Ingenico DirectLink",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa"
 *   }
 * )
 */
class DirectLink extends OnsitePaymentGatewayBase implements DirectLinkInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, ClientInterface $client) {
    $this->httpClient = $client;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
 }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultForms() {
    $default_forms = parent::getDefaultForms();

    $default_forms['renew-payment-authorization'] = 'Drupal\commerce_ingenico\PluginForm\PaymentRenewAuthorizationForm';

    return $default_forms;
  }

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
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaymentOperations(PaymentInterface $payment) {
    $operations = parent::buildPaymentOperations($payment);

    $access = $payment->getState()->value == 'authorization';
    $operations['renew_authorization'] = [
      'title' => $this->t('Renew authorization'),
      'page_title' => $this->t('Renew payment authorization'),
      'plugin_form' => 'renew-payment-authorization',
      'access' => $access,
      'weight' => 10,
    ];

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // Create credit card alias using Ingenico Alias Gateway.
    $alias = $this->doCreatePaymentMethod($payment_method, $payment_details);
    $payment_method->setRemoteId($alias);

    $payment_method->card_type = $payment_details['type'];
    // Only the last 4 numbers are safe to store.
    $payment_method->card_number = substr($payment_details['number'], -4);
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];

    // Payment method expiration timestamp.
    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    $payment_method->setExpiresTime($expires);

    $payment_method->save();
  }

  /**
   * Creates the credit card alias on Ingenico Alias Gateway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/alias-gateway
   */
  protected function doCreatePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);
    $shaComposer->addParameterFilter(new AliasShaInParameterFilter);

    $createAliasRequest = new CreateAliasRequest($shaComposer);

    $createAliasRequest->setPspid($this->configuration['pspid']);
    // Store the alias indifinitely.
    $createAliasRequest->setAliasPersistedAfterUse('Y');

    // Standard Alias Gateway behaviour is that it wants to redirect us back
    // after alias creation (or error) to a URL provided by us. However, we will
    // forbid our HTTP client to follow the redirections (see below), therefore
    // we can set both redirect URLs to anything really, as they won't matter.
    $createAliasRequest->setAccepturl($GLOBALS['base_url']);
    $createAliasRequest->setExceptionurl($GLOBALS['base_url']);

    $createAliasRequest->setCardno($payment_details['number']);
    $createAliasRequest->setCn($payment_method->getBillingProfile()->get('address')->get(0)->getGivenName() . ' ' . $payment_method->getBillingProfile()->get('address')->get(0)->getFamilyName());
    $createAliasRequest->setCvc($payment_details['security_code']);
    $createAliasRequest->setEd($payment_details['expiration']['month'] . substr($payment_details['expiration']['year'], -2));

    $createAliasRequest->validate();

    $ogone_uri = $this->getMode() == 'live' ? CreateAliasRequest::PRODUCTION : CreateAliasRequest::TEST;
    $createAliasRequest->setOgoneUri($ogone_uri);

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $createAliasRequest->toArray();
    $body['SHASIGN'] = $createAliasRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      // Obviously we must not log the full credit card number.
      $body_log = $body;
      $body_log['CARDNO'] = str_pad(substr($body_log['CARDNO'], -4), strlen($body['CARDNO']), 'X', STR_PAD_LEFT);
      \Drupal::logger('commerce_ingenico')
        ->debug('AliasGateway request: @url <pre>@body</pre>', [
          '@url' => $createAliasRequest->getOgoneUri(),
          '@body' => var_export($body_log, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    // Alias Gateway will want to redirect our request to either the Accept URL
    // or Exception URL specified in the request, but we don't want it to do
    // that. Let's then forbid following redirects, and instead we will get
    // the redirection URL from the response and process it ourselves.
    $options = [
      'form_params' => $body,
      'allow_redirects' => FALSE,
    ];
    $response = $this->httpClient->post($createAliasRequest->getOgoneUri(), $options);

    // Validate the API response.
    // We expect to see the redirection in the response.
    if ($response->getStatusCode() != 302) {
      throw new InvalidResponseException($this->t('The request returned with unexpected HTTP code @http_code.', ['@http_code' => $response->getStatusCode()]));
    }
    elseif (!$location = $response->getHeaderLine('Location')) {
      throw new InvalidResponseException($this->t('The response did not contain expected location header.'));
    }

    $location_parsed = UrlHelper::parse($location);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('AliasGateway response: <pre>@body</pre>', [
          '@body' => var_export($location_parsed['query'], TRUE),
        ]);
    }

    // Use all the redirection URL query parameters as the response to process.
    $createAliasResponse = new CreateAliasResponse($location_parsed['query']);

    // Validate response's SHASign.
    $passphrase = new Passphrase($this->configuration['sha_out']);
    $shaComposer = new AllParametersShaComposer($passphrase);
    if (!$createAliasResponse->isValid($shaComposer)) {
      throw new InvalidResponseException($this->t('The gateway response looks suspicious.'));
    }

    if (!$createAliasResponse->isSuccessful()) {
      throw new DeclineException($this->t('Alias creation has been declined by the gateway (@error_code).', [
        '@error_code' => $createAliasResponse->getParam('NCERROR'),
      ]), $createAliasResponse->getParam('NCERROR'));
    }

    return $createAliasResponse->getAlias();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Ingenico does not support deleting credit card aliases through their API.
    // The only option to delete an alias is to do it either manually through
    // their UI, or using Bulk Alias management via batch file. See
    // https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/alias

    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    if ($payment->getState()->value != 'new') {
      throw new \InvalidArgumentException('The provided payment is in an invalid state.');
    }

    $payment_method = $payment->getPaymentMethod();
    if (empty($payment_method)) {
      throw new \InvalidArgumentException('The provided payment has no payment method referenced.');
    }
    if (REQUEST_TIME >= $payment_method->getExpiresTime()) {
      throw new HardDeclineException('The provided payment method has expired');
    }

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $directLinkRequest = new DirectLinkPaymentRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);

    $operation = $capture ? PaymentOperation::REQUEST_FOR_DIRECT_SALE : PaymentOperation::REQUEST_FOR_AUTHORISATION;
    $directLinkRequest->setOperation(new PaymentOperation($operation));

    // Ingenico requires the AMOUNT value to be sent in decimals.
    $directLinkRequest->setAmount((int) $payment->getAmount()->getNumber() * 100);
    $directLinkRequest->setCurrency($payment->getAmount()->getCurrencyCode());
    $directLinkRequest->setOrderid($payment->getOrder()->getOrderNumber() . '-' . $payment->getOrder()->getCreatedTime());
    $directLinkRequest->setCom((string) $this->t('Order @order_number', ['@order_number' => $payment->getOrder()->getOrderNumber()]));

    // Use credit card alias created in DirectLink::doCreatePaymentMethod().
    $alias = new Alias($payment_method->getRemoteId());
    $directLinkRequest->setAlias($alias);

    $directLinkRequest->setEmail($payment_method->getOwner()->getEmail());
    $directLinkRequest->setCn($payment_method->getBillingProfile()->get('address')->get(0)->getGivenName() . ' ' . $payment_method->getBillingProfile()->get('address')->get(0)->getFamilyName());
    $directLinkRequest->setOwnerAddress($payment_method->getBillingProfile()->get('address')->get(0)->getAddressLine1());
    $directLinkRequest->setOwnerZip($payment_method->getBillingProfile()->get('address')->get(0)->getPostalCode());
    $directLinkRequest->setOwnerTown($payment_method->getBillingProfile()->get('address')->get(0)->getLocality());
    $directLinkRequest->setOwnerCty($payment_method->getBillingProfile()->get('address')->get(0)->getCountryCode());

    $directLinkRequest->setRemote_addr($_SERVER['REMOTE_ADDR']);
    $directLinkRequest->setEci(new Eci(Eci::ECOMMERCE_WITH_SSL));

    $directLinkRequest->validate();

    $ogone_uri = $this->getMode() == 'live' ? DirectLinkPaymentRequest::PRODUCTION : DirectLinkPaymentRequest::TEST;
    $directLinkRequest->setOgoneUri($ogone_uri);

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink order request: @url <pre>@body</pre>', [
          '@url' => $directLinkRequest->getOgoneUri(),
          '@body' => var_export($body, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    $options = [
      'form_params' => $body,
    ];
    $response = $this->httpClient->post($directLinkRequest->getOgoneUri(), $options);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink order response: <pre>@body</pre>', [
          '@body' => (string) $response->getBody(),
        ]);
    }

    // Validate the API response.
    if ($response->getStatusCode() != 200) {
      throw new InvalidResponseException($this->t('The request returned with error code @http_code.', ['@http_code' => $response->getStatusCode()]));
    }
    elseif (!$response->getBody()) {
      throw new InvalidResponseException($this->t('The response did not have a body.'));
    }

    $directLinkResponse = new DirectLinkPaymentResponse($response->getBody());
    if (!$directLinkResponse->isSuccessful()) {
      throw new DeclineException($this->t('Payment has been declined by the gateway (@error_code).', [
        '@error_code' => $directLinkResponse->getParam('NCERROR'),
      ]), $directLinkResponse->getParam('NCERROR'));
    }

    $payment->state = $capture ? 'capture_completed' : 'authorization';
    $payment->setRemoteId($directLinkResponse->getParam('PAYID'));
    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
    $payment->setAuthorizedTime(REQUEST_TIME);
    if ($capture) {
      $payment->setCapturedTime(REQUEST_TIME);
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink/maintenance
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException($this->t('Only payments in the "authorization" state can be captured.'));
    }

    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // Validate the requested amount.
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException($this->t('Cannot capture more than @amount.', ['@amount' => (string) $balance]));
    }

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());
    // Ingenico requires the AMOUNT value to be sent in decimals.
    $directLinkRequest->setAmount((int) ($amount->getNumber() * 100));

    $operation = $balance->subtract($amount)->isZero() ? MaintenanceOperation::OPERATION_CAPTURE_LAST_OR_FULL : MaintenanceOperation::OPERATION_CAPTURE_PARTIAL;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    $ogone_uri = $this->getMode() == 'live' ? DirectLinkMaintenanceRequest::PRODUCTION : DirectLinkMaintenanceRequest::TEST;
    $directLinkRequest->setOgoneUri($ogone_uri);

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink capture request: @url <pre>@body</pre>', [
          '@url' => $directLinkRequest->getOgoneUri(),
          '@body' => var_export($body, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    $options = [
      'form_params' => $body,
    ];
    $response = $this->httpClient->post($directLinkRequest->getOgoneUri(), $options);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink capture response: <pre>@body</pre>', [
          '@body' => (string) $response->getBody(),
        ]);
    }

    // Validate the API response.
    if ($response->getStatusCode() != 200) {
      throw new InvalidResponseException($this->t('The request returned with error code @http_code.', ['@http_code' => $response->getStatusCode()]));
    }
    elseif (!$response->getBody()) {
      throw new InvalidResponseException($this->t('The response did not have a body.'));
    }

    $directLinkResponse = new DirectLinkMaintenanceResponse($response->getBody());
    if (!$directLinkResponse->isSuccessful()) {
      throw new DeclineException($this->t('Transaction has been declined by the gateway (@error_code).', [
        '@error_code' => $directLinkResponse->getParam('NCERROR'),
      ]), $directLinkResponse->getParam('NCERROR'));
    }

    $payment->state = 'capture_completed';
    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
    $payment->setAmount($amount);
    $payment->setCapturedTime(REQUEST_TIME);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink/maintenance
   */
  public function voidPayment(PaymentInterface $payment) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException($this->t('Only payments in the "authorization" state can be voided.'));
    }

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());

    $operation = MaintenanceOperation::OPERATION_AUTHORISATION_DELETE_AND_CLOSE;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    $ogone_uri = $this->getMode() == 'live' ? DirectLinkMaintenanceRequest::PRODUCTION : DirectLinkMaintenanceRequest::TEST;
    $directLinkRequest->setOgoneUri($ogone_uri);

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink void request: @url <pre>@body</pre>', [
          '@url' => $directLinkRequest->getOgoneUri(),
          '@body' => var_export($body, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    $options = [
      'form_params' => $body,
    ];
    $response = $this->httpClient->post($directLinkRequest->getOgoneUri(), $options);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink void response: <pre>@body</pre>', [
          '@body' => (string) $response->getBody(),
        ]);
    }

    // Validate the API response.
    if ($response->getStatusCode() != 200) {
      throw new InvalidResponseException($this->t('The request returned with error code @http_code.', ['@http_code' => $response->getStatusCode()]));
    }
    elseif (!$response->getBody()) {
      throw new InvalidResponseException($this->t('The response did not have a body.'));
    }

    $directLinkResponse = new DirectLinkMaintenanceResponse($response->getBody());
    if (!$directLinkResponse->isSuccessful()) {
      throw new DeclineException($this->t('Transaction has been declined by the gateway (@error_code).', [
        '@error_code' => $directLinkResponse->getParam('NCERROR'),
      ]), $directLinkResponse->getParam('NCERROR'));
    }

    $payment->state = 'authorization_voided';
    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
    $payment->save();
  }

  /**
   * Renews the authorization for the given payment.
   *
   * Only authorizations for payments in the 'authorization' state can be
   * renewed.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to renew the authorization for.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the payment is not in the 'authorization' state.
   * @throws \Drupal\commerce_payment\Exception\InvalidResponseException
   *   Thrown when the invalid response is returned by the gateway.
   * @throws \Drupal\commerce_payment\Exception\DeclineException
   *   Thrown when the transaction is declined by the gateway.
   *
   * @see DirectLink::buildPaymentOperations()
   * @see DirectLink::getDefaultForms()
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink/maintenance
   */
  public function renewAuthorization(PaymentInterface $payment) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException($this->t('Only authorizations for payments in the "authorization" state can be renewed.'));
    }

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());

    $operation = MaintenanceOperation::OPERATION_AUTHORISATION_RENEW;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    $ogone_uri = $this->getMode() == 'live' ? DirectLinkMaintenanceRequest::PRODUCTION : DirectLinkMaintenanceRequest::TEST;
    $directLinkRequest->setOgoneUri($ogone_uri);

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink renew authorization request: @url <pre>@body</pre>', [
          '@url' => $directLinkRequest->getOgoneUri(),
          '@body' => var_export($body, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    $options = [
      'form_params' => $body,
    ];
    $response = $this->httpClient->post($directLinkRequest->getOgoneUri(), $options);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink renew authorization response: <pre>@body</pre>', [
          '@body' => (string) $response->getBody(),
        ]);
    }

    // Validate the API response.
    if ($response->getStatusCode() != 200) {
      throw new InvalidResponseException($this->t('The request returned with error code @http_code.', ['@http_code' => $response->getStatusCode()]));
    }
    elseif (!$response->getBody()) {
      throw new InvalidResponseException($this->t('The response did not have a body.'));
    }

    $directLinkResponse = new DirectLinkMaintenanceResponse($response->getBody());
    if (!$directLinkResponse->isSuccessful()) {
      throw new DeclineException($this->t('Transaction has been declined by the gateway (@error_code).', [
        '@error_code' => $directLinkResponse->getParam('NCERROR'),
      ]), $directLinkResponse->getParam('NCERROR'));
    }

    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink/maintenance
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException($this->t('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.'));
    }

    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // Validate the requested amount.
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException($this->t('Cannot refund more than @amount.', ['@amount' => (string) $balance]));
    }

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());
    // Ingenico requires the AMOUNT value to be sent in decimals.
    $directLinkRequest->setAmount((int) ($amount->getNumber() * 100));

    $operation = $balance->subtract($amount)->isZero() ? MaintenanceOperation::OPERATION_REFUND_LAST_OR_FULL : MaintenanceOperation::OPERATION_REFUND_PARTIAL;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    $ogone_uri = $this->getMode() == 'live' ? DirectLinkMaintenanceRequest::PRODUCTION : DirectLinkMaintenanceRequest::TEST;
    $directLinkRequest->setOgoneUri($ogone_uri);

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink refund request: @url <pre>@body</pre>', [
          '@url' => $directLinkRequest->getOgoneUri(),
          '@body' => var_export($body, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    $options = [
      'form_params' => $body,
    ];
    $response = $this->httpClient->post($directLinkRequest->getOgoneUri(), $options);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink refund response: <pre>@body</pre>', [
          '@body' => (string) $response->getBody(),
        ]);
    }

    // Validate the API response.
    if ($response->getStatusCode() != 200) {
      throw new InvalidResponseException($this->t('The request returned with error code @http_code.', ['@http_code' => $response->getStatusCode()]));
    }
    elseif (!$response->getBody()) {
      throw new InvalidResponseException($this->t('The response did not have a body.'));
    }

    $directLinkResponse = new DirectLinkMaintenanceResponse($response->getBody());
    if (!$directLinkResponse->isSuccessful()) {
      throw new DeclineException($this->t('Transaction has been declined by the gateway (@error_code).', [
        '@error_code' => $directLinkResponse->getParam('NCERROR'),
      ]), $directLinkResponse->getParam('NCERROR'));
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->state = 'capture_partially_refunded';
    }
    else {
      $payment->state = 'capture_refunded';
    }

    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

}
