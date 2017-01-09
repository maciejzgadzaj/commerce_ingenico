<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
 *   id = "ingenico_directlink",
 *   label = "Ingenico DirectLink (on-site)",
 *   display_label = "Ingenico DirectLink",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa"
 *   }
 * )
 */
class DirectLink extends OnsitePaymentGatewayBase implements DirectLinkInterface {

  // Both payment method configuration form as well as payment operations
  // (capture/void/refund) are common to Ingenico DirectLink and e-Commerce.
  use ConfigurationTrait;
  use OperationsTrait;

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
   * @return \Ogone\DirectLink\Alias
   *   A credit card alias returned by the payment gateway.
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/alias-gateway
   */
  protected function doCreatePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $passphrase = new Passphrase($this->configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);
    $shaComposer->addParameterFilter(new AliasShaInParameterFilter());

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
          '@url' => $this->getOgoneUri($createAliasRequest),
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
    $response = $this->httpClient->post($this->getOgoneUri($createAliasRequest), $options);

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

    // Save payment transaction to get its ID.
    $payment->save();

    $directLinkRequest->setOrderid($payment->getOrder()->getOrderNumber() . '-' . $payment->getOrder()->getCreatedTime());
    $directLinkRequest->setCom((string) $this->t('Order @order_number', ['@order_number' => $payment->getOrder()->getOrderNumber()]));
    // We don't need to send PARAMPLUS for DirectLink itself, but it will be
    // used with 3D Secure transactions, as they are finished over e-Commerce.
    $directLinkRequest->setParamplus([
      'ORDER_ID' => $payment->getOrder()->getOrderNumber(),
      'PAYMENT_ID' => $payment->get('payment_id')->first()->value,
    ]);
    // Ingenico requires the AMOUNT value to be sent in decimals.
    $directLinkRequest->setAmount((int) $payment->getAmount()->getNumber() * 100);
    $directLinkRequest->setCurrency($payment->getAmount()->getCurrencyCode());
    $directLinkRequest->setLanguage('en_US');

    // Use credit card alias created in DirectLink::doCreatePaymentMethod().
    $alias = new Alias($payment_method->getRemoteId());
    $directLinkRequest->setAlias($alias);

    $directLinkRequest->setEmail($payment_method->getOwner()->getEmail());
    $billing_address = $payment_method->getBillingProfile()->get('address')->first();
    $directLinkRequest->setCn($billing_address->getGivenName() . ' ' . $billing_address->getFamilyName());
    $directLinkRequest->setOwnerAddress($billing_address->getAddressLine1());
    $directLinkRequest->setOwnerZip($billing_address->getPostalCode());
    $directLinkRequest->setOwnerTown($billing_address->getLocality());
    $directLinkRequest->setOwnerCty($billing_address->getCountryCode());

    $directLinkRequest->setRemote_addr($_SERVER['REMOTE_ADDR']);
    $directLinkRequest->setEci(new Eci(Eci::ECOMMERCE_WITH_SSL));

    if (!empty($this->configuration['3ds']['3d_secure'])) {
      $directLinkRequest->setFlag3d('Y');
      $directLinkRequest->setHttp_accept($_SERVER['HTTP_ACCEPT']);
      $directLinkRequest->setHttp_user_agent($_SERVER['HTTP_USER_AGENT']);
      $directLinkRequest->setWin3ds('MAINW');

      // From PaymentProcess::buildReturnUrl(), which we don't have access to
      // from here unfortunately.
      $return_url = Url::fromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $payment->getOrder()->id(),
        'step' => 'payment',
      ], ['absolute' => TRUE])->toString();
      // From PaymentProcess::buildCancelUrl().
      $cancel_url = Url::fromRoute('commerce_payment.checkout.cancel', [
        'commerce_order' => $payment->getOrder()->id(),
        'step' => 'payment',
      ], ['absolute' => TRUE])->toString();
      $directLinkRequest->setAccepturl($return_url);
      $directLinkRequest->setDeclineurl($return_url);
      $directLinkRequest->setExceptionurl($return_url);
      $directLinkRequest->setCancelurl($cancel_url);

      $directLinkRequest->setComplus('SUCCESS');
      $directLinkRequest->setLanguage('en_US');
    }

    $directLinkRequest->validate();

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink order request: @url <pre>@body</pre>', [
          '@url' => $this->getOgoneUri($directLinkRequest),
          '@body' => var_export($body, TRUE),
        ]);
    }

    // Perform the request to Ingenico API.
    $options = [
      'form_params' => $body,
    ];
    $response = $this->httpClient->post($this->getOgoneUri($directLinkRequest), $options);

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

    // If we received 3D Secure response HTML, display it on the page.
    libxml_use_internal_errors(TRUE);
    if (($xml = simplexml_load_string($response->getBody())) && isset($xml->HTML_ANSWER)) {
      // The remaining part of communication with Ingenico for this order will
      // be done using e-Commerce instead of DirectLink (including using
      // e-Commerce's return and notification URLs), so we need to update
      // the order's payment gateway to have access to these URLs allowed
      // (see OffsitePaymentController::returnCheckoutPage() etc).
      $payment->getOrder()->set('payment_gateway', $this->configuration['3ds']['3d_secure_ecommerce_gateway'])->save();
      print base64_decode((string) $xml->HTML_ANSWER);
      exit;
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

}
