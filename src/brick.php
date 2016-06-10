<?php

defined('_JEXEC') or die('Restricted access');

if (!class_exists('Paymentwall_Config'))
    require(VMPATH_PLUGINS . DS . 'vmpayment' . DS . 'brick' . DS . 'paymentwall-php' . DS . 'lib' . DS . 'paymentwall.php');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

if (!class_exists('VirtueMartModelOrders'))
    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

class plgVmpaymentBrick extends vmPSPlugin
{
    private $paymentConfigs = array();

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = TRUE;
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = array(
            'app_key' => array('', 'char'),
            'secret_key' => array('', 'char'),
            'public_key' => array('', 'char'),
            'private_key' => array('', 'char')
        );
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Brick Table');
    }

    function getTableSQLFields()
    {
        $sqlFields = array(
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(2000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_min_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)'
        );

        return $sqlFields;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        if (!defined('VM_VERSION') || VM_VERSION < 3) {
            // for older vm version
            return $this->onStoreInstallPaymentPluginTable($jplugin_id);
        } else {
            return $this->onStoreInstallPluginTable($jplugin_id);
        }
    }

    /**
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * Process after buyer set confirm purchase in check out< it loads a new page with widget
     */
    function plgVmConfirmedOrder($cart, $order)
    {

        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        VmConfig::loadJLang('com_virtuemart', true);

        // Prepare data that should be stored in the database
        $transaction_data = $this->prepareTransactionData($order, $cart);
        $this->storePSPluginInternalData($transaction_data);

        $uid = $order['details']['BT']->virtuemart_user_id != 0 ? $order['details']['BT']->customer_number : $_SERVER['REMOTE_ADDR'];
        $this->initPaymentwallConfigs($order['details']['BT']->virtuemart_paymentmethod_id);

        $paymentId = $order['details']['BT']->virtuemart_paymentmethod_id;
 

        $html = $this->renderByLayout('brick', array(
            'order_number' => $order['details']['BT']->order_number,
            'order_pass' => $order['details']['BT']->order_pass,
            'order_total' => $order['details']['BT']->order_total,
            'order_name' => $order['details']['BT']->order_name,
            'public_key' => $this->getBrickConfig($paymentId, 'public_key'),
            'currency_code' => $this->getCurrencyCodeById($order['details']['BT']->order_currency),
            'action_url' => rtrim(JUri::base(), '/') . $this->getOwnUrl() . '/billing.php?payment_id=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&order_id=' . $order['details']['BT']->virtuemart_order_id,
            'base_url' => JUri::base()
        ));

        vRequest::setVar('html', $html);
        $cart->emptyCart();
        return true;
    }

    private function getBrickConfig($payment_id, $key)
    {
        if ($params = $this->getPaymentConfigs($payment_id)) {
            return !empty($params[$key]) ? $params[$key] : null;
        }
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     */
    function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object|VirtueMartCart $cart Cart object
     * @param integer $selected ID of the method selected
     * @param $htmlIn
     * @return bool True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     */
    function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        //ToDo add image logo
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param VirtueMartCart $cart
     * @param int $method
     * @param array $cart_prices : cart prices
     * @return true : if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     */
    function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     *
     * @param VirtueMartCart $cart the current cart
     * @param array cart_prices the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */
    function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * Addition triggers for VM3
     * @param $data
     * @return bool
     */
    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @internal param int $_virtuemart_order_id The order ID
     */
    function plgVmOnShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function getCurrencyCodeById($currency_id)
    {
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName(array('currency_code_3')));
        $query->from($db->quoteName('#__virtuemart_currencies'));
        $query->where($db->quoteName('virtuemart_currency_id') . '=' . $db->quote($currency_id));
        $db->setQuery($query, 0, 1);

        $result = $db->loadRow();
        return $result ? $result[0] : false;
    }

    /**
     * @param $payment_id
     */
    public function initPaymentwallConfigs($payment_id, $isPingback = false)
    {
        if ($params = $this->getPaymentConfigs($payment_id)) {
            Paymentwall_Config::getInstance()->set(array(
                'api_type' => Paymentwall_Config::API_GOODS,
                'public_key' => $params['public_key'],
                'private_key' => $params['private_key']
            ));
            if ($isPingback) {
                Paymentwall_Base::setSecretKey($params['secret_key']);
            }
        }
    }

    /**
     * Get Payment configs
     * @param $payment_id
     * @return array|bool
     */
    public function getPaymentConfigs($payment_id = false)
    {
        if (!$this->paymentConfigs && $payment_id) {

            $db = JFactory::getDBO();
            $query = $db->getQuery(true);
            $query->select($db->quoteName(array('payment_params')));
            $query->from($db->quoteName('#__virtuemart_paymentmethods'));
            $query->where($db->quoteName('virtuemart_paymentmethod_id') . '=' . $db->quote($payment_id));
            $db->setQuery($query, 0, 1);
            $result = $db->loadRow();

            if (count($result[0]) > 0) {
                $payment_params = array();
                foreach (explode("|", $result[0]) as $payment_param) {
                    if (empty($payment_param)) {
                        continue;
                    }
                    $param = explode('=', $payment_param);
                    $payment_params[$param[0]] = substr($param[1], 1, -1);
                }
                $this->paymentConfigs = $payment_params;
            }
        }

        return $this->paymentConfigs;
    }

    private function getUserProfileData($orderInfo)
    {
        return array(
            'customer[city]' => $orderInfo->city,
            'customer[state]' => $orderInfo->virtuemart_state_id,
            'customer[address]' => $orderInfo->address_1,
            'customer[country]' => $orderInfo->virtuemart_country_id,
            'customer[zip]' => $orderInfo->zip,
            'customer[username]' => $orderInfo->virtuemart_user_id,
            'customer[firstname]' => $orderInfo->first_name,
            'customer[lastname]' => $orderInfo->last_name
        );
    }

    /**
     * @param $order
     * @return array
     */
    private function getDeliveryData($order, $isTest, $ref)
    {
        $shipping = false;
        if (isset($order['details']['ST'])) {
            $shipping = $order['details']['ST'];
        } elseif (isset($order['details']['BT'])) {
            $shipping = $order['details']['BT'];
        } else {
            return array();
        }

        return array(
            'payment_id' => $ref,
            'type' => 'digital',
            'status' => 'delivered',
            'estimated_delivery_datetime' => date('Y/m/d H:i:s'),
            'estimated_update_datetime' => date('Y/m/d H:i:s'),
            'refundable' => 'yes',
            'details' => 'Item will be delivered via email by ' . date('Y/m/d H:i:s'),
            'shipping_address[email]' => $order['details']['BT']->email,
            'shipping_address[firstname]' => $shipping->first_name,
            'shipping_address[lastname]' => $shipping->last_name,
            'shipping_address[country]' => $shipping->virtuemart_country_id,
            'shipping_address[street]' => $shipping->address_1,
            'shipping_address[state]' => $shipping->virtuemart_state_id,
            'shipping_address[phone]' => $shipping->phone_1,
            'shipping_address[zip]' => $shipping->zip,
            'shipping_address[city]' => $shipping->city,
            'reason' => 'none',
            'is_test' => $isTest,
        );
    }

    /**
     * @param $order
     * @param $ref
     */
    public function callDeliveryConfirmationApi($order, $ref)
    {
        // initPaymentwallConfigs loaded the configs before,
        // no need pass payment id
        $configs = $this->getPaymentConfigs();
        $shippingData = $this->getDeliveryData($order, $configs['test_mode'], $ref);

        if ($configs && $configs['delivery'] && $shippingData) {
            // Delivery Confirmation
            $delivery = new Paymentwall_GenerericApiObject('delivery');
            $response = $delivery->post($shippingData);
        }
    }

    /**
     * Extends the standard function in vmplugin. Extendst the input data by virtuemart_order_id
     * Calls the parent to execute the write operation
     *
     * @param $values
     * @param int $primaryKey
     * @param bool $preload
     * @return array
     * @internal param array $_values
     * @internal param string $_table
     */
    protected function storePSPluginInternalData($values, $primaryKey = 0, $preload = FALSE)
    {

        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        if (!isset($values['virtuemart_order_id'])) {
            $values['virtuemart_order_id'] = VirtueMartModelOrders::getOrderIdByOrderNumber($values['order_number']);
        }
        return $this->storePluginInternalData($values, $primaryKey, 0, $preload);
    }

    /**
     * @param $order
     * @param $cart
     * @return array
     */
    public function prepareTransactionData($order, $cart)
    {
        // Prepare data that should be stored in the database
        return array(
            'order_number' => $order['details']['BT']->order_number,
            'payment_name' => $this->_currentMethod->payment_name,
            'virtuemart_paymentmethod_id' => $cart->virtuemart_paymentmethod_id,
            'cost_per_transaction' => $this->_currentMethod->cost_per_transaction,
            'cost_percent_total' => $this->_currentMethod->cost_percent_total,
            'payment_currency' => $this->_currentMethod->payment_currency,
            'payment_order_total' => $order['details']['BT']->order_total,
            'tax_id' => $this->_currentMethod->tax_id,
        );
    }

}
