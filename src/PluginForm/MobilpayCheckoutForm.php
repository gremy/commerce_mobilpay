<?php

namespace Drupal\commerce_mobilpay\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class MobilpayCheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_mobilpay\Plugin\Commerce\PaymentGateway $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $redirect_url = $payment_gateway_plugin->getUrl();
    // Get plugin configuration.
    $plugin_config = $payment_gateway_plugin->getConfiguration();

    $mobilpay_data = $payment_gateway_plugin->setMobilpayCheckoutData($payment);

    foreach ($mobilpay_data as $name => $value) {
      if (!empty($value)) {
        $data[$name] = $value;
      }
    }

    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, $plugin_config['redirect_method']);
  }
}
