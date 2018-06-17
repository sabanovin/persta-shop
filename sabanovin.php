<?php
if (!defined('_PS_VERSION_'))
	exit('');

require_once (_PS_MODULE_DIR_ . 'sabanovin/sms/sms.php');


class Sabanovin extends Module
{
	private $logger;
	private $log_enabled = true;
	private $api;
	public function __construct()
	{
		$this->name = 'sabanovin';
		$this->tab = 'emailing';	
		$this->page = basename(__FILE__, '.php');
		$this->displayName = $this->l('Sabanovin');
		$this->description = $this->l('send sms notifications with Sabanovin');
		$this->version = '1.0.0';
		$this->author = 'Sabanovin Dev Team';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = array(
			'min' => '1.6',
			'max' => _PS_VERSION_
		);
		$this->bootstrap = true;
		parent::__construct();
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
			// Checking Extension
		if (!extension_loaded('curl') || !ini_get('allow_url_fopen'))
		{
			if (!extension_loaded('curl') && !ini_get('allow_url_fopen'))
				$this->warning = $this->l('You must enable cURL extension and allow_url_fopen option on your server if you want to use this module.');
			else
				if (!extension_loaded('curl'))
					$this->warning = $this->l('You must enable cURL extension on your server if you want to use this module.');
				else
					if (!ini_get('allow_url_fopen'))
						$this->warning = $this->l('You must enable allow_url_fopen option on your server if you want to use this module.');
		}
		$this->initLogger();
		
	}

	public function isConfigured(){
		if (!Configuration::get('Sabanovin_APIKey'))
		    return false;
		if (!Configuration::get('Sabanovin_Sender'))
		   return false;
		return true;
	}

	public function install()
	{
		$this->logMessage('Installing Sabanovin Module...');
		if (Shop::isFeatureActive())
			Shop::setContext(Shop::CONTEXT_ALL);
		$this->logMessage('Installing Sabanovin Module');
		$success = (parent::install() && $this->hookInstall());
		if ($success)
		{
			$suggested = '%first_name% %last_name%  یک سفارش جدید (%order_reference%) در تاریخ  %order_date% به مبلغ  %order_price%  ثبت کرده است';
			Configuration::updateValue('Sabanovin_ORDER_NOTIFICATION_TEMPLATE', $suggested);
			$suggested = '%first_name% %last_name%  عزیر سفارش شما به وضعیت %order_state% تغییر یافت';
			Configuration::updateValue('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE', $suggested);
			$suggested = '%first_name% %last_name% ثبت نام کرد';
			Configuration::updateValue('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE', $suggested);
			// $suggested = '%first_name% %last_name% عزیز با تشکر از ثبت نام شما';
			// Configuration::updateValue('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE', $suggested);
			$this->logMessage('Successfully installed Sabanovin Module');
		}
		else
			$this->logMessage('Error Installing Sabanovin Module');

		return $success;
	}

	
	private function removeConfigKeys()
	{
		if (!Configuration::deleteByName('Sabanovin_APIKey'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_Sender'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_Admin_Mobile'))
			return false;
			
		if (!Configuration::deleteByName('Sabanovin_ORDER_NOTIFICATION_ACTIVE'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_ORDER_NOTIFICATION_TEMPLATE'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE'))
			return false;
			
		if (!Configuration::deleteByName('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE'))
			return false;
			
		if (!Configuration::deleteByName('Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE'))
			return false;
		if (!Configuration::deleteByName('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE'))
			return false;	
		// if (!Configuration::deleteByName('Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE'))
			// return false;
		// if (!Configuration::deleteByName('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE'))
			// return false;	
	}

	private function hookUninstall()
	{
		$this->logMessage('hookUninstall');
		return ($this->unregisterHook('actionPaymentConfirmation') && $this->unregisterHook('orderConfirmation') && $this->unregisterHook('updateOrderStatus') &&  $this->unregisterHook('actionCustomerAccountAdd'));
	}

	private function hookInstall()
	{
		$this->logMessage('hookInstall');	
		return ($this->unregisterHook('actionPaymentConfirmation') && $this->registerHook('orderConfirmation') && $this->registerHook('updateOrderStatus') && $this->registerHook('actionCustomerAccountAdd'));
	}
	
	public function uninstall()
	{
		$this->logMessage('Uninstalling Sabanovin Module');
		$success = (parent::uninstall() && $this->removeConfigKeys() && $this->hookUninstall());
		if ($success)
			$this->logMessage('Sabanovin Module Uninstalled Successfully');
		return $success;
	}
	
