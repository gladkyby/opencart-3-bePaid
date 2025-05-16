<?php
class ModelExtensionPaymentBeGateway extends Model {
  public function getMethod($address, $total) {
    $this->load->language('extension/payment/begateway');

    $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_begateway_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

    if ($this->config->get('payment_begateway_total') > 0 && $this->config->get('payment_begateway_total') > $total) {
      $status = false;
    } elseif (!$this->config->get('payment_begateway_geo_zone_id')) {
      $status = true;
    } elseif ($query->num_rows) {
      $status = true;
    } else {
      $status = false;
    }

    $method_data = array();

    if ($status) {
      $method_data = array(
        'code'       => 'begateway',
        'title'      => $this->language->get('text_title'),
        'terms'      => '',
        'sort_order' => $this->config->get('payment_begateway_sort_order')
      );
    }

    return $method_data;
  }

  public function getOrderByEmail($email, $amount) {
		$query = $this->db->query("SELECT order_id FROM " . DB_PREFIX . "order WHERE email = '" . $email . "' AND order_status_id NOT IN (3,9,20) ORDER BY order_id DESC"); // статус "в обработке"
		//$query = $this->db->query("SELECT order_id FROM " . DB_PREFIX . "order WHERE email = '" . $email . "' AND order_status_id = " . $this->config->get('begateway_status_id') . " ORDER BY order_id DESC");
		if ($query->num_rows == 0) {
			$query = $this->db->query("SELECT order_id FROM " . DB_PREFIX . "order WHERE total = '" . $amount . "' AND order_status_id IN (1,17,19) AND `payment_code` LIKE '%begat%' ORDER BY order_id DESC"); // статус "в обработке"
		}
		//$this->log->write('model/payment/begateway.php $query');
    //$this->log->write($query);
		return $query->row['order_id'];
	}


	public function saveTransaction($data, $message) {
		
		switch($data['payment_method_type']) {
			case 'erip':
				$payer = str_replace(['"', "'"], '', $data['erip']['agent_name']);
				break;
			case 'credit_card':
				$payer = $data['credit_card']['holder'] .'. Country: '. $data['credit_card']['issuer_country'];
				break;
			default:
				$payer = '';
		}
		
		$this->db->query("INSERT INTO " . DB_PREFIX . "ms_report_bepaid SET 
				uid = '".$data['uid']."', 
				order_id = ".$data['tracking_id'].", 
				created_at = '".$data['created_at']."', 
				paid_at = '".$data['paid_at']."', 
				amount = '".$data['amount']."', 
				message = '".$data['message']."', 
				payment_method_type = '".$data['payment_method_type']."',
				payer = '".$payer."' 
			ON DUPLICATE KEY UPDATE 
				paid_at = '".$data['paid_at']."', 
				message = '".$data['message']."',
				payment_method_type = '".$data['payment_method_type']."',
				payer = '".$payer."'
			");
		
		/* передача уведомления в Телеграм */
    $tlgrm = $this->db->query("SELECT notify FROM " . DB_PREFIX . "ms_report_bepaid WHERE uid = '".$data['uid']."' AND notify = 0");
    //$this->log->write('model/payment/begateway.php $tlgrm');$this->log->write($tlgrm);
    // добавить в настройки поля tlgrm_bp_notification_id и tlgrm_bp_notification_token
    if ($tlgrm->num_rows == 1) {
    	$this->load->model('module/tlgrm_notification');
			$this->model_extension_module_tlgrm_notification->sendNotification($message, $this->config->get('tlgrm_bp_notification_id'), $this->config->get('tlgrm_bp_notification_token'));
			$this->db->query("UPDATE " . DB_PREFIX . "ms_report_bepaid SET notify = 1 WHERE uid = '".$data['uid']."'");
    }
	}
}

?>
