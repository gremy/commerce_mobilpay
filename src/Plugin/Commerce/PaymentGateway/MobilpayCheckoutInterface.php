<?php

namespace Drupal\commerce_mobilpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Provides the interface for the Mobilpay Checkout payment gateway.
 */
interface MobilpayCheckoutInterface {

  /**
   * Gets the API URL.
   *
   * @return string
   *   The API URL.
   */
  public function getUrl();

  /**
   * SetMobilpayCheckout request.
   *
   * Builds the data for the request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return array
   *   Mobilpay data.
   *
   */
  public function setMobilpayCheckoutData(PaymentInterface $payment);

  /**
   * Builds the URL to the "return" page.
   *
   * @param int $order_id
   *   The order ID.
   *
   * @return \Drupal\Core\Url
   *   The "return" page URL.
   */
  public function buildReturnUrl($order_id);

  /**
   * Builds the URL to the "cancel" page.
   *
   * @param int $order_id
   *   The order ID.
   *
   * @return \Drupal\Core\Url
   *   The "cancel" page URL.
   */
  public function buildCancelUrl($order_id);

}