	private function shouldNotifyUponShipment()
	{
		return Configuration::get('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE') == 1 &&Configuration::get('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE') != '';
	}
	
	private function shouldNotifyUponNewOrder()
	{
		return Configuration::get('Sabanovin_ORDER_NOTIFICATION_ACTIVE') == 1 && Configuration::get('Sabanovin_ORDER_NOTIFICATION_TEMPLATE') != '';
	}
	private function shouldNotifyUponNewOrderForClient()
	{
		return Configuration::get('Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE') == 1 && Configuration::get('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE') != '';
	}
	
	
	private function shouldNotifyCustomerAccountAdd()
	{
		$this->logMessage('shouldNotifyCustomerAccountAdd');
		return Configuration::get('Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE') == 1 && Configuration::get('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE') != '';
	}
	// private function shouldNotifyCustomerAccountAddForClient()
	// {
		// return Configuration::get('Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE') == 1 && Configuration::get('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE') != '';
	// }
	
	public function hookActionCustomerAccountAdd($params)
	{
		$this->logMessage('hookActionCustomerAccountAdd');
		
		if (!$this->checkModuleStatus())
		{
			$this->logMessage('Sabanovin module not enabled');
			return false;
		}
		$newCustomer=$params['newCustomer'];
		if (!$newCustomer)
		{
			$this->logMessage('Unable to load order data');
			return false;
		}
		//$this->logMessage(print_r($newCustomer, 1));
		if(!$this->shouldNotifyCustomerAccountAdd()){
			$this->logMessage('Used did not opted in for New Customer Account Add notification');
			return false;
		}else{
			$this->logMessage("valid hookActionCustomerAccountAdd");
			$template = Configuration::get('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE');
			$data = array();
			$data['message']  = $this->buildMessageBody(array("first_name"=>$newCustomer->firstname,"last_name"=>$newCustomer->lastname), $template);
			$data['sender']   = Configuration::get('Sabanovin_Sender');
			$data['receptor'] = Configuration::get('Sabanovin_Admin_Mobile');
			$this->Send($data);
		}
		// if($this->shouldNotifyCustomerAccountAddForClient()){
				   // $this->logMessage('Used did not opted in for New Customer Account Add notification For Client');
		// }else{
			// $template = Configuration::get('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE');
			// $data = array();
			// $data['message']  = $this->buildMessageBody(array("first_name"=>$newCustomer->firstname,"last_name"=>$newCustomer->lastname), $template);
			// $data['sender']   = Configuration::get('Sabanovin_Sender');
			// $data['receptor'] = $newCustomer->phone_mobile;
			// $this->logMessage('hookActionCustomerAccountAdd');
			// $this->Send($data);
		// }
		$this->logMessage('end hookActionCustomerAccountAdd');
		return true;
	}
	
	public function hookUpdateOrderStatus($params)
	{
		$this->logMessage('hookUpdateOrderStatus ***********************');
		//$this->logMessage(print_r($params, 1));
		if (!$this->checkModuleStatus())
		{
			$this->logMessage('Sabanovin module not enabled');
			return false;
		}
		if (!$this->shouldNotifyUponShipment())
		{
			$this->logMessage('User did not opted in for shipment notification');
			return false;
		}
		$this->logMessage('Valid hookUpdateOrderStatus');
		
		$newOrderStatus = $params['newOrderStatus'];
		if($newOrderStatus->template=="payment"){
			$this->logMessage("Order state is not valid : payment ");
			return false;
		}
		
		$params = $this->getParamsFromOrder($params);
		if (!$params)
		{
			$this->logMessage('Unable to load order data');
			return false;
		}


		$template = Configuration::get('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE');
		$data = array();
		$data['message'] = $this->buildMessageBody($params, $template);
		$data['sender'] = Configuration::get('Sabanovin_Sender');
		$data['receptor'] = $params['customer_mobile'];
		
		$this->logMessage('*********************** hookUpdateOrderStatusc');
		return $this->Send($data);
	}
	
