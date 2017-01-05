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
    $this->httpClient = new Client();
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // Log the response message if request logging is enabled.
    if (!empty($this->configuration['api_logging']['response'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('e-Commerce payment response: <pre>@body</pre>', [
          '@body' => var_export($request->query->all(), TRUE),
        ]);
    }

    $ecommercePaymentResponse = new EcommercePaymentResponse($request->query->all());

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'state' => 'new',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $ecommercePaymentResponse->getParam('PAYID'),
      'remote_state' => $ecommercePaymentResponse->getParam('STATUS'),
    ]);
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

    $state = $request->query->get('STATUS') == PaymentResponse::STATUS_AUTHORISED ? 'authorization' : 'capture_completed';
    $payment->set('state', $state);
    $payment->setAuthorizedTime(REQUEST_TIME);
    if ($request->query->get('STATUS') != PaymentResponse::STATUS_AUTHORISED) {
      $payment->setCapturedTime(REQUEST_TIME);
    }
    $payment->save();
  }

}
