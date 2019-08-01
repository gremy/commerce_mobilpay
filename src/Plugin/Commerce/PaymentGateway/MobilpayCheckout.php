<?php

namespace Drupal\commerce_mobilpay\Plugin\Commerce\PaymentGateway;

use Mobilpay\Payment\Invoice;
use Mobilpay\Payment\Request\RequestAbstract;
use Mobilpay\Payment\Request\Card;
use Mobilpay\Payment\Address;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\file\FileUsage\FileUsageInterface;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Calculator;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Component\Datetime\TimeInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Exception;


/**
 * Provides the Mobilpay Checkout payment gateway plugin.
 *
 * @CommercePaymentGateway(
 *   id = "mobilpay_checkout",
 *   label = @Translation("Mobilpay Checkout"),
 *   display_label = @Translation("Mobilpay"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_mobilpay\PluginForm\MobilpayCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa", "maestro",
 *   },
 * )
 */
class MobilpayCheckout extends OffsitePaymentGatewayBase implements MobilpayCheckoutInterface {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */

  protected $state;


  /**
   * The file storage service.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $fileStorage;

  /**
   * The file storage service.
   *
   * @var \Drupal\Core\File\FileSystem;
   */
  protected $fileSystem;

  /**
   * The file storage service.
   *
   * @var \Drupal\file\FileUsageInterface;
   */
  protected $fileUsage;

