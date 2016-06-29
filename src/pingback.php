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

/**
 * Process Pingback request
 */

$pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);
$modelOrder = new VirtueMartModelOrders();
$order = $modelOrder->getOrder($pingback->getProductId());

if (!$order) {
    die('Order invalid');
}

$brickPlugin = new plgVmpaymentBrick($dispatcher, array('type' => 'vmpayment', 'name' => 'brick'));
$brickPlugin->initPaymentwallConfigs($order['details']['BT']->virtuemart_paymentmethod_id, true);

if ($pingback->validate()) {

    $productId = $pingback->getProduct()->getId();
    $orderUpd = array(
        'customer_notified' => 0,
        'virtuemart_order_id' => $productId,
        'comments' => vmText::_('VMPAYMENT_BRICK_SUCCESS')
    );

    if ($pingback->isDeliverable()) {
        $brickPlugin->callDeliveryConfirmationApi($order, $pingback->getReferenceId());
        $orderUpd['order_status'] = VM_ORDER_STATUS_CONFIRMED;
    } else if ($pingback->isCancelable()) {
        $orderUpd['order_status'] = VM_ORDER_STATUS_REFUNDED;
    }

    $modelOrder->updateStatusForOneOrder($productId, $orderUpd, true);
    echo DEFAULT_PINGBACK_RESPONSE; // Paymentwall expects response to be OK, otherwise the pingback will be resent
} else {
    echo $pingback->getErrorSummary();
}
