<?php

namespace Drupal\commerce_ingenico\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;

/**
 * Provides the interface for the AuthorizeNet payment gateway.
 */
interface EcommerceInterface extends OffsitePaymentGatewayInterface, SupportsStoredPaymentMethodsInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

}