  /**
   * @var \Drupal\Core\Render\RendererInterface
   */
  private $renderer;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new PaymentGatewayBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $pluginId
   *   The plugin_id for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $paymentTypeManager
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $paymentMethodTypeManager
   * @param \Drupal\Component\Datetime\TimeInterface $time
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Core\Entity\EntityStorageInterface $fileStorage
   * @param \Drupal\file\FileUsage\FileUsageInterface $fileUsage
   * @param \Drupal\Core\File\FileSystem $fileSystem
   * @param \Drupal\Core\Render\RendererInterface $renderer
   */
  public function __construct(array $configuration,
                              $pluginId,
                              $pluginDefinition,
                              EntityTypeManagerInterface $entityTypeManager,
                              PaymentTypeManager $paymentTypeManager,
                              PaymentMethodTypeManager $paymentMethodTypeManager,
                              TimeInterface $time,
                              StateInterface $state,
                              EntityStorageInterface $fileStorage,
                              FileUsageInterface $fileUsage,
                              FileSystem $fileSystem,
                              RendererInterface $renderer,
                              AccountInterface $currentUser
                              ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition, $entityTypeManager, $paymentTypeManager, $paymentMethodTypeManager, $time);
    $this->state = $state;
    $this->fileStorage = $fileStorage;
    $this->fileUsage = $fileUsage;
    $this->fileSystem = $fileSystem;
    $this->renderer = $renderer;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition) {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('state'),
      $container->get('entity.manager')->getStorage('file'),
      $container->get('file.usage'),
      $container->get('file_system'),
      $container->get('renderer'),
      $container->get('current_user')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'redirect_method' => 'post',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // merchant account signature - generated by mobilpay.ro for every merchant
    // account
    $form['merchant_signature'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Signature'),
      '#description' => t('The merchant signature from the mobilpay.ro provider.'),
      '#default_value' => $this->state->get('mobilpay.merchant_signature'),
      '#required' => TRUE,
    ];

    $form['test_credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test API Credentials'),
      '#tree' => true,
      '#states' => [
        'visible' => [
          [':input[name="configuration[' . $this->pluginId . '][mode]"]' => ['value' => 'test']],
        ],
      ],
    ];

    $form['test_credentials']['test_' . $this->pluginId . '_private_key'] = [
      '#name' => 'test_' . $this->pluginId . '_private_key',
      '#type' => 'managed_file',
      '#title' => t('Upload private key'),
      '#default_value' => [$this->state->get('test_' . $this->pluginId . '_private_key')],
      '#description' => t('Upload private key that you received from Mobilpay.(key)'),
      '#upload_validators' => [
        'file_validate_extensions' => ['key'],
      ],
      '#upload_location' => 'private://',
    ];

    $form['test_credentials']['test_' . $this->pluginId . '_public_key'] = [
      '#name' => 'test_' . $this->pluginId . '_public_key',
      '#type' => 'managed_file',
      '#title' => t('Upload public key'),
      '#default_value' => [$this->state->get('test_' . $this->pluginId . '_public_key')],
      '#description' => t('Upload public key that you received from Mobilpay.(cer)'),
      '#upload_validators' => [
        'file_validate_extensions' => ['cer'],
      ],
      '#upload_location' => 'private://',
    ];

    $form['live_credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Live API Credentials'),
      '#tree' => true,
      '#states' => [
        'visible' => [
          [':input[name="configuration[' . $this->pluginId . '][mode]"]' => ['value' => 'live']],
        ],
      ],
    ];

    $form['live_credentials']['live_' . $this->pluginId . '_private_key'] = [
      '#name' => 'live_' . $this->pluginId . '_private_key',
      '#type' => 'managed_file',
      '#title' => t('Upload private key'),
      '#default_value' => [$this->state->get('live_' . $this->pluginId . '_private_key')],
      '#description' => t('Upload private key that you received from Mobilpay.(key)'),
      '#upload_validators' => [
        'file_validate_extensions' => ['key'],
      ],
      '#upload_location' => 'private://',
    ];

    $form['live_credentials']['live_' . $this->pluginId . '_public_key'] = [
      '#name' => 'live_' . $this->pluginId . '_public_key',
      '#type' => 'managed_file',
      '#title' => t('Upload public key'),
      '#default_value' => [$this->state->get('live_' . $this->pluginId . '_public_key')],
      '#description' => t('Upload public key that you received from Mobilpay.(cer)'),
      '#upload_validators' => [
        'file_validate_extensions' => ['cer'],
      ],
      '#upload_location' => 'private://',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $keyValues = $form_state->getValue($form['#parents']);
      $this->state->set('mobilpay.merchant_signature', $keyValues['merchant_signature']);

      foreach ($keyValues as $key => $value) {
        if (strpos($key, 'credentials') !== FALSE) {
          foreach ($value as $subkey => $subvalue) {
            if (strpos($subkey, $this->pluginId) !== FALSE) {
              $newFidKey = reset($subvalue);
              if ($newFidKey) {
                $this->createFile($newFidKey, $subkey);
                $this->state->set($subkey, $newFidKey);
              }
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    $envKey = $request->request->get('env_key');
    $data = $request->request->get('data');

    if (empty($envKey) || empty($data)) {
      throw new PaymentGatewayException('No value found in query string on Mobilpay payment return.');
    }

    if (isset($envKey) && isset($data)) {
      // Private key path.

      $privateKeyFilePath = $this->getPrivateKeyFile();

      try {
        $objPmReq = RequestAbstract::factoryFromEncrypted($envKey, $data, $privateKeyFilePath);

        $errorType		= RequestAbstract::CONFIRM_ERROR_TYPE_NONE;
        $errorCode = $objPmReq->objPmNotify->errorCode;

        switch ($objPmReq->objPmNotify->action) {
          case 'confirmed':
            $order->setData('state', 'confirmed');
            $this->createPaymentStorage($order, $objPmReq->objPmNotify);
            $errorMessage = $objPmReq->objPmNotify->errorMessage;
            break;

          case 'confirmed_pending':
          case 'paid_pending':
            $order->setData('state', 'pending');
            $this->createPaymentStorage($order, $objPmReq->objPmNotify);
          $errorMessage = $objPmReq->objPmNotify->errorMessage;
            break;

          case 'paid':
            $order->setData('state', 'open/preauthorized');
            $this->createPaymentStorage($order, $objPmReq->objPmNotify);
            $errorMessage = $objPmReq->objPmNotify->errorMessage;
            break;

          case 'canceled':
            $order->setData('state', 'canceled');
            $errorMessage = $objPmReq->objPmNotify->errorMessage;
            break;

          default:
            $errorType = RequestAbstract::CONFIRM_ERROR_TYPE_PERMANENT;
            $errorCode = RequestAbstract::ERROR_CONFIRM_INVALID_ACTION;
            $errorMessage = 'mobilpay_refference_action paramaters is invalid';
            break;
        }
      }
      catch (Exception $e) {
        $errorType = RequestAbstract::CONFIRM_ERROR_TYPE_TEMPORARY;
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();
        throw new BadRequestHttpException(sprintf($this->t('Error occurred: @message'),
          ['@message' => $errorMessage]));
      }
    }
    else {
      $errorType = RequestAbstract::CONFIRM_ERROR_TYPE_PERMANENT;
      $errorCode = RequestAbstract::ERROR_CONFIRM_INVALID_POST_PARAMETERS;
      $errorMessage = 'mobilpay.ro posted invalid parameters';
    }
    $order->save();

    $renderable = [
      '#theme' => 'commerce_mobilpay',
      '#errorType' => $errorType,
      '#errorCode' => $errorCode,
      '#errorMessage' => $errorMessage
    ];

    echo ($this->renderer->render($renderable));
}

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    parent::onNotify($request);

    $envKey = $request->request->get('env_key');
    $data = $request->request->get('data');
    try {
      $privateKeyPath = $this->getPrivateKeyFile();

      $objPmReq = RequestAbstract::factoryFromEncrypted($envKey, $data, $privateKeyPath);
      $order = Order::load($objPmReq->orderId);
      $this->createPaymentStorage($order, $objPmReq->objPmNotify);
      $url = Url::fromUri('internal:/checkout/' . $objPmReq->orderId . '/complete');
    }
    catch (Exception $e) {
      $errorMessage = $e->getMessage();
      throw new BadRequestHttpException(sprintf($this->t('Error occurred: @message'),
        ['@message' => $errorMessage]));
    }
    $url->toString();
    die;
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
  public function setMobilpayCheckoutData(PaymentInterface $payment) {

    $order = $payment->getOrder();
    $amount = $payment->getAmount();

    if (!$order) {
      throw new BadRequestHttpException('Invalid order.');
    }

    if (!$order->getBillingProfile()) {
      return;
    }
    $address = $order->getBillingProfile()->get('address')->first();
    $addressArray = [
      $address->getAddressLine1(),
      $address->getAddressLine2(),
      $address->getLocality(),
      $address->getAdministrativeArea(),
      $address->getPostalCode(),
      $address->getCountryCode(),
    ];

    try {
      srand((double) microtime() * 1000000);

      $objPmReqCard = new Card();
      // Merchant account signature - generated by mobilpay.ro
      $objPmReqCard->signature = $this->state->get('mobilpay.merchant_signature');
      $objPmReqCard->orderId = $order->id();

      // Below is where mobilPay will send the payment result. This URL will
      // always be called first; mandatory.
      // Borrowed from PaymentProcess::buildReturnUrl().
      $objPmReqCard->confirmUrl = $this->getNotifyUrl()->toString();

      // Below is where mobilPay redirects the client once the payment
      // process is finished. Not to be mistaken for
      // a "successURL" nor "cancelURL"; mandatory.
      $objPmReqCard->returnUrl = $this->buildReturnUrl($order->id())->toString();

      // payment details: currency, amount, description.
      $objPmReqCard->invoice = new Invoice();
      $objPmReqCard->invoice->currency = $amount->getCurrencyCode();
      $objPmReqCard->invoice->amount = Calculator::round($amount->getNumber(), 2);

      $orderDesc = 'Order #' . $order->id() . ': ';

      foreach ($order->getItems() as $item) {
        $productSku = $item->getPurchasedEntity()->getSku();
        $orderDesc .= $item->getTitle() . ' [' . $productSku . ']';
        $orderDesc .= ', ';
      }

      // Remove the last comma.
      $orderDesc = rtrim($orderDesc, ', ');
      $objPmReqCard->invoice->details = $orderDesc;

      $billingAddress = new Address();
      $billingAddress->type = 'person';
      $billingAddress->firstName = $address->getName();
      $billingAddress->lastName = $address->getName();
      $billingAddress->address = implode(', ', array_filter($addressArray));
      $billingAddress->email = $order->getCustomer()->getEmail();

      $objPmReqCard->invoice->setBillingAddress($billingAddress);
      $objPmReqCard->invoice->setShippingAddress($billingAddress);

      // This is the path on your server to the public certificate.
      //eg.   /srv/web/sites/default/files/mykey.crt
      $x509FilePath = $this->getPublicKeyFile();
      $objPmReqCard->encrypt($x509FilePath);

      $formInfo = [
        'env_key' => $objPmReqCard->getEnvKey(),
        'data' => $objPmReqCard->getEncData(),
      ];
    } catch (Exception $e) {
      throw new BadRequestHttpException('Order could not be payed: ' . $e->getMessage());
    }

    return $formInfo;
  }

  /**
   * Create a PaymentStorage object.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *  The commerce_order object.
   * @param array $paymentData
   *  The PaymentObject object.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The PaymentStorage object.
   */
  public function createPaymentStorage(OrderInterface $order, $paymentData) {
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $requestTime = $this->time->getRequestTime();

    $payment = $paymentStorage->create([
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'authorized' => $requestTime,
      'remote_state' => $paymentData->action,
      'remote_id' => $paymentData->purchaseId,
      'state' => $order->getState()
    ]);
    $payment->save();
    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    if ($this->getMode() == 'test') {
      return 'http://sandboxsecure.mobilpay.ro';
    }
    else {
      return 'https://secure.mobilpay.ro';
    }
  }

  /**
   * Create file entity.
   *
   * @param int $keyFid
   *  The files fid.
   * @param string $keyType
   *  The file usage type.
   */
  public function createFile($keyFid, $keyType) {
    $file = $this->fileStorage->load($keyFid);
    $this->fileUsage->add($file, 'commerce_mobilpay', $keyType, $this->currentUser->id());
    $file->setPermanent();
    $file->save();
  }

  /**
   * {@inheritdoc}
   */
  public function buildReturnUrl($order_id) {
    return Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order_id,
      'step' => 'payment',
    ], ['absolute' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildCancelUrl($order_id) {
    return Url::fromRoute('commerce_payment.checkout.cancel', [
      'commerce_order' => $order_id,
      'step' => 'payment',
    ], ['absolute' => TRUE]);
  }

  /**
   * @return string
   *   The private key path.
   */
  public function getPrivateKeyFile() {
    // Private key path.
    $fid = $this->state->get($this->getMode() . '_' . $this->pluginId . '_private_key');
    $file = $this->fileStorage->load($fid);
    return $this->fileSystem->realpath($file->getFileUri());
  }

  /**
   * @return string
   *   The pubic key path.
   */
  public function getPublicKeyFile() {
    // Public key path.
    $fid = $this->state->get($this->getMode() . '_' . $this->pluginId . '_public_key');
    $file = $this->fileStorage->load($fid);
    return $this->fileSystem->realpath($file->getFileUri());
  }
}
