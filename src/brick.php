<?php

defined('_JEXEC') or die('Restricted access');
if (!class_exists('Creditcard')) {
    require_once(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'creditcard.php');
}
if (!class_exists('Paymentwall_Config'))
    require(VMPATH_PLUGINS . DS . 'vmpayment' . DS . 'brick' . DS . 'paymentwall-php' . DS . 'lib' . DS . 'paymentwall.php');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

if (!class_exists('VirtueMartModelOrders'))
    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

define('DEFAULT_PINGBACK_RESPONSE', 'OK');
define('VM_ORDER_STATUS_CONFIRMED', 'C');
define('VM_ORDER_STATUS_REFUNDED', 'R');

class plgVmpaymentBrick extends vmPSPlugin
{
    private $paymentConfigs = array();
    private $_cc_expire_month = '';
    private $_cc_expire_year = '';
    private $_cc_name = '';
    private $_cc_type = '';
    private $_cc_number = '';
    private $_cc_cvv = '';
    private $_key = 'brick';

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = TRUE;
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = array(
            'brick_app_key' => array('', 'char'),
            'brick_secret_key' => array('', 'char'),
            'brick_public_key' => array('', 'char'),
            'brick_private_key' => array('', 'char')
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
            'tax_id' => 'smallint(1)',
            'transaction_id' => 'varchar(128)',
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
        $this->initPaymentwallConfigs($order['details']['BT']->virtuemart_paymentmethod_id);

        $cardInfo = array(
            'email' => $order['details']['BT']->email,
            'amount' => $order['details']['BT']->order_total,
            'currency' => $this->getCurrencyCodeById($order['details']['BT']->order_currency),
            'token' => $_POST['hiddenToken'],
            'fingerprint' => $_POST['hiddenFingerprint'],
            'description' => "Payment " . $order['details']['BT']->order_number
        );

        $charge = new Paymentwall_Charge();
        $charge->create(array_merge(
            $cardInfo,
            $this->getUserProfileData($order['details']['BT'])
        ));

        $response = $charge->getPublicData();
        $responseData = json_decode($charge->getRawResponseData(), true);

        $orderId = $order['details']['BT']->virtuemart_order_id;

        $modelOrder = new VirtueMartModelOrders();
        $order = $modelOrder->getOrder($orderId);

        if ($charge->isSuccessful()) {
            $transactionId = $charge->getId();
            $orderUpdate = array(
                'customer_notified' => 0,
                'virtuemart_order_id' => $orderId,
                'comments' => 'Brick payment successful. TransactionID: #' . $transactionId
            );
            if ($charge->isCaptured()) {
                $this->callDeliveryConfirmationApi($order, $transactionId);
                $orderUpdate['order_status'] = VM_ORDER_STATUS_CONFIRMED;
            } elseif ($charge->isUnderReview()) {
                // decide on risk charge
            }
            $modelOrder->updateStatusForOneOrder($orderId, $orderUpdate, true);

            $html = $this->renderByLayout('brick', array(
                'charge_id' => $responseData['id'],
                'amount' => $responseData['amount'],
                'currency' => $responseData['currency'],
                'card' => $responseData['card'],
                'order_number' => $order['details']['BT']->order_number,
                'status' => 'OK',
                'message' => vmText::_('VMPAYMENT_BRICK_SUCCESS') . ' TransactionID: #' . $transactionId,
            ));

            // Prepare data that should be stored in the database
            $transaction_data = $this->prepareTransactionData($order, $cart, $transactionId);
            $this->storePSPluginInternalData($transaction_data);
        } else {
            $errors = json_decode($response, true);
            $html = $this->renderByLayout('brick', array(
                'status' => 'Fail',
                'message' => '#'.$errors['error']['code'] .' - '. $errors['error']['message'],
            ));
        }

        vRequest::setVar('html', $html);
        $this->clearBrickSesison();
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
        $this->_cc_type = vRequest::getVar('cc_type_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_name = vRequest::getVar('cc_name_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_number = str_replace(" ", "", vRequest::getVar('cc_number_' . $cart->virtuemart_paymentmethod_id, ''));
        $this->_cc_cvv = vRequest::getVar('cc_cvv_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_expire_month = vRequest::getVar('cc_expire_month_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_expire_year = vRequest::getVar('cc_expire_year_' . $cart->virtuemart_paymentmethod_id, '');

        $this->_setBrickIntoSession();

        return $this->OnSelectCheck($cart);
    }

    function _setBrickIntoSession()
    {
        $session = JFactory::getSession();
        $sessionBrick = new stdClass();

        // card information
        $sessionBrick->cc_type = $this->_cc_type;
        $sessionBrick->cc_number = $this->encryptData($this->_cc_number);
        $sessionBrick->cc_cvv = $this->encryptData($this->_cc_cvv);
        $sessionBrick->cc_expire_month = $this->_cc_expire_month;
        $sessionBrick->cc_expire_year = $this->_cc_expire_year;
        $sessionBrick->cc_valid = $this->_cc_valid;

        $session->set('Brick', json_encode($sessionBrick), 'vm');
    }

    function _getBrickFromSession()
    {
        $session = JFactory::getSession();
        $brickSession = $session->get('Brick', 0, 'vm');

        if (!empty($brickSession)) {
            $brickData = (object)json_decode($brickSession, true);
            $this->_cc_type = $brickData->cc_type;
            $this->_cc_number = $this->decryptData($brickData->cc_number);
            $this->_cc_cvv = $this->decryptData($brickData->cc_cvv);
            $this->_cc_expire_month = $brickData->cc_expire_month;
            $this->_cc_expire_year = $brickData->cc_expire_year;
            $this->_cc_valid = $brickData->cc_valid;
        }
    }

    public function clearBrickSesison()
    {
        $session = JFactory::getSession();
        $session->set('Brick', null, 'vm');
    }

    public function encryptData($data)
    {
        $iv = mcrypt_create_iv(
            mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC),
            MCRYPT_DEV_URANDOM
        );

        return $encrypted = base64_encode(
            $iv .
            mcrypt_encrypt(
                MCRYPT_RIJNDAEL_128,
                hash('sha256', $this->_key, true),
                $data,
                MCRYPT_MODE_CBC,
                $iv
            )
        );
    }

    public function decryptData($encrypted)
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC));

        return $decrypted = rtrim(
            mcrypt_decrypt(
                MCRYPT_RIJNDAEL_128,
                hash('sha256', $this->_key, true),
                substr($data, mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC)),
                MCRYPT_MODE_CBC,
                $iv
            ),
            "\0"
        );
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
        if ($this->getPluginMethods($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                vmAdminInfo('displayListFE cartVendorId=' . $cart->vendorId);
                $app = JFactory::getApplication();
                $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                return FALSE;
            } else {
                return FALSE;
            }
        }

        $html = array();
        $method_name = $this->_psType . '_name';
        $virtuemart_paymentmethod_id = 0;
        foreach ($this->methods as $method) {
            if ($this->checkConditions($cart, $method, $cart->cartPrices)) {
                // the price must not be overwritten directly in the cart
                $prices = $cart->cartPrices;
                $methodSalesPrice = $this->setCartPrices($cart, $prices, $method);

                $method->$method_name = $this->renderPluginName($method);
                $html[] = $this->getPluginHtml($method, $selected, $methodSalesPrice);
                $virtuemart_paymentmethod_id = $method->virtuemart_paymentmethod_id;
            }
        }
        if (!empty($html)) {
            $html[] = $this->getCCForm($virtuemart_paymentmethod_id);
            $htmlIn[] = $html;
            return TRUE;
        }

        return FALSE;
    }

    public function getCCForm($payment_method_id)
    {
        $this->_getBrickFromSession();
        $creditCards = array(
            'Visa',
            'Mastercard',
            'AmericanExpress',
            'Discover',
            'JCB',
        );

        $creditCardList = '';
        if ($creditCards) {
            $creditCardList = ($this->_renderCreditCardList($creditCards, $this->_cc_type, $payment_method_id, FALSE));
        }

        $html = $this->renderByLayout('ccform', array(
            'public_key' => $this->getBrickConfig($payment_method_id, 'brick_public_key'),
            'payment_id' => $payment_method_id,
            'list_month' => shopfunctions::listMonths('cc_expire_month_' . $payment_method_id, $this->_cc_expire_month),
            'list_year' => shopfunctions::listYears('cc_expire_year_' . $payment_method_id, $this->_cc_expire_year, NULL, NULL, ""),
            'credit_card_list' => $creditCardList,
            'cc_number' => $this->_cc_number,
            'cc_cvv' => $this->_cc_cvv
        ));
        return $html;
    }

    /**
     * Creates a Drop Down list of available Creditcards
     *
     * @author Valerie Isaksen
     */
    function _renderCreditCardList($creditCards, $selected_cc_type, $paymentmethod_id, $multiple = FALSE, $attrs = '')
    {
        $idA = $id = 'cc_type_' . $paymentmethod_id;
        if (!is_array($creditCards)) {
            $creditCards = (array)$creditCards;
        }
        foreach ($creditCards as $creditCard) {
            $options[] = JHTML::_('select.option', $creditCard, $creditCard);
        }
        if ($multiple) {
            $attrs = 'multiple="multiple"';
            $idA .= '[]';
        }
        return JHTML::_('select.genericlist', $options, $idA, $attrs, 'value', 'text', $selected_cc_type);
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
            if ($isPingback) {
                Paymentwall_Config::getInstance()->set(array(
                    'private_key' => $params['brick_secret_key']
                ));
            } else {
                Paymentwall_Config::getInstance()->set(array(
                    'api_type' => Paymentwall_Config::API_GOODS,
                    'public_key' => $params['brick_public_key'],
                    'private_key' => $params['brick_private_key']
                ));
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
            'customer[lastname]' => $orderInfo->last_name,
            'email' => $orderInfo->email,
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
    public function prepareTransactionData($order, $cart, $transactionId)
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
            'transaction_id' => $transactionId
        );
    }

}
