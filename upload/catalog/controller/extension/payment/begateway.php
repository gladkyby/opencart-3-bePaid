<?php
class ControllerExtensionPaymentBeGateway extends Controller {
  const API_VERSION = 2;

  public function index() {
    $this->language->load('extension/payment/begateway');
    $this->load->model('checkout/order');

    $checkout_data = $this->generateToken();

    if ($checkout_data) {
      $data['action'] = strtok($checkout_data['redirect_url'], '?');
      $data['token'] = $checkout_data['token'];
    } else {
      $data['token'] = false;
    }

    $data['button_confirm'] = $this->language->get('button_confirm');
    $data['token_error'] = $this->language->get('token_error');
    $data['order_id'] = $this->session->data['order_id'];

    return $this->load->view('extension/payment/begateway', $data);
  }

  public function generateToken(){

    $this->load->model('checkout/order');
    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
    $orderAmount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
    $orderAmount = (float)$orderAmount * pow(10,(int)$this->currency->getDecimalPlace($order_info['currency_code']));
    $orderAmount = intval(strval($orderAmount));

    $customer_array =  array (
      /*'address' => strlen($order_info['payment_address_1']) > 0 ? $order_info['payment_address_1'] : null,
      'first_name' => strlen($order_info['payment_firstname']) > 0 ? $order_info['payment_firstname'] : null,
      'last_name' => strlen($order_info['payment_lastname']) > 0 ? $order_info['payment_lastname'] : null,
      'country' => strlen($order_info['payment_iso_code_2']) > 0 ? $order_info['payment_iso_code_2'] : null,
      'city'=> strlen($order_info['payment_city']) > 0 ? $order_info['payment_city'] : null,
      'phone' => strlen($order_info['telephone']) > 0 ? $order_info['telephone'] : null,
      'email'=> strlen($order_info['email']) > 0 ? $order_info['email'] : null,
      'zip' => strlen($order_info['payment_postcode']) > 0 ? $order_info['payment_postcode'] : null,*/
      'ip' => $this->request->server['REMOTE_ADDR']
    );

    if (in_array($order_info['payment_iso_code_2'], array('US','CA'))) {
      $customer_array['state'] = $order_info['payment_zone_code'];
    }

    $order_array = array ( 'currency'=> $order_info['currency_code'],
      'amount' => $orderAmount,
      'description' => $this->language->get('text_order'). ' ' .$order_info['order_id'],
      'tracking_id' => $order_info['order_id']);

    $callback_url = $this->url->link('extension/payment/begateway/callback1', '', 'SSL');
    $callback_url = str_replace('carts.local', 'webhook.begateway.com:8443', $callback_url);

    $setting_array = array ( 'success_url'=>$this->url->link('extension/payment/begateway/callback', '', 'SSL'),
      'decline_url'=> $this->url->link('checkout/checkout', '', 'SSL'),
      'cancel_url'=> $this->url->link('checkout/checkout', '', 'SSL'),
      'fail_url'=>$this->url->link('checkout/checkout', '', 'SSL'),
      'language' => $this->_language($this->session->data['language']),
      'notification_url'=> $callback_url);

    $transaction_type='payment';

    $payment_methods_array = array(
      'types' => array(
      )
    );

    $pm_type = $this->config->get('payment_begateway_payment_type');
    if ($pm_type['card'] == 1) {
      $payment_methods_array['types'][] = 'credit_card';
    }

    if ($pm_type['halva'] == 1) {
      $payment_methods_array['types'][] = 'halva';
    }

    if ($pm_type['erip'] == 1) {
      $payment_methods_array['types'][] = 'erip';
      $payment_methods_array['erip'] = array(
        'order_id' => $order_info['order_id'],
        'account_number' => $order_info['order_id'],
        'service_no' => $this->config->get('payment_begateway_erip_service_no'),
        'service_info' => array($order_array['description'])
      );
    }

    $checkout_array = array(
      'version' => '2.1',
      'transaction_type' => $transaction_type,
      'test' => intval($this->config->get('payment_begateway_test_mode')) == 1,
      'settings' =>$setting_array,
      'order' => $order_array,
      'customer' => $customer_array
      );

    if (count($payment_methods_array['types']) > 0) {
      $checkout_array['payment_method'] = $payment_methods_array;
    }

    $token_json =  array('checkout' =>$checkout_array );

    $this->load->model('checkout/order');

    $post_string = json_encode($token_json);

    $username=$this->config->get('payment_begateway_companyid');
    $password=$this->config->get('payment_begateway_encyptionkey');
    $ctp_url = 'https://' . $this->config->get('payment_begateway_domain_payment_page') . '/ctp/api/checkouts';

    $curl = curl_init($ctp_url);
    curl_setopt($curl, CURLOPT_PORT, 443);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
      'X-API-Version: ' . self::API_VERSION,
      'Content-Type: application/json',
      'Content-Length: '.strlen($post_string))) ;
    curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $post_string);

    $response = curl_exec($curl);
    $curl_error = curl_error($curl);
    $curl_errno = curl_errno($curl);
    curl_close($curl);

    if (!$response) {
      $this->log->write("Payment token request failed: $curl_error ($curl_errno)");
      return false;
    }

    $token = json_decode($response,true);

    if ($token == NULL) {
      $this->log->write("Payment token response parse error: $response");
      return false;
    }

    if (isset($token['errors'])) {
      $this->log->write("Payment token request validation errors: $response");
      return false;
    }

    if (isset($token['response']) && isset($token['response']['message'])) {
      $this->log->write("Payment token request error: $response");
      return false;
    }

    if (isset($token['checkout']) && isset($token['checkout']['token'])) {
      return $token['checkout'];
    } else {
      $this->log->write("No payment token in response: $response");
      return false;
    }
  }

  public function callback() {

    if (isset($this->session->data['order_id'])) {
      $order_id = $this->session->data['order_id'];
    } else {
      $order_id = 0;
    }

    $this->load->model('checkout/order');
    $order_info = $this->model_checkout_order->getOrder($order_id);

    $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
  }

  public function callback1() {

    $postData =  (string)file_get_contents("php://input");

    $post_array = json_decode($postData, true);

    // обработка массивов с данными о транзакциях с другими названиями
    if (isset($post_array['last_transaction'])) {
    	$post_array['transaction'] = [
    		'uid' 			=> $post_array['last_transaction']['uid'],
    		'status'		=> $post_array['last_transaction']['status'],
    		'message'		=> $post_array['last_transaction']['message'],
    		'tracking_id'		=> $post_array['product']['name'],
    		'amount'		=> $post_array['product']['amount'],
    		'currency'		=> $post_array['product']['currency'],
    		'created_at'		=> $post_array['product']['created_at'],
    		'paid_at'		=> $post_array['last_transaction']['created_at'],
    		'payment_method_type'	=> ''
    	];
    }
    
    if (isset($post_array['order'])) {
        $post_array['transaction'] = [
    		'uid' 			=> $post_array['order']['additional_data']['request_id'],
    		'status'		=> $post_array['status'],
    		'message'		=> $post_array['message'],
    		'tracking_id'		=> $post_array['order']['tracking_id'],
    		'amount'		=> $post_array['order']['amount'],
    		'currency'		=> $post_array['order']['currency'],
    		'created_at'		=> $post_array['payment_method']['created_at'],
    		'paid_at'		=> $post_array['payment_method']['updated_at'],
    		'payment_method_type'	=> ''
    	];
    }
    // обработка end
    
    //обработка вебхука из Битрикс
    if (isset($post_array['transaction']['additional_data']['platform_data']) && $post_array['transaction']['additional_data']['platform_data'] == 'Bitrix24') {
  	// передача вебхука в Битрикс домен сохранияет модуль от api.pro
	$ch = curl_init('https://'.$this->config->get('b24_key_domain').'/bitrix/tools/sale_ps_result.php');
  	curl_setopt($ch, CURLOPT_POST, 1);
  	curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  	$response = curl_exec($ch);
  	curl_close($ch);
  		
  	// Вывод email-адреса из строки описания
  	preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $post_array['transaction']['description'], $matches);
  	$email = $matches[0];
  		
  	// Проверка, существует ли в строке email, который оканчивается на fotomagazin.by
  	if (preg_match('/\b[A-Za-z0-9._%+-]+@fotomagazin\.by\b/', $email)) {
  	    // Извлечение числа до @
  	    preg_match('/\d+/', $email, $matches);
  	    $number = $matches[0];
  	}
  		
	if (isset($number)) {
	  $post_array['transaction']['tracking_id'] = (int)$number;
        } else {
	  $post_array['transaction']['tracking_id'] = $this->model_extension_payment_begateway->getOrderByEmail($email, $post_array['transaction']['amount'] / 100);
        }
    }
    //обработка end
    
    if (!isset($post_array['transaction'])) return;

    $order_id = $post_array['transaction']['tracking_id'];
    $status = $post_array['transaction']['status'];

    $transaction_id = $post_array['transaction']['uid'];
    $transaction_message = $post_array['transaction']['message'];
    $transaction_amount = $this->currency->format($post_array['transaction']['amount'] / 100);

    $date = new DateTime($post_array['transaction']['paid_at']);
    $date->setTimezone(new DateTimeZone('Europe/Minsk'));
    $paid_at = $date->format('d.m.Y H:i');

    $three_d = '';

    if (isset($post_array['transaction']['three_d_secure_verification'])) {
      $three_d = $post_array['transaction']['three_d_secure_verification']['pa_status'];
      if (isset($three_d)) {
        $three_d = '3-D Secure: ' . $three_d . '.';
      } 
    }

    //$this->log->write("Webhook received: $postData");
    $message = "$paid_at поступил платеж на сумму $transaction_amount по заказу <b>$order_id</b>." . PHP_EOL . "Номер операции: $transaction_id";

    $this->load->model('checkout/order');

    $order_info = $this->model_checkout_order->getOrder($order_id);

    if ($order_info) {
      $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('config_order_status_id'));

      if (isset($status) && $status == 'successful'){
        $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_begateway_completed_status_id'), $message, true);
        /* сохранение в БД */
        //$this->model_extension_payment_begateway->saveTransaction($post_array['transaction'], $message);
        /* передача лида в Б24 */
    	$this->load->model('module/b24_order');
	$get_b24_order = $this->model_module_b24_order->getById($order_id);
	//$this->log->write('controller/payment/begateway.php $get_b24_order');
	//$this->log->write($get_b24_order);
	if (empty($get_b24_order['b24_order_id'])) $this->model_module_b24_order->addOrder($order_id);
	/* отправка уведомления в телеграм */
	$this->sendNotification($message);
      }
      if(isset($status) && ($status == 'failed')){
	$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_begateway_failed_status_id'), "UID: $transaction_id. Fail reason: $transaction_message", true);
      }
    }
  }

  public function sendNotification($message) {
    	$link = 'https://api.telegram.org/bot';
    	$bot_token = $this->config->get('tlgrm_bp_notification_token');
	$chat_id = trim($this->config->get('tlgrm_bp_notification_id'));
	
	if (empty($bot_token) || empty($chat_id)) return;
        $sendToTelegram = $link . $bot_token;
        $message = strip_tags($message, '<b><a><i>');
	$params = [
	    'chat_id' => $chat_id,
	    'text' => $message,
	    'parse_mode' =>'html'
	];
	$ch = curl_init($sendToTelegram . '/sendMessage');
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, ($params));
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	$result = curl_exec($ch);
	curl_close($ch);
  }

  private function _language($lang_id) {
	$lang = substr($lang_id, 0, 2);
	$lang = strtolower($lang);
	return $lang;
  }
}
