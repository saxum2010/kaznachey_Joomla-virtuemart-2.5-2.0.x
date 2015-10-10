<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentKaznachey extends vmPSPlugin
{
    // instance of class
    public static $_this = false;
	public	$urlGetMerchantInfo = 'http://payment.kaznachey.net/api/PaymentInterface/CreatePayment';
	public	$urlGetClientMerchantInfo = 'http://payment.kaznachey.net/api/PaymentInterface/GetMerchatInformation';
    
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        
        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush        = array(
            'payment_logos' => array(
                '',
                'char'
            ),
            'countries' => array(
                0,
                'int'
            ),
            'payment_currency' => array(
                0,
                'int'
            ),
            'merchant_id' => array(
                '',
                'string'
            ),
            'secret_key' => array(
                '',
                'string'
            ),            
            'status_success' => array(
                '',
                'char'
            ),
            'status_pending' => array(
                '',
                'char'
            ),
            'status_canceled' => array(
                '',
                'char'
            )
        );
        
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
    
    
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment kaznachey Table');
    }
    
    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,2) NOT NULL DEFAULT \'0.00\' ',
            'payment_currency' => 'char(3) '
        );
        
        return $SQLfields;
    }
    
    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        $vendorId = 0;
        
        $session        = JFactory::getSession();
        $return_context = $session->getId();
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
        
        $html = "";
        
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        if (!$method->payment_currency)
            $this->getPaymentCurrency($method);
        // END printing out HTML Form code (Payment Extra Info)
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db =& JFactory::getDBO();
        $db->setQuery($q);
        
        $currency = strtoupper($db->loadResult());

        $amount = ceil($order['details']['BT']->order_total*100)/100;
        $order_id    = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number);
        
        $desc = 'Оплата заказа №'.$order['details']['BT']->order_number;
        $success_url = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number . '&order_pass=' . $order['details']['BT']->order_pass);
        $fail_url = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
        $result_url = JROUTE::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pelement=kaznachey&order_number=' . $order_id);

        $this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['payment_name']                = $this->renderPluginName($method);
        $dbValues['order_number']                = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
        $dbValues['payment_currency']            = $currency;
        $dbValues['payment_order_total']         = $amount;
        $this->storePSPluginInternalData($dbValues);
        
		$merchantGuid = $method->merchant_id;
		$merchnatSecretKey  = $method->secret_key;
		$user_email = $order['details']['BT']->email;
		$phone_1 = $order['details']['BT']->phone_1;
		$virtuemart_user_id = $order['details']['BT']->virtuemart_user_id;
		
		$selectedPaySystemId = JRequest::getVar('cc_type', '1');
		
		$i = 0;
		$amount2 = 0;
		$product_count = 0;

		foreach ($order['items'] as $key=>$pr_item)
		{
			$products[$i]['ProductItemsNum'] = number_format($pr_item->product_quantity, 2, '.', '');
			$products[$i]['ProductName'] = $pr_item->order_item_name;
			$products[$i]['ProductPrice'] = number_format($pr_item->product_final_price, 2, '.', '');
			$products[$i]['ProductId'] = $pr_item->order_item_sku;
			$amount2 += $pr_item->product_final_price * $pr_item->product_quantity;
			$product_count += $pr_item->product_quantity;
			$i++;
		}
		
		$amount2  = number_format($amount2, 2, '.', '');
		if($amount != $amount2)
		{
			$tt = $amount - $amount2; 
			$products[$i]['ProductItemsNum'] = '1.00';
			$products[$i]['ProductName'] = 'Доставка или скидка';
			$products[$i]['ProductPrice'] = number_format($tt, 2, '.', '');
			$products[$i]['ProductId'] = '00001'; 
			$product_count += '1.00';
			$amount2  = number_format($amount2 + $tt, 2, '.', '');
		}
		
/* 	$gmi = $this->GetMerchnatInfo($selectedPaySystemId);
	if($gmi['Fields']){
		foreach ($gmi['Fields'] as $key=>$field)
		{
			$userEnteredFields[$key]['FieldTag'] = $field['FieldTag'];
			
			switch ($field['FieldTag'])
			{
				case 'E-Mail':
					$userEnteredFields[$key]['FieldValue'] = $user_email;
					break;
				
				case 'PhoneNumber':
					$userEnteredFields[$key]['FieldValue'] = $phone_1;
					break;
			}
		}
	}else{
		$userEnteredFields = Array(
			Array(
				"FieldTag"=>"E-Mail",
				"FieldValue"=>$user_email
			)
		);
	} */
		$product_count = number_format($product_count, 2, '.', '');

		$signature_u = md5(md5(
			$merchantGuid.
			$merchnatSecretKey.
			"$amount".
			$order_id
		));
		
		$DeliveryFirstname	= (@$order['details']['BT']->first_name) ? $order['details']['BT']->first_name : 1;
		$DeliveryLastname	= (@$order['details']['BT']->last_name) ? $order['details']['BT']->last_name : 1;
		$DeliveryZip		= (@$order['details']['BT']->zip) ? $order['details']['BT']->zip : 1 ;
		$DeliveryCountry	= (@$order['details']['BT']->virtuemart_country_id) ? $order['details']['BT']->virtuemart_country_id : 1 ;
		$DeliveryPatronymic	= '1';
		$DeliveryStreet		= (@$order['details']['BT']->address_1) ? $order['details']['BT']->address_1 : 1 ;
		$DeliveryCity		= (@$order['details']['BT']->city) ? $order['details']['BT']->city : 1 ;
		$DeliveryZone		= 0 ;

		$BuyerCountry 		= $DeliveryCountry;
		$BuyerFirstname 	= $DeliveryFirstname;
		$BuyerPatronymic 	= '1';
		$BuyerLastname		= $DeliveryLastname;
		$BuyerStreet		= (@$order['details']['BT']->address_2) ? $order['details']['BT']->address_2 : $DeliveryStreet;
		$BuyerZone			= $DeliveryZone;
		$BuyerZip			= $DeliveryZip;
		$BuyerCity			= $DeliveryCity;
		
		//Детали платежа
		$paymentDetails = Array(
		   "MerchantInternalPaymentId"=>"$order_id",// Номер платежа в системе мерчанта
		   "MerchantInternalUserId"=>"$virtuemart_user_id", //Номер пользователя в системе мерчанта
		   "CustomMerchantInfo"=>"$signature_u",// Любая информация
		   "StatusUrl"=>"$result_url",// url состояния
		   "ReturnUrl"=>"$success_url",//url возврата 
		   "BuyerCountry"=>"$BuyerCountry",//Страна
		   "BuyerFirstname"=>"$BuyerFirstname",//Имя,
		   "BuyerPatronymic"=>"$BuyerPatronymic",// отчество
		   "BuyerLastname"=>"$BuyerLastname",//Фамилия
		   "BuyerStreet"=>"$BuyerStreet",// Адрес
		   "BuyerZone"=>"$BuyerZone",//   Область
		   "BuyerZip"=>"$BuyerZip",//  Индекс
		   "BuyerCity"=>"$BuyerCity",//   Город,
			// аналогичная информация о доставке
		   "DeliveryFirstname"=>"$DeliveryFirstname",// 
		   "DeliveryLastname"=>"$DeliveryLastname",//
		   "DeliveryZip"=>"$DeliveryZip",//     
		   "DeliveryCountry"=>"$DeliveryCountry",//   
		   "DeliveryPatronymic"=>"$DeliveryPatronymic",//
		   "DeliveryStreet"=>"$DeliveryStreet",//   
		   "DeliveryCity"=>"$DeliveryCity",//      ,
		   "DeliveryZone"=>"$DeliveryZone",//      ,
		);
		
		$signature = md5(
			$merchantGuid.
			"$amount2".
			"$product_count".
			$paymentDetails["MerchantInternalUserId"].
			$paymentDetails["MerchantInternalPaymentId"].
			$selectedPaySystemId.
			$merchnatSecretKey
		);

		$request = Array(
			"SelectedPaySystemId"=>$selectedPaySystemId,
			"Products"=>$products,
			/* "Fields"=>$userEnteredFields, */
			"PaymentDetails"=>$paymentDetails,
			"Signature"=>$signature,
			"MerchantGuid"=>$merchantGuid
		);
		
		$res = $this->sendRequestKaznachey($this->urlGetMerchantInfo, json_encode($request));
		$result = json_decode($res,true);
		
		if($result['ErrorCode'] != 0)
		{
  			JController::setRedirect($fail_url, 'Ошибка транзакции' );
			JController::redirect(); 
		}else{
			$html = base64_decode($result["ExternalForm"]);
		}
		
        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
    }
    
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null;
        }
        
        $db = JFactory::getDBO();
        $q  = 'SELECT * FROM `' . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }
        $this->getPaymentCurrency($paymentTable);
        
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";
        return $html;
    }
    
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        return 0;
    }
    
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }
    
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }
    
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }
    
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
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
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
    
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }
    
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }
    
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
    
    protected function displayLogos($logo_list)
    {
        $img = "";
        
        if (!(empty($logo_list))) {
            $url = JURI::root() . str_replace('\\', '/', str_replace(JPATH_ROOT, '', dirname(__FILE__))) . '/';
            if (!is_array($logo_list))
                $logo_list = (array) $logo_list;
            foreach ($logo_list as $logo) {
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /> ';
            }
        }
        return $img;
    }
	
	/**
	 * @param $plugin plugin
    */

	protected function renderPluginName ($plugin) {
		$return = '';
		$plugin_name = $this->_psType . '_name';
		$plugin_desc = $this->_psType . '_desc';
		$description = '';

		$logosFieldName = $this->_psType . '_logos';
		$logos = $plugin->$logosFieldName;
		if (!empty($logos)) {
			$return = $this->displayLogos ($logos) . ' ';
		}
		if (!empty($plugin->$plugin_desc)) {
			$description = '<span class="' . $this->_type . '_description">' . $plugin->$plugin_desc . '</span>';
		}
		
		$cc_types = $this->GetMerchnatInfo();
		if($cc_types)
		{
			$select = '<br><select name="cc_type" id="cc_type">';
			foreach ($cc_types["PaySystems"] as $paysystem)
			{
				$select .= '<option value="'.$paysystem['Id'].'">'.$paysystem['PaySystemName'].'</option>';
			}
			$select .= '</select>';
			
			$term_url = $this->GetTermToUse();
			$cc_agreed = "<br><input type='checkbox' class='form-checkbox' name='cc_agreed' id='cc_agreed' checked><label for='edit-panes-payment-details-cc-agreed'><a href='$term_url' target='_blank'>Согласен с условиями использования</a></label>";
			
	$html .= '<script type="text/javascript">';
	$html .= "//<![CDATA[
	jQuery(document).ready(function($) {
		 var cc_a = $('#cc_agreed');
		 cc_a.click(function(){
			if(cc_a.is(':checked')){	
				$('.cart-summary').find('.red').remove();
			}else{
				cc_a.next().after('<span class=red>Примите условие!</span>');
			}
			
		 });

		function change_cc_type(){
			var cc_type = $('#cc_type').val();
			$('#checkoutForm').find('.cc_agreed_h').remove().end()
				.append('<input type=hidden name=cc_agreed class=cc_agreed_h value=\"'+cc_type+'\" />');
		}
		
		$('#cc_type').change(function () {
			change_cc_type();
		});
		
		change_cc_type();
			
	});
//]]>";
			$html .= '</script>';
			
		}
		
		$pluginName = $return . '<span class="' . $this->_type . '_name">' . $plugin->$plugin_name . '</span>' . $description . $select . $cc_agreed . $html;
		return $pluginName;
	}
    
    public function plgVmOnPaymentNotification()
    {
        if (JRequest::getVar('pelement') != 'kaznachey') {
            return null;
        }
        
		if (!class_exists('VirtueMartModelOrders')){
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}

        $order_id = JRequest::getVar('order_number');
		$order    = VirtueMartModelOrders::getOrder($order_id);
		$error = false;
		
        $method   = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
        if ($method)
        {
			$merchantGuid = $method->merchant_id;
			$merchnatSecretKey  = $method->secret_key;	
		
			$HTTP_RAW_POST_DATA = @$HTTP_RAW_POST_DATA ? $HTTP_RAW_POST_DATA : file_get_contents('php://input');

			$hrpd = json_decode($HTTP_RAW_POST_DATA);
			if(@$hrpd->MerchantInternalPaymentId)
			{
				$merchantGuid = $method->merchant_id;
				$merchnatSecretKey  = $method->secret_key;		

				$amount = number_format($order['details']['BT']->order_total, 2, '.', '');
				
				$signature_u = md5(md5(
					$merchantGuid.
					$merchnatSecretKey.
					"$amount".
					$order_id
				));
			
				if($hrpd->ErrorCode == 0)
				{
					if($hrpd->CustomMerchantInfo == $signature_u)
					{
					  $order['order_status']        = $method->status_success;
					  $order['virtuemart_order_id'] = "$order_id";
					  $order['customer_notified']   = 0;
					  $order['comments']            = JTExt::sprintf('VMPAYMENT_kaznachey_PAYMENT_CONFIRMED', $order_id);
					  if (!class_exists('VirtueMartModelOrders'))
						require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
					  $modelOrder = new VirtueMartModelOrders();
					  ob_start();
						$modelOrder->updateStatusForOneOrder($order_id, $order, true);
					  ob_end_clean();
					}else{
						$error = "Wrong_SIGNATURE";
					}
				}else{
					$error = "Transaction_error";
				}
			}
			
			if($error)
			{
			  $order['order_status']        = $method->status_canceled;
			  $order['virtuemart_order_id'] = "$order_id";
			  $order['customer_notified']   = 0;
			  $order['comments']            = JTExt::sprintf("VMPAYMENT_kaznachey_PAYMENT_ERROR: $error", $order_id);
			  if (!class_exists('VirtueMartModelOrders'))
				require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
			  $modelOrder = new VirtueMartModelOrders();
			  ob_start();
				$modelOrder->updateStatusForOneOrder($order_id, $order, true);
			  ob_end_clean();
			}
		}
     
        exit;
        return null;
    }
    
    function plgVmOnPaymentResponseReceived(&$html)
    {
        // the payment itself should send the parameter needed;
        
        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
        
        $vendorId = 0;
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        
        $order_number        = JRequest::getVar('on');
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
        $payment_name        = $this->renderPluginName($method);
        $html                = '<table>' . "\n";
        $html .= $this->getHtmlRow('kaznachey_PAYMENT_NAME', $payment_name);
        $html .= $this->getHtmlRow('kaznachey_ORDER_NUMBER', $virtuemart_order_id);
        $html .= $this->getHtmlRow('kaznachey_STATUS', JText::_('VMPAYMENT_kaznachey_STATUS_SUCCESS'));
        
        $html .= '</table>' . "\n";
        
        if ($virtuemart_order_id) {
            if (!class_exists('VirtueMartCart'))
                require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
            // get the correct cart / session
            $cart = VirtueMartCart::getCart();
            
            // send the email ONLY if payment has been accepted
            if (!class_exists('VirtueMartModelOrders'))
                require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
            $order      = new VirtueMartModelOrders();
            $orderitems = $order->getOrder($virtuemart_order_id);
            $cart->sentOrderConfirmedEmail($orderitems);
            $cart->emptyCart();
        }
        
        return true;
    }
    
    function plgVmOnUserPaymentCancel()
    {
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        
        $order_number = JRequest::getVar('on');
        if (!$order_number)
            return false;
        $db    = JFactory::getDBO();
        $query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'";
        
        $db->setQuery($query);
        $virtuemart_order_id = $db->loadResult();
        
        if (!$virtuemart_order_id) {
            return null;
        }
        $this->handlePaymentUserCancel($virtuemart_order_id);
        
        return true;
    }

    private function notifyCustomer($order, $order_info)
    {
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        if (!class_exists('VirtueMartControllerVirtuemart'))
            require(JPATH_VM_SITE . DS . 'controllers' . DS . 'virtuemart.php');
        
        if (!class_exists('shopFunctionsF'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        $controller = new VirtueMartControllerVirtuemart();
        $controller->addViewPath(JPATH_VM_ADMINISTRATOR . DS . 'views');
        
        $view = $controller->getView('orders', 'html');
        if (!$controllerName)
            $controllerName = 'orders';
        $controllerClassName = 'VirtueMartController' . ucfirst($controllerName);
        if (!class_exists($controllerClassName))
            require(JPATH_VM_SITE . DS . 'controllers' . DS . $controllerName . '.php');
        
        $view->addTemplatePath(JPATH_COMPONENT_ADMINISTRATOR . '/views/orders/tmpl');
        
        $db = JFactory::getDBO();
        $q  = "SELECT CONCAT_WS(' ',first_name, middle_name , last_name) AS full_name, email, order_status_name
			FROM #__virtuemart_order_userinfos
			LEFT JOIN #__virtuemart_orders
			ON #__virtuemart_orders.virtuemart_user_id = #__virtuemart_order_userinfos.virtuemart_user_id
			LEFT JOIN #__virtuemart_orderstates
			ON #__virtuemart_orderstates.order_status_code = #__virtuemart_orders.order_status
			WHERE #__virtuemart_orders.virtuemart_order_id = '" . $order['virtuemart_order_id'] . "'
			AND #__virtuemart_orders.virtuemart_order_id = #__virtuemart_order_userinfos.virtuemart_order_id";
        $db->setQuery($q);
        $db->query();
        $view->user  = $db->loadObject();
        $view->order = $order;
        JRequest::setVar('view', 'orders');
        $user = $this->sendVmMail($view, $order_info['details']['BT']->email, false);
        if (isset($view->doVendor)) {
            $this->sendVmMail($view, $view->vendorEmail, true);
        }
    }

    private function sendVmMail(&$view, $recipient, $vendor = false)
    {
        ob_start();
        $view->renderMailLayout($vendor, $recipient);
        $body = ob_get_contents();
        ob_end_clean();
        
        $subject = (isset($view->subject)) ? $view->subject : JText::_('COM_VIRTUEMART_DEFAULT_MESSAGE_SUBJECT');
        $mailer  = JFactory::getMailer();
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->isHTML(VmConfig::get('order_mail_html', true));
        $mailer->setBody($body);
        
        if (!$vendor) {
            $replyto[0] = $view->vendorEmail;
            $replyto[1] = $view->vendor->vendor_name;
            $mailer->addReplyTo($replyto);
        }
        
        if (isset($view->mediaToSend)) {
            foreach ((array) $view->mediaToSend as $media) {
                $mailer->addAttachment($media);
            }
        }
        return $mailer->Send();
    }
	
	private function sendRequestKaznachey($url,$data)
	{
		$curl =curl_init();
		if (!$curl)
			return false;

		curl_setopt($curl, CURLOPT_URL,$url );
		curl_setopt($curl, CURLOPT_POST,true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, 
				array("Expect: ","Content-Type: application/json; charset=UTF-8",'Content-Length: ' 
					. strlen($data)));
		curl_setopt($curl, CURLOPT_POSTFIELDS,$data);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER,True);
		$res =  curl_exec($curl);
		curl_close($curl);

		return $res;
	}

	private function GetMerchnatInfo($id = false)
	{
	    if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
		
		$urlGetClientMerchantInfo = $this->urlGetClientMerchantInfo;
		$merchantGuid = $method->merchant_id;
		$merchnatSecretKey  = $method->secret_key;

		$requestMerchantInfo = Array(
			"MerchantGuid"=>$merchantGuid,
			"Signature"=>md5($merchantGuid.$merchnatSecretKey)
		);

		$resMerchantInfo = json_decode($this->sendRequestKaznachey($urlGetClientMerchantInfo , json_encode($requestMerchantInfo)),true); 
		
		if($id)
		{
			foreach ($resMerchantInfo["PaySystems"] as $key=>$paysystem)
			{
				if($paysystem['Id'] == $id)
				{
					return $paysystem;
				}
			}
		}else{
			return $resMerchantInfo;
		}
	}
	
	private function GetTermToUse()
	{
	    if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
		
		$urlGetClientMerchantInfo = $this->urlGetClientMerchantInfo;
		$merchantGuid = $method->merchant_id;
		$merchnatSecretKey  = $method->secret_key;

		$requestMerchantInfo = Array(
			"MerchantGuid"=>$merchantGuid,
			"Signature"=>md5($merchantGuid.$merchnatSecretKey)
		);

		$resMerchantInfo = json_decode($this->sendRequestKaznachey($urlGetClientMerchantInfo , json_encode($requestMerchantInfo)),true); 

		return $resMerchantInfo['TermToUse'];

	}
    
}
