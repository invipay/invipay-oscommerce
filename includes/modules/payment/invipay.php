<?php

  class invipay {
    var $code, $title, $description, $enabled;


    function invipay() {
		global $cart, $order, $billto, $messageStack;
		$this->code = 'invipay';
		$this->title = MODULE_PAYMENT_INVIPAY_TEXT_TITLE;
		$this->description = MODULE_PAYMENT_INVIPAY_TEXT_DESCRIPTION;
		if(MODULE_PAYMENT_INVIPAY_STATUS == '1'){
			$this->enabled = true;
		} else {
			$this->enabled = false;
		}
		$this->sort_order = MODULE_PAYMENT_INVIPAY_SORT_ORDER;
		if(MODULE_PAYMENT_INVIPAY_DEMO == '0'){
			$this->api_url = 'https://api.invipay.com/api/rest';
		} else {
			$this->api_url = 'http://demo.invipay.com/services/api/rest';
		}
		if(is_object($cart)){
			if($cart->total < MODULE_PAYMENT_INVIPAY_MINCART){
				$this->title .= '<br />'.MODULE_PAYMENT_INVIPAY_MINCART_INFO;
			}
		}
		if(is_object($order) && $billto && defined('MODULE_PAYMENT_INVIPAY_NIP_FIELD')){
			$nip_query = tep_db_query('select '.MODULE_PAYMENT_INVIPAY_NIP_FIELD.' from '.TABLE_ADDRESS_BOOK.' where address_book_id = '.(int)$billto);
			$nip = tep_db_fetch_array($nip_query);
			if(!tep_not_null($nip[MODULE_PAYMENT_INVIPAY_NIP_FIELD])){
				$this->enabled = false;
			}
		}
		if(!is_object($order) && !is_object($cart) && $this->check()){
			require_once(DIR_FS_CATALOG ."ext/invipay/TestApiClient.class.php");
			$client = new TestApiClient($this->api_url, MODULE_PAYMENT_INVIPAY_PUBLIC_KEY, MODULE_PAYMENT_INVIPAY_PRIVATE_KEY);
			$request = new EchoIn();
			$request->setMessage("Hello world ążłóćł");
			$request->setReverse(true);
			try {
				$result = $client->echoMessage($request);
			} catch (Exception $e) {
				$this->enabled = false;
				tep_db_query('update '.TABLE_CONFIGURATION.' set configuration_value = "0" where configuration_key = "MODULE_PAYMENT_INVIPAY_STATUS"');
				$this->title .= '<br><span style="font-weight:bold;color:red">('.$e->getMessage().')</span><br>';
			}
		}
		
    }

    function javascript_validation() {
      return false;
    }

	function selection() {
     return array('id' => $this->code, 'module' => $this->title);
    } 

    function pre_confirmation_check() {
		global $cart;
		if($cart->total < MODULE_PAYMENT_INVIPAY_MINCART){
			tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode(MODULE_PAYMENT_INVIPAY_MINCART_ERROR), 'SSL'));
		}
		return false;
    }


    function confirmation() {
      global $_POST;

      $confirmation = array('title' => $this->title . ': ' . $this->check,
							'fields' => array(
								array('title' => MODULE_PAYMENT_INVIPAY_TEXT_DESCRIPTION)
							)
	  );
      return $confirmation;
    }

    function process_button() {
      return false;
    }

    function before_process() {
      return false;
    }

    function after_process() {
      global $insert_id, $billto, $order_totals, $order, $cart;
	  $i=0;
	  $total_cart_value = 0;
	  for($i=0,$n=sizeof($order_totals);$i<$n;$i++){
		if($order_totals[$i]['code'] == 'ot_total'){
			$total_cart_value = $order_totals[$i]['value'];
		}
	  }
	  
	  $nip_query = tep_db_query('select '.MODULE_PAYMENT_INVIPAY_NIP_FIELD.' from '.TABLE_ADDRESS_BOOK.' where address_book_id = '.(int)$billto);
	  $nip = tep_db_fetch_array($nip_query);
	
	  require_once(DIR_FS_CATALOG ."ext/invipay/PaygateApiClient.class.php"); 
	  $client = new PaygateApiClient($this->api_url, MODULE_PAYMENT_INVIPAY_PUBLIC_KEY, MODULE_PAYMENT_INVIPAY_PRIVATE_KEY); 
	  $request = new PaymentCreationData(); 
	  if(ENABLE_SSL){
		$request->setReturnUrl(HTTPS_SERVER.DIR_WS_HTTPS_CATALOG.FILENAME_CHECKOUT_SUCCESS);
		$request->setStatusUrl(HTTPS_SERVER.DIR_WS_HTTPS_CATALOG.'invipay_status.php?action=statusinvipay');
	  } else {
		$request->setReturnUrl(HTTP_SERVER.DIR_WS_HTTP_CATALOG.FILENAME_CHECKOUT_SUCCESS);
		$request->setStatusUrl(HTTPS_SERVER.DIR_WS_HTTPS_CATALOG.'invipay_status.php?action=statusinvipay');
	  }
	  $request->setDocumentNumber($insert_id);
	  
	  $request->setIssueDate(date('Y-m-d'));
	  $request->setDueDate(date('Y-m-d', (time() + 60 * 60 * 24 * (int)MODULE_PAYMENT_INVIPAY_PAYDATE))); 
	  $request->setPriceGross($total_cart_value);
	  $request->setCurrency($order->info['currency']);
	  $request->setBuyerGovId($nip[MODULE_PAYMENT_INVIPAY_NIP_FIELD]);
	  $request->setBuyerEmail($order->customer['email_address']);
	  //$request->setBuyerEmail('demo@invipay.com');
	  $request->setIsInvoice(false);
	  $request->setNoRisk(null);
	  $result = $client->createPayment($request);
	  $paymentId = $result->getPaymentId();
	  
	  $sql_data_array = array(
		'orders_id' => $insert_id,
		'payment_id' => $paymentId
	  );
	  tep_db_perform('orders_invipay', $sql_data_array);
	  tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_ID . "', last_modified = now() where orders_id = '" . (int)$insert_id . "'");
	  $cart->reset(true);
	  tep_session_unregister('sendto');
	  tep_session_unregister('billto');
	  tep_session_unregister('shipping');
	  tep_session_unregister('payment');
	  tep_session_unregister('comments');
	  tep_redirect($result->getRedirectUrl());
    }

    function output_error() {
      return false;
    }

    function check() {
      if (!isset($this->check)) {
        $check_query = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_INVIPAY_STATUS'");
        $this->check = tep_db_num_rows($check_query);
      }
      return $this->check;
    }

    function install() {
		global $languages_id;
		tep_db_query('ALTER TABLE orders_status CHANGE orders_status_name orders_status_name VARCHAR( 128 )');
		if(!defined(MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_NAME)){
			include_once(DIR_FS_CATALOG . 'includes/languages/polish/modules/payment/invipay.php');
		}
		$check_query = tep_db_query('select orders_status_id from orders_status where orders_status_name = "'.MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_NAME.'"');
		if(tep_db_num_rows($check_query) > 0){
			$check = tep_db_fetch_array($check_query);
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia rozpoczętej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_ID', ".$check['orders_status_id'].", 'Ustaw status zamówienia dla rozpoczętej płatności w InviPay.com', '6', '5', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		} else {
			$next_id_query = tep_db_query("select max(orders_status_id) as orders_status_id from " . TABLE_ORDERS_STATUS . "");
            $next_id = tep_db_fetch_array($next_id_query);
            $orders_status_id = $next_id['orders_status_id'] + 1;
			$sql_data_array = array('orders_status_id' => $orders_status_id,
									'language_id' => $languages_id,
									'orders_status_name' => MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_NAME,
									'public_flag' => 1,
									'downloads_flag' => 0);
			tep_db_perform('orders_status', $sql_data_array);
			$status_id = $orders_status_id;
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia rozpoczętej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_ID', ".$status_id.", 'Ustaw status zamówienia dla rozpoczętej płatności w InviPay.com', '6', '5', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		}
		
		$check_query = tep_db_query('select orders_status_id from orders_status where orders_status_name = "'.MODULE_PAYMENT_INVIPAY_OUT_OF_LIMIT_ORDER_STATUS_NAME.'"');
		if(tep_db_num_rows($check_query) > 0){
			$check = tep_db_fetch_array($check_query);
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia odrzuconej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_OUT_OF_LIMIT_ORDER_STATUS_ID', ".$check['orders_status_id'].", 'Status zamówienia dla odrzuconej płatności w InviPay.com', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		} else {
			$next_id_query = tep_db_query("select max(orders_status_id) as orders_status_id from " . TABLE_ORDERS_STATUS . "");
            $next_id = tep_db_fetch_array($next_id_query);
            $orders_status_id = $next_id['orders_status_id'] + 1;
			$sql_data_array = array('orders_status_id' => $orders_status_id,
									'language_id' => $languages_id,
									'orders_status_name' => MODULE_PAYMENT_INVIPAY_OUT_OF_LIMIT_ORDER_STATUS_NAME,
									'public_flag' => 1,
									'downloads_flag' => 0);
			tep_db_perform('orders_status', $sql_data_array);
			$status_id = $orders_status_id;
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia odrzuconej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_OUT_OF_LIMIT_ORDER_STATUS_ID', ".$status_id.", 'Status zamówienia dla odrzuconej płatności w InviPay.com', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		}
		
		$check_query = tep_db_query('select orders_status_id from orders_status where orders_status_name = "'.MODULE_PAYMENT_INVIPAY_TIMEDOUT_ORDER_STATUS_NAME.'"');
		if(tep_db_num_rows($check_query) > 0){
			$check = tep_db_fetch_array($check_query);
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia porzuconej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_TIMEDOUT_ORDER_STATUS_ID', ".$check['orders_status_id'].", 'Status zamówienia dla porzuconej płatności w InviPay.com', '6', '7', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		} else {
			$next_id_query = tep_db_query("select max(orders_status_id) as orders_status_id from " . TABLE_ORDERS_STATUS . "");
            $next_id = tep_db_fetch_array($next_id_query);
            $orders_status_id = $next_id['orders_status_id'] + 1;
			$sql_data_array = array('orders_status_id' => $orders_status_id,
									'language_id' => $languages_id,
									'orders_status_name' => MODULE_PAYMENT_INVIPAY_TIMEDOUT_ORDER_STATUS_NAME,
									'public_flag' => 1,
									'downloads_flag' => 0);
			tep_db_perform('orders_status', $sql_data_array);
			$status_id = $orders_status_id;
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia porzuconej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_TIMEDOUT_ORDER_STATUS_ID', ".$status_id.", 'Status zamówienia dla porzuconej płatności w InviPay.com', '6', '7', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		}
		
		$check_query = tep_db_query('select orders_status_id from orders_status where orders_status_name = "'.MODULE_PAYMENT_INVIPAY_COMPLETED_ORDER_STATUS_NAME.'"');
		if(tep_db_num_rows($check_query) > 0){
			$check = tep_db_fetch_array($check_query);
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia zaakceptowanej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_COMPLETED_ORDER_STATUS_ID', ".$check['orders_status_id'].", 'Status zamówienia dla zaakceptowanej płatności w InviPay.com', '6', '8', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		} else {
			$next_id_query = tep_db_query("select max(orders_status_id) as orders_status_id from " . TABLE_ORDERS_STATUS . "");
            $next_id = tep_db_fetch_array($next_id_query);
            $orders_status_id = $next_id['orders_status_id'] + 1;
			$sql_data_array = array('orders_status_id' => $orders_status_id,
									'language_id' => $languages_id,
									'orders_status_name' => MODULE_PAYMENT_INVIPAY_COMPLETED_ORDER_STATUS_NAME,
									'public_flag' => 1,
									'downloads_flag' => 0);
			tep_db_perform('orders_status', $sql_data_array);
			$status_id = $orders_status_id;
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia zaakceptowanej płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_COMPLETED_ORDER_STATUS_ID', ".$status_id.", 'Status zamówienia dla zaakceptowanej płatności InviPay.com', '6', '8', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		}
		
		$check_query = tep_db_query('select orders_status_id from orders_status where orders_status_name = "'.MODULE_PAYMENT_INVIPAY_DELIVERED_ORDER_STATUS_NAME.'"');
		if(tep_db_num_rows($check_query) > 0){
			$check = tep_db_fetch_array($check_query);
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia dla doręczonej paczki z użyciem płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_DELIVERED_ORDER_STATUS_ID', ".$check['orders_status_id'].", 'Status zamówienia dla doręczonej paczki z użyciem płatności InviPay.com', '6', '9', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		} else {
			$next_id_query = tep_db_query("select max(orders_status_id) as orders_status_id from " . TABLE_ORDERS_STATUS . "");
            $next_id = tep_db_fetch_array($next_id_query);
            $orders_status_id = $next_id['orders_status_id'] + 1;
			$sql_data_array = array('orders_status_id' => $orders_status_id,
									'language_id' => $languages_id,
									'orders_status_name' => MODULE_PAYMENT_INVIPAY_DELIVERED_ORDER_STATUS_NAME,
									'public_flag' => 1,
									'downloads_flag' => 0);
			tep_db_perform('orders_status', $sql_data_array);
			$status_id = $orders_status_id;
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia dla doręczonej paczki z użyciem płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_DELIVERED_ORDER_STATUS_ID', ".$status_id.", 'Status zamówienia dla doręczonej paczki z użyciem płatności InviPay.com', '6', '9', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		}
		
		$check_query = tep_db_query('select orders_status_id from orders_status where orders_status_name = "'.MODULE_PAYMENT_INVIPAY_FINISHED_ORDER_STATUS_NAME.'"');
		if(tep_db_num_rows($check_query) > 0){
			$check = tep_db_fetch_array($check_query);
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia dla doręczonej paczki i wystawionej faktury z użyciem płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_FINISHED_ORDER_STATUS_ID', ".$check['orders_status_id'].", 'Status zamówienia dla doręczonej paczki i wystawionej faktury  z użyciem płatności InviPay.com', '6', '10', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		} else {
			$next_id_query = tep_db_query("select max(orders_status_id) as orders_status_id from " . TABLE_ORDERS_STATUS . "");
            $next_id = tep_db_fetch_array($next_id_query);
            $orders_status_id = $next_id['orders_status_id'] + 1;
			$sql_data_array = array('orders_status_id' => $orders_status_id,
									'language_id' => $languages_id,
									'orders_status_name' => MODULE_PAYMENT_INVIPAY_FINISHED_ORDER_STATUS_NAME,
									'public_flag' => 1,
									'downloads_flag' => 0);
			tep_db_perform('orders_status', $sql_data_array);
			$status_id = $orders_status_id;
			tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Status zamówienia dla doręczonej paczki i wystawionej faktury z użyciem płatności InviPay.com', 'MODULE_PAYMENT_INVIPAY_FINISHED_ORDER_STATUS_ID', ".$status_id.", 'Status zamówienia dla doręczonej paczki i wystawionej faktury z użyciem płatności InviPay.com', '6', '10', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
		}
		
		
      tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Pokazuj płatność InviPay.com', 'MODULE_PAYMENT_INVIPAY_STATUS', '1', 'Czy chcesz włączyć płatność InviPay.com?', '6', '1','invipay_pull_down_status(', 'invipay_status', now())");
	  tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Tryb demo', 'MODULE_PAYMENT_INVIPAY_DEMO', '1', 'Czy chcesz przeprowadzić testy działania płatności InviPay.com?', '6', '2', 'invipay_pull_down_demo_status(', 'invipay_demo_status', now())");
      tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Publiczny klucz API InviPay.com', 'MODULE_PAYMENT_INVIPAY_PUBLIC_KEY', '', 'Klucz publiczny wymagany do autoryzacji transakcji', '6', '3', now());");
      tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Prywatny klucz API InviPay.com', 'MODULE_PAYMENT_INVIPAY_PRIVATE_KEY', '', 'Klucz prywatny wymagany do autoryzacji transakcji', '6', '4', now());");
	  tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Kolumna pola NIP', 'MODULE_PAYMENT_INVIPAY_NIP_FIELD', '', 'Wybierz zbazy danych kolumnę zawierającą pole NIP klienta', '6', '11', 'invipay_pull_down_nip(', now())");
      tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Termin płatności', 'MODULE_PAYMENT_INVIPAY_PAYDATE', '14', 'Termin płatności faktury (zgodnie z umową z InviPay.com) wyrażana w dniach', '6', '12', now());");
      tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Minimalna wartość koszyka', 'MODULE_PAYMENT_INVIPAY_MINCART', '200', 'Minimalna wartość koszyka wyrażona w PLN dla której płatności InviPay.com będą aktywne', '6', '13', now());");
      tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values('Kolejnosć wyswietlania.', 'MODULE_PAYMENT_INVIPAY_SORT_ORDER', '0', 'Kolejnosc wyswietlania. Najnizsze wyswietlane sa na poczatku.', '6', '14', now())");
	  
	  tep_db_query("CREATE TABLE IF NOT EXISTS orders_invipay (invipay_id int(11) NOT NULL AUTO_INCREMENT,orders_id int(11) NOT NULL,payment_id varchar(64) NOT NULL,PRIMARY KEY (invipay_id),KEY orders_id (orders_id,payment_id))");
   }

    function remove() {
      tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys() {
      $keys = array(
		'MODULE_PAYMENT_INVIPAY_STATUS',
		'MODULE_PAYMENT_INVIPAY_DEMO',
		'MODULE_PAYMENT_INVIPAY_PUBLIC_KEY',
		'MODULE_PAYMENT_INVIPAY_PRIVATE_KEY',
		'MODULE_PAYMENT_INVIPAY_STARTED_ORDER_STATUS_ID',
		'MODULE_PAYMENT_INVIPAY_OUT_OF_LIMIT_ORDER_STATUS_ID',
		'MODULE_PAYMENT_INVIPAY_TIMEDOUT_ORDER_STATUS_ID',
		'MODULE_PAYMENT_INVIPAY_COMPLETED_ORDER_STATUS_ID',
		'MODULE_PAYMENT_INVIPAY_DELIVERED_ORDER_STATUS_ID',
		'MODULE_PAYMENT_INVIPAY_FINISHED_ORDER_STATUS_ID',
		'MODULE_PAYMENT_INVIPAY_NIP_FIELD',
		'MODULE_PAYMENT_INVIPAY_PAYDATE',
		'MODULE_PAYMENT_INVIPAY_MINCART',
		'MODULE_PAYMENT_INVIPAY_SORT_ORDER'
	  );

      return $keys;
    }
  }

function invipay_pull_down_status($status, $key = '') {
    $name = (($key) ? 'configuration[' . $key . ']' : 'configuration_value');
    $statuses_array = array(
		array('id' => '0', 'text' => 'Nie'),
		array('id' => '1', 'text' => 'Tak')
	);
	return tep_draw_pull_down_menu($name, $statuses_array, $status);
}

function invipay_status($status, $language_id = '') {
	$status_name = array();
	$status_name[0] = 'Nie';
	$status_name[1] = 'Tak';
	return $status_name[$status];
}
  
function invipay_pull_down_demo_status($status, $key = '') {
    $name = (($key) ? 'configuration[' . $key . ']' : 'configuration_value');
    $statuses_array = array(
		array('id' => '0', 'text' => 'Nie'),
		array('id' => '1', 'text' => 'Tak')
	);
	return tep_draw_pull_down_menu($name, $statuses_array, $status);
}

function invipay_demo_status($status, $language_id = '') {
	$status_name = array();
	$status_name[0] = 'Nie';
	$status_name[1] = 'Tak';
	return $status_name[$status];
}

function invipay_pull_down_nip($field, $key = '') {
	$name = (($key) ? 'configuration[' . $key . ']' : 'configuration_value');
	$fields_array = array();
	$fields_array[] = array('id' => '', 'text' => '-wybierz-');
	$fields_query = tep_db_query('SHOW COLUMNS FROM '.TABLE_ADDRESS_BOOK);
	while ($fields = tep_db_fetch_array($fields_query)) {
        $fields_array[] = array('id' => $fields['Field'], 'text' => $fields['Field']);
    }
	return tep_draw_pull_down_menu($name, $fields_array, $field);
}

if(false === function_exists('lcfirst'))
{
    function lcfirst( $str ) {
        $str[0] = strtolower($str[0]);
        return (string)$str;
    }
}
?>