	public function hookActionPaymentConfirmation($params)
	{
		$this->logMessage('hookActionPaymentConfirmation ***********************');
		if (!$this->checkModuleStatus())
		{
			$this->logMessage('Sabanovin module not enabled');
			return false;
		}
		if (!$params)
		{
			$this->logMessage('Unable to retreive params from order');
			return false;
		}


		$params = $this->getParamsFromOrder($params);
		if (!$params)
		{
			$this->logMessage('Unable to load order data');
			return false;
		}

		if (!$this->shouldNotifyUponNewOrder())
		{
			$this->logMessage("!shouldNotifyUponNewOrder()");
			
		}else{
			$template = Configuration::get('Sabanovin_ORDER_NOTIFICATION_TEMPLATE');
			$data = array();
			$data['message'] = $this->buildMessageBody($params, $template);
			$data['sender'] = Configuration::get('Sabanovin_Sender');
			$data['receptor'] = Configuration::get('Sabanovin_Admin_Mobile');
			$this->Send($data);
		}
		
		if (!$this->shouldNotifyUponNewOrderForClient())
		{
			$this->logMessage("!shouldNotifyUponNewOrderForClient()");
		}else {
			if ($params['customer_mobile'])
			{
				$template = Configuration::get('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE');
				$data = array();
				$data['message'] = $this->buildMessageBody($params, $template);
				$data['sender'] = Configuration::get('Sabanovin_Sender');
				$data['receptor'] = $params['customer_mobile'];
				$this->Send($data);
			}	
		}	
		$this->logMessage('*********************** hookActionPaymentConfirmation');
		return true;
	}

	public function hookOrderConfirmation($params)
	{
		$this->logMessage('hookOrderConfirmation ***********************');
		
		if (!$this->checkModuleStatus())
		{
			$this->logMessage('Sabanovin module not enabled');
			return false;
		}
		if (!$params)
		{
			$this->logMessage('Unable to retreive params from order');
			return false;
		}
		$params = $this->getParamsFromOrder($params);
		if (!$params)
		{
			$this->logMessage('Unable to load order data');
			return false;
		}

		if (!$this->shouldNotifyUponNewOrder())
		{
			$this->logMessage('!shouldNotifyUponNewOrder()');			
		}
		else{
			$template = Configuration::get('Sabanovin_ORDER_NOTIFICATION_TEMPLATE');
			$data = array();
			$data['message'] = $this->buildMessageBody($params, $template);
			$data['sender'] = Configuration::get('Sabanovin_Sender');
			$data['receptor'] = Configuration::get('Sabanovin_Admin_Mobile');
			$this->Send($data);
		}
		if (!$this->shouldNotifyUponNewOrderForClient())
		{
			$this->logMessage('!shouldNotifyUponNewOrderForClient()');			
		}
		else{
			$template = Configuration::get('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE');
			$data = array();
			$data['message'] = $this->buildMessageBody($params, $template);
			$data['sender'] = Configuration::get('Sabanovin_Sender');
			$data['receptor'] = $params['customer_mobile'];
			$this->Send($data);
		}	
		$this->logMessage('***********************  hookOrderConfirmation');
		
		return true;
	}

	

	private function getParamsFromOrder($params)
	{

		$this->logMessage('getParamsFromOrder ***********************');
		
		// //  $this->logMessage("params");
		// //  $this->logMessage(print_r($params, 1));

		$currency = $this->context->currency;
		$language_id = $this->context->language->id;
		$order_state = $params['newOrderStatus']->name;
		$id_order = $params['id_order'];

		$this->logMessage("id_order");
		$this->logMessage($id_order);
		
		if(!isset($params['id_order']) || $id_order==null || !$id_order){
			$id_order=$params['objOrder']->id;
		}

		$order = new Order($id_order);
		$address = new Address((int)$order->id_address_delivery);
		$params = array();
		$firstname = (isset($address->firstname)) ? $address->firstname : '';
		$lastname = (isset($address->lastname)) ? $address->lastname : '';		
		// get order date.
		// try to format the date according to language context
		$order_date = (isset($order->date_upd)) ? $order->date_upd : 0;	
		$order_date=Tools::displayDate($order_date, $language_id, true);	
		// the order amount and currency.
		$order_price = (isset($order->total_paid)) ? $order->total_paid : 0;
		$order_price=Tools::displayPrice($order_price, $currency, false);	
		
		if (_PS_VERSION_ < '1.5.0.0')
			$order_reference = (isset($order->id)) ? $order->id : '';
		else
			$order_reference = (isset($order->reference)) ? $order->reference : '';
			
		// Prepare variables for message template replacement.
		// We assume the user have specified a template for the message.
		$params['first_name'] = $firstname;
		$params['last_name'] = $lastname;
		$params['order_price'] = $order_price;
		$params['order_date'] = $order_date;
		$params['order_reference'] = $order_reference;
		$params['order_state'] = $order_state;

		$customer_mobile = $this->buildCustomerMobileNumber($address);
		$params['customer_mobile'] = $customer_mobile;


		// // $this->logMessage("order");
		// // $this->logMessage(print_r($order, 1));

		// // $this->logMessage("address");	
		// // $this->logMessage(print_r($address, 1));
	
		// // $this->logMessage("params");			
		// // $this->logMessage(print_r($params, 1));

		 $this->logMessage('*********************** getParamsFromOrder');
		
		return $params;
	}

