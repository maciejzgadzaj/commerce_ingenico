<?php

namespace Drupal\commerce_ingenico\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Ogone\DirectLink\PaymentOperation;
use Ogone\Ecommerce\EcommercePaymentRequest;
use Ogone\Passphrase;
use Ogone\ShaComposer\AllParametersShaComposer;

class ECommerceOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    // The test property is not yet added at this point.
    $payment->setTest($payment->getPaymentGateway()->getPlugin()->getMode() == 'test');
    // Save the payment entity so that we can get its ID and use it for
    // building the 'ORDERID' property for Ingenico. Then, when user returns
    // from the off-site redirect, we will update the same payment.
    $payment->save();

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $payment_gateway_configuration = $payment_gateway_plugin->getConfiguration();

    $passphrase = new Passphrase($payment_gateway_configuration['sha_in']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $ecommercePaymentRequest = new EcommercePaymentRequest($shaComposer);
    $ecommercePaymentRequest->setPspid($payment_gateway_configuration['pspid']);

    $ecommercePaymentRequest->setOrderid($payment->getOrder()->getOrderNumber() . '-' . $payment->getOrder()->getCreatedTime());
    $ecommercePaymentRequest->setCom((string) t('Order @order_number', ['@order_number' => $payment->getOrder()->getOrderNumber()]));
    $ecommercePaymentRequest->setParamplus([
      'ORDER_ID' => $payment->getOrder()->getOrderNumber(),
      'PAYMENT_ID' => $payment->get('payment_id')->first()->value,
    ]);
    // Ingenico requires the AMOUNT value to be sent in decimals.
    $ecommercePaymentRequest->setAmount((int) $payment->getAmount()->getNumber() * 100);
    $ecommercePaymentRequest->setCurrency($payment->getAmount()->getCurrencyCode());
    $ecommercePaymentRequest->setLanguage($payment_gateway_configuration['language']);

    // At the beginning PaymentProcess::buildPaneForm() did not pass the
    // selected transaction mode to the offsite payment form, but we still
    // want the default mode to be capture.
    $operation = isset($form['#capture']) && $form['#capture'] === FALSE ? PaymentOperation::REQUEST_FOR_AUTHORISATION : PaymentOperation::REQUEST_FOR_DIRECT_SALE;
    $ecommercePaymentRequest->setOperation(new PaymentOperation($operation));

    $ecommercePaymentRequest->setAccepturl($form['#return_url']);
    $ecommercePaymentRequest->setDeclineurl($form['#return_url']);
    $ecommercePaymentRequest->setExceptionurl($form['#return_url']);
    $ecommercePaymentRequest->setCancelurl($form['#cancel_url']);

    $ecommercePaymentRequest->setEmail($payment->getOrder()->getEmail());
    $billing_address = $payment->getOrder()->getBillingProfile()->get('address')->first();
    $ecommercePaymentRequest->setCn($billing_address->getGivenName() . ' ' . $billing_address->getFamilyName());
    $ecommercePaymentRequest->setOwnerAddress($billing_address->getAddressLine1());
    $ecommercePaymentRequest->setOwnerZip($billing_address->getPostalCode());
    $ecommercePaymentRequest->setOwnerTown($billing_address->getLocality());
    $ecommercePaymentRequest->setOwnerCty($billing_address->getCountryCode());

    $ecommercePaymentRequest->validate();

    $redirect_url = $payment_gateway_plugin->getOgoneUri($ecommercePaymentRequest);

    $data = $ecommercePaymentRequest->toArray();
    $data['SHASIGN'] = $ecommercePaymentRequest->getShaSign();

    // Log the request message if request logging is enabled.
    if (!empty($payment_gateway_configuration['api_logging']['request'])) {
      \Drupal::logger('commerce_ingenico')
        ->debug('e-Commerce payment request: @url <pre>@body</pre>', [
          '@url' => $redirect_url,
          '@body' => var_export($data, TRUE),
        ]);
    }

    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, PaymentOffsiteForm::REDIRECT_POST);
  }

}
