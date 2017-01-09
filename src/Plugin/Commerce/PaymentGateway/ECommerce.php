<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use GuzzleHttp\Client;
use Ogone\Ecommerce\EcommercePaymentResponse;
use Ogone\HashAlgorithm;
use Ogone\Passphrase;
use Ogone\PaymentResponse;
use Ogone\ShaComposer\AllParametersShaComposer;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "ingenico_ecommerce",
 *   label = "Ingenico e-Commerce (off-site)",
 *   display_label = "Ingenico e-Commerce",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_ingenico\PluginForm\ECommerceOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class ECommerce extends OffsitePaymentGatewayBase implements EcommerceInterface {

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
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    // We need to define httpClient here for capture/void/refund operations,
    // as it is not passed to off-site plugins constructor.
    $this->httpClient = new Client();
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
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('e-Commerce payment response: <pre>@body</pre>', [
          '@body' => var_export($request->query->all(), TRUE),
        ]);
    }

    // Common response processing for both redirect back and async notification.
    $payment = $this->processFeedback($request);

    $payment_method = $payment->getPaymentMethod();
    $payment_method->card_type = strtolower($request->query->get('BRAND'));
    $payment_method->card_number = substr($request->query->get('CARDNO'), -4);
    $card_exp_month = substr($request->query->get('ED'), 0, 2);
    $payment_method->card_exp_month = $card_exp_month;
    $card_exp_year = \DateTime::createFromFormat('y', substr($request->query->get('ED'), -2))->format('Y');
    $payment_method->card_exp_year = $card_exp_year;

    // Payment method expiration timestamp.
    $expires = CreditCard::calculateExpirationTimestamp($card_exp_month, $card_exp_year);
    $payment_method->setExpiresTime($expires);

    $payment_method->save();

    // Do not update payment state here - it should be done from the received
    // notification only, and considering that usually notification is received
    // even before the user returns from the off-site redirect, at this point
    // the state tends to be already the correct one.
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    parent::onNotify($request);

    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('e-Commerce notification: <pre>@body</pre>', [
          '@body' => var_export($request->query->all(), TRUE),
        ]);
    }

    // Common response processing for both redirect back and async notification.
    $payment = $this->processFeedback($request);

    // Let's also update payment state here - it's safer doing it from received
    // asynchronous notification rather than from the redirect back from the
    // off-site redirect.
    $state = $request->query->get('STATUS') == PaymentResponse::STATUS_AUTHORISED ? 'authorization' : 'capture_completed';
    $payment->set('state', $state);
    $payment->setAuthorizedTime(REQUEST_TIME);
    if ($request->query->get('STATUS') != PaymentResponse::STATUS_AUTHORISED) {
      $payment->setCapturedTime(REQUEST_TIME);
    }
    $payment->save();
  }

  /**
   * Common response processing for both redirect back and async notification.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment entity, or NULL in case of an exception.
   *
   * @throws InvalidResponseException
   *   An exception thrown if response SHASign does not validate.
   * @throws DeclineException
   *   An exception thrown if payment has been declined.
   */
  private function processFeedback(Request $request) {
    $ecommercePaymentResponse = new EcommercePaymentResponse($request->query->all());

    // Load the payment entity created in
    // ECommerceOffsiteForm::buildConfigurationForm().
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($request->query->get('PAYMENT_ID'));

    $payment->setRemoteId($ecommercePaymentResponse->getParam('PAYID'));
    $payment->setRemoteState($ecommercePaymentResponse->getParam('STATUS'));
    $payment->save();

    // Validate response's SHASign.
    $passphrase = new Passphrase($this->configuration['sha_out']);
    $sha_algorithm = new HashAlgorithm($this->configuration['sha_algorithm']);
    $shaComposer = new AllParametersShaComposer($passphrase, $sha_algorithm);
    if (!$ecommercePaymentResponse->isValid($shaComposer)) {
      $payment->set('state', 'failed');
      $payment->save();
      throw new InvalidResponseException($this->t('The gateway response looks suspicious.'));
    }

    // Validate response's status.
    if (!$ecommercePaymentResponse->isSuccessful()) {
      $payment->set('state', 'failed');
      $payment->save();
      throw new DeclineException($this->t('Payment has been declined by the gateway (@error_code).', [
        '@error_code' => $ecommercePaymentResponse->getParam('NCERROR'),
      ]), $ecommercePaymentResponse->getParam('NCERROR'));
    }

    return $payment;
  }

}