	private function buildCustomerMobileNumber($address)
	{		
		
		$mobile_number = $address->phone_mobile;
		if (!$mobile_number)
		{
			$this->logMessage('Unable to retrive customers mobile number');
			return null;
		}
		$mobile_number = trim($mobile_number);
		return $mobile_number;
	}
	
	

	private function buildMessageBody($params, $template)
	{
		// TODO: we should perparse and notify the user if the message excedes a single message.	
		if (isset($params['first_name']))
			$template = str_replace('%first_name%', $params['first_name'], $template);

		if (isset($params['last_name']))
			$template = str_replace('%last_name%', $params['last_name'], $template);

		if (isset($params['order_price']))
			$template = str_replace('%order_price%', $params['order_price'], $template);

		if (isset($params['order_date']))
			$template = str_replace('%order_date%', $params['order_date'], $template);

		if (isset($params['order_reference']))
			$template = str_replace('%order_reference%', $params['order_reference'], $template);
		
		if (isset($params['order_state']))
			$template = str_replace('%order_state%', $params['order_state'], $template);

		return $template;
	}

	
	private function Send(array $data)
	{
		$this->logMessage('Send ***********************');
		try{
			$sender = $data['sender'];
			$receptor = $data['receptor'];
			$message = $data['message'];
			$APIKey=Configuration::get('Sabanovin_APIKey');
			$this->sms = new Sms();
			$result = $this->sms->send_sms($sender,$receptor,$message,$APIKey);
			$this->logMessage($result);
			//return true;
		}
		catch(\Sabanovin\Exceptions\ApiException $e){
			$this->logMessage($e->errorMessage());
			//return true;
		}
		catch(\Sabanovin\Exceptions\HttpException $e){
			$this->logMessage($e->errorMessage());
			//return true;
		}
		return true;
		$this->logMessage('***********************  Send');		
	}

	
	public function displayForm()
	{

		$data = array();
		$data['token'] = Tools::encrypt(Configuration::get('PS_SHOP_NAME'));
		$this->context->smarty->assign($data);

		// Get default language
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

	

		$fields_form = array();
		array_push($fields_form, array());

		// Configuration Form
		$fields_form[0]['form'] = array(
			'legend' => array(
				'title' => $this->l('Settings')
			),
			'input' => array(
				array(
					'type' => 'text',
					'label' => $this->l('APIKey'),
					'desc' => $this->l(''),
					'name' => 'Sabanovin_APIKey',
					'size' => 60,
					'required' => true
				),
				array(
					'type' => 'text',
					'label' => $this->l('Sender'),
					'desc' => $this->l(''),
					'name' => 'Sabanovin_Sender',
					'size' => 20,
					'required' => true
				),			
				array(
					'type' => 'text',
					'label' => $this->l('Admin Mobile Number'),
					'desc' => $this->l(''),
					'name' => 'Sabanovin_Admin_Mobile',
					'size' => 20,
					'required' => true
				),array(
					'type' => 'checkbox',
					'label' => $this->l('New Order notification enabled?'),
					'name' => 'Sabanovin_ORDER_NOTIFICATION',
					'required' => false,
					'values' => array(
						'query' => array(
							array(
								'id' => 'ACTIVE',
								'name' => $this->l('Enabled'),
								'val' => '1'
							)
						),
						'id' => 'id',
						'name' => 'name'
					)
				),array(
					'type' => 'textarea',
					'label' => $this->l('Order message template'),
					'desc' => $this->l('%first_name% %last_name% %order_price% %order_date% %order_reference%'),
					'name' => 'Sabanovin_ORDER_NOTIFICATION_TEMPLATE',
					'cols' => 40,
					'rows' => 5,
					'required' => false
				),array(
					'type' => 'checkbox',
					'label' => $this->l('New Order notification For Client enabled?'),
					'name' => 'Sabanovin_Client_ORDER_NOTIFICATION',
					'required' => false,
					'values' => array(
						'query' => array(
							array(
								'id' => 'ACTIVE',
								'name' => $this->l('Enabled'),
								'val' => '1'
							)
						),
						'id' => 'id',
						'name' => 'name'
					)
				),array(
					'type' => 'textarea',
					'label' => $this->l('Order message template For Client'),
					'desc' => $this->l('%first_name% %last_name% %order_price% %order_date% %order_reference%'),
					'name' => 'Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE',
					'cols' => 40,
					'rows' => 5,
					'required' => false
				),array(
					'type' => 'checkbox',
					'label' => $this->l('Shipment Status notification enabled?'),
					'name' => 'Sabanovin_SHIPMENTSTATUS_NOTIFICATION',
					'required' => false,
					'values' => array(
						'query' => array(
							array(
								'id' => 'ACTIVE',
								'name' => $this->l('Enabled'),
								'val' => '1'
							)
						),
						'id' => 'id',
						'name' => 'name'
					)
				),
				array(
					'type' => 'textarea',
					'label' => $this->l('Shipment Status template'),
					'desc' => $this->l('%first_name% %last_name% %order_price% %order_date% %order reference% %order_state%'),
					'name' => 'Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE',
					'cols' => 40,
					'rows' => 5,
					'required' => false
				),
				array(
					'type' => 'checkbox',
					'label' => $this->l('Customer Register Status notification enabled?'),
					'name' => 'Sabanovin_CustomerRegister_NOTIFICATION',
					'required' => false,
					'values' => array(
						'query' => array(
							array(
								'id' => 'ACTIVE',
								'name' => $this->l('Enabled'),
								'val' => '1'
							)
						),
						'id' => 'id',
						'name' => 'name'
					)
				),array(
					'type' => 'textarea',
					'label' => $this->l('Customer Register template'),
					'desc' => $this->l('%first_name% %last_name%'),
					'name' => 'Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE',
					'cols' => 40,
					'rows' => 5,
					'required' => false
				)/*,array(
					'type' => 'checkbox',
					'label' => $this->l('Customer Register Status notification For Client enabled?'),
					'name' => 'Sabanovin_Client_CustomerRegister_NOTIFICATION',
					'required' => false,
					'values' => array(
						'query' => array(
							array(
								'id' => 'ACTIVE',
								'name' => $this->l('Enabled'),
								'val' => '1'
							)
						),
						'id' => 'id',
						'name' => 'name'
					)
				),
				array(
					'type' => 'textarea',
					'label' => $this->l('Customer Register template For Client'),
					'desc' => $this->l('%first_name% %last_name%'),
					'name' => 'Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE',
					'cols' => 40,
					'rows' => 5,
					'required' => false
				)*/	
			),
			'submit' => array(
				'title' => $this->l('Save settings'),
				'class' => 'button'
			)
		);

		$helper = new HelperForm();

		// Module, token and currentIndex
		$helper->module = $this;
		$helper->name_controller = $this->name;

		// Store current token
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

		// Language
		$helper->default_form_language = $default_lang;
		$helper->allow_employee_form_lang = $default_lang;

		// Form Title
		$helper->title = $this->displayName;

		// Form Toolbar
		$helper->show_toolbar = true; // false -> remove toolbar
		$helper->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
		$helper->submit_action = 'submit'.$this->name;
		$helper->toolbar_btn = array(
			'save' => array(
				'desc' => $this->l('Save'),
				'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.'&token='.
				Tools::getAdminTokenLite('AdminModules')
			),
			'back' => array(
				'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
				'desc' => $this->l('Back to list')
			)
		);

		// Load current value
		$helper->fields_value['Sabanovin_APIKey'] = Configuration::get('Sabanovin_APIKey');
		
		$helper->fields_value['Sabanovin_Sender'] = Configuration::get('Sabanovin_Sender');
		
		$helper->fields_value['Sabanovin_Admin_Mobile'] = Configuration::get('Sabanovin_Admin_Mobile');
		
		$helper->fields_value['Sabanovin_ORDER_NOTIFICATION_ACTIVE'] = ((string)Configuration::get('Sabanovin_ORDER_NOTIFICATION_ACTIVE') == '1');
		$helper->fields_value['Sabanovin_ORDER_NOTIFICATION_TEMPLATE'] =  Configuration::get('Sabanovin_ORDER_NOTIFICATION_TEMPLATE');
		$helper->fields_value['Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE'] = ((string)Configuration::get('Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE') == '1');
		$helper->fields_value['Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE'] =  Configuration::get('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE');
		
		$helper->fields_value['Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE'] = ((string)Configuration::get('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE') =='1');
		$helper->fields_value['Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE'] = Configuration::get('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE');
		
		$helper->fields_value['Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE'] = ((string)Configuration::get('Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE') =='1');
		$helper->fields_value['Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE'] = Configuration::get('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE');
		// $helper->fields_value['Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE'] = ((string)Configuration::get('Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE') =='1');
		// $helper->fields_value['Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE'] = Configuration::get('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE');
		
		// Build the complete panel

		$theform = '<div class="Sabanovin">';

		// Bind the form with data
		$this->context->smarty->assign($data);

		$theform .= $this->display(__FILE__, 'views/templates/admin/style.tpl');
		$theform .= $this->display(__FILE__, 'views/templates/admin/header.tpl');
		$theform .= $helper->generateForm($fields_form);
		$theform .= '</div>';
		return $theform;
	}

