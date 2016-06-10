<?php
error_reporting(0);

define('_JEXEC', 1);
define('DS', DIRECTORY_SEPARATOR);

$com_viruemart = 'com_virtuemart';
$path = dirname(__FILE__);
$path = explode(DS . 'plugins', $path);
$path = $path[0];

if (file_exists($path . '/defines.php')) {
    include_once $path . '/defines.php';
}

if (!defined('_JDEFINES')) {
    define('JPATH_BASE', $path);
    require_once JPATH_BASE . '/includes/defines.php';
}

define('JPATH_COMPONENT', JPATH_BASE . '/components/' . $com_viruemart);
define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/' . $com_viruemart);
define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/' . $com_viruemart);

define('DEFAULT_PINGBACK_RESPONSE', 'OK');
define('VM_ORDER_STATUS_CONFIRMED', 'C');
define('VM_ORDER_STATUS_REFUNDED', 'R');

require_once JPATH_BASE . '/includes/framework.php';

$app = JFactory::getApplication('site');
$app->initialise();

if (!class_exists('VmConfig')) {
    require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
}

VmConfig::loadConfig();

if (!class_exists('VirtueMartModelOrders')) {
    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
}

if (!class_exists('plgVmpaymentBrick')) {
    require(VMPATH_PLUGINS . DS . 'vmpayment' . DS . 'brick' . DS . 'brick.php');
}
$dispatcher = JDispatcher::getInstance();

/*
 * Processing Billing Brick
 */
try {
    $paymentId = $_GET['payment_id'];
    $orderId = $_GET['order_id'];

    if (empty($paymentId) || empty($orderId)) {
        die("Payment or Order invalid !");
    }

    $brickPlugin = new plgVmpaymentBrick($dispatcher, array('type' => 'vmpayment', 'name' => 'brick'));
    $brickPlugin->initPaymentwallConfigs($paymentId);

    $modelOrder = new VirtueMartModelOrders();
    $order = $modelOrder->getOrder($orderId);

    if (empty($order['details'])) {
        die("Order invalid !");
    }

    $orderDetail = $order['details']['BT'];

    $parameters = $_POST;
    $cardInfo = array(
        'email' => $parameters['email'],
        'amount' => $orderDetail->order_total,
        'currency' => $brickPlugin->getCurrencyCodeById($orderDetail->order_currency),
        'token' => $parameters['brick_token'],
        'fingerprint' => $parameters['brick_fingerprint'],
        'description' => 'Order #' . $orderDetail->order_name
    );

    $charge = new Paymentwall_Charge();
    $charge->create(
        array_merge($cardInfo, $brickPlugin->getUserProfileData($orderDetail))
    );
    $response = $charge->getPublicData();

    if ($charge->isSuccessful()) {
        $orderUpdate = array(
            'customer_notified' => 0,
            'virtuemart_order_id' => $orderId,
            'comments' => 'Brick payment successful'
        );
        if ($charge->isCaptured()) {
            $brickPlugin->callDeliveryConfirmationApi($order, $charge->getId());
            $orderUpdate['order_status'] = VM_ORDER_STATUS_CONFIRMED;
        } elseif ($charge->isUnderReview()) {
            
        }
        $modelOrder->updateStatusForOneOrder($orderId, $orderUpdate, true);
    } else {
    }
    echo $response;
} catch (Exception $e) {

}



