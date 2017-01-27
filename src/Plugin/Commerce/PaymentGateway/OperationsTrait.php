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
use Ogone\HashAlgorithm;
use Ogone\Passphrase;
use Ogone\ShaComposer\AllParametersShaComposer;
use Ogone\ParameterFilter\AliasShaInParameterFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait OperationsTrait {

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
   *
   * @see https://payment-services.ingenico.com/int/en/ogone/support/guides/integration%20guides/directlink/maintenance
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['authorization', 'partially_captured', 'capture_partially_refunded', 'capture_refunded'])) {
      throw new \InvalidArgumentException($this->t("Payments in @state state can't be captured.", ['@state' => $payment->getState()->value]));
    }

    // If not specified, capture the entire uncaptured amount.
    $uncaptured_amount = $payment->getUncapturedAmount();
    $amount = $amount ?: $uncaptured_amount;

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $sha_algorithm = new HashAlgorithm($this->configuration['sha_algorithm']);
    $shaComposer = new AllParametersShaComposer($passphrase, $sha_algorithm);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());
    // Ingenico requires the AMOUNT value to be sent in decimals.
    $directLinkRequest->setAmount((int) ($amount->getNumber() * 100));

    $operation = $uncaptured_amount->subtract($amount)->isZero() ? MaintenanceOperation::OPERATION_CAPTURE_LAST_OR_FULL : MaintenanceOperation::OPERATION_CAPTURE_PARTIAL;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink capture request: @url <pre>@body</pre>', [
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

    $payment->setCapturedAmount($payment->getCapturedAmount()->add($amount));
    $payment->setCapturedTime(REQUEST_TIME);
    $payment->state = $payment->getStateSuggestion();
    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
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
    $sha_algorithm = new HashAlgorithm($this->configuration['sha_algorithm']);
    $shaComposer = new AllParametersShaComposer($passphrase, $sha_algorithm);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());

    $operation = MaintenanceOperation::OPERATION_AUTHORISATION_DELETE_AND_CLOSE;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink void request: @url <pre>@body</pre>', [
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
    $sha_algorithm = new HashAlgorithm($this->configuration['sha_algorithm']);
    $shaComposer = new AllParametersShaComposer($passphrase, $sha_algorithm);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());

    $operation = MaintenanceOperation::OPERATION_AUTHORISATION_RENEW;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink renew authorization request: @url <pre>@body</pre>', [
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
    if (!in_array($payment->getState()->value, ['partially_captured', 'capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException($this->t("Payments in @state state can't be refunded.", ['@state' => $payment->getState()->value]));
    }

    // If not specified, refund the entire unrefunded amount.
    $unrefunded_amount = $payment->getUnrefundedAmount();
    $amount = $amount ?: $unrefunded_amount;

    $passphrase = new Passphrase($this->configuration['sha_in']);
    $sha_algorithm = new HashAlgorithm($this->configuration['sha_algorithm']);
    $shaComposer = new AllParametersShaComposer($passphrase, $sha_algorithm);

    $directLinkRequest = new DirectLinkMaintenanceRequest($shaComposer);

    $directLinkRequest->setPspid($this->configuration['pspid']);
    $directLinkRequest->setUserId($this->configuration['userid']);
    $directLinkRequest->setPassword($this->configuration['password']);
    $directLinkRequest->setPayId($payment->getRemoteId());
    // Ingenico requires the AMOUNT value to be sent in decimals.
    $directLinkRequest->setAmount((int) ($amount->getNumber() * 100));

    $operation = $payment->getAuthorizedAmount()->subtract($payment->getRefundedAmount())->subtract($amount)->isZero() ? MaintenanceOperation::OPERATION_REFUND_LAST_OR_FULL : MaintenanceOperation::OPERATION_REFUND_PARTIAL;
    $directLinkRequest->setOperation(new MaintenanceOperation($operation));

    $directLinkRequest->validate();

    // We cannot use magic set method to AbstractRequest::__call the SHASIGN
    // value (as it is not on the list of Ogone fields), so let's get all
    // already set parameters, and add SHASIGN to them here.
    $body = $directLinkRequest->toArray();
    $body['SHASIGN'] = $directLinkRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('DirectLink refund request: @url <pre>@body</pre>', [
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

    $payment->setRefundedAmount($payment->getRefundedAmount()->add($amount));
    $payment->state = $payment->getStateSuggestion();
    $payment->setRemoteState($directLinkResponse->getParam('STATUS'));
    $payment->save();
  }

}