	public function getContent()
	{
		if (Tools::isSubmit('submit'.$this->name))
		{
			
			$Sabanovin_APIKey = (string)Tools::getValue('Sabanovin_APIKey');
			Configuration::updateValue('Sabanovin_APIKey', $Sabanovin_APIKey);

			$Sabanovin_Sender = (string)Tools::getValue('Sabanovin_Sender');
			Configuration::updateValue('Sabanovin_Sender', $Sabanovin_Sender);			
						
			$Sabanovin_Admin_Mobile = (string)Tools::getValue('Sabanovin_Admin_Mobile');
			Configuration::updateValue('Sabanovin_Admin_Mobile', $Sabanovin_Admin_Mobile);			
			
			$Sabanovin_ORDER_NOTIFICATION_ACTIVE = Tools::getValue('Sabanovin_ORDER_NOTIFICATION_ACTIVE');
			Configuration::updateValue('Sabanovin_ORDER_NOTIFICATION_ACTIVE', $Sabanovin_ORDER_NOTIFICATION_ACTIVE);		
			$Sabanovin_ORDER_NOTIFICATION_TEMPLATE = (string)Tools::getValue('Sabanovin_ORDER_NOTIFICATION_TEMPLATE');
			Configuration::updateValue('Sabanovin_ORDER_NOTIFICATION_TEMPLATE', $Sabanovin_ORDER_NOTIFICATION_TEMPLATE);
			
			$Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE = Tools::getValue('Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE');
			Configuration::updateValue('Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE', $Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE);
			$this->logMessage('Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE: '.$Sabanovin_Client_ORDER_NOTIFICATION_ACTIVE);
			
			$Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE = (string)Tools::getValue('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE');
			Configuration::updateValue('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE', $Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE);
			$this->logMessage('Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE: '.$Sabanovin_Client_ORDER_NOTIFICATION_TEMPLATE);

						
			$Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE = Tools::getValue('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE');
			Configuration::updateValue('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE', $Sabanovin_SHIPMENTSTATUS_NOTIFICATION_ACTIVE);

			$Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE = (string)Tools::getValue('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE');
			Configuration::updateValue('Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE', $Sabanovin_SHIPMENTSTATUS_NOTIFICATION_TEMPLATE);
			
			$Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE = Tools::getValue('Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE');
			Configuration::updateValue('Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE', $Sabanovin_CustomerRegister_NOTIFICATION_ACTIVE);		
			$Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE = (string)Tools::getValue('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE');
			Configuration::updateValue('Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE', $Sabanovin_CustomerRegister_NOTIFICATION_TEMPLATE);
			// $Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE = Tools::getValue('Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE');
			// Configuration::updateValue('Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE', $Sabanovin_Client_CustomerRegister_NOTIFICATION_ACTIVE);		
			// $Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE = (string)Tools::getValue('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE');
			// Configuration::updateValue('Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE', $Sabanovin_Client_CustomerRegister_NOTIFICATION_TEMPLATE);

		}
		return $this->displayForm();
	}

	private function checkModuleStatus()
	{
		return Module::isEnabled('sabanovin');
	}
	
	private function logMessage($message)
	{
		if (!$this->log_enabled)
			return;
		$this->logger->logDebug($message);
	}

	private function initLogger()
	{
		if (!$this->log_enabled)
			return;
		$this->logger = new FileLogger(0);
		$this->logger->setFilename(_PS_ROOT_DIR_.'/log/sabanovin.log');
	}

}