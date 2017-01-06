<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use GuzzleHttp\Client;
use Ogone\Ecommerce\EcommercePaymentResponse;
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
    list(, $payment_id,) = explode('-', $ecommercePaymentResponse->getParam('orderID'));
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($payment_id);

    $payment->setRemoteId($ecommercePaymentResponse->getParam('PAYID'));
    $payment->setRemoteState($ecommercePaymentResponse->getParam('STATUS'));
    $payment->save();

    // Validate response's SHASign.
    $passphrase = new Passphrase($this->configuration['sha_out']);
    $shaComposer = new AllParametersShaComposer($passphrase);
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
