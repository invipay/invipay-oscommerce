<?php
if(isset($_GET['action']) && $_GET['action'] == 'statusanvipay'){
ini_set('track_errors', '1');
ini_set('display_errors', true);

$outputPath = dirname(__FILE__).'/log.txt';
file_put_contents($outputPath, date('Y-m-d H:i:s'), FILE_APPEND);

$admin_dir = 'admin/';
define('CHARSET', 'utf-8');

	error_reporting(E_ALL & ~E_NOTICE);
	include('includes/configure.php');
	include('includes/functions/compatibility.php');
	$request_type = (getenv('HTTPS') == 'on') ? 'SSL' : 'NONSSL';
	$req = parse_url($_SERVER['SCRIPT_NAME']);
	$PHP_SELF = substr($req['path'], ($request_type == 'NONSSL') ? strlen(DIR_WS_HTTP_CATALOG) : strlen(DIR_WS_HTTPS_CATALOG)); 
	if ($request_type == 'NONSSL') {
		define('DIR_WS_CATALOG', DIR_WS_HTTP_CATALOG);
	} else {
		define('DIR_WS_CATALOG', DIR_WS_HTTPS_CATALOG);
	}
	include(DIR_WS_INCLUDES . 'database_tables.php');
	include(DIR_WS_INCLUDES . 'filenames.php');
	include(DIR_WS_FUNCTIONS . 'database.php');
	tep_db_connect() or die('Unable to connect to database server!');
	$configuration_query = tep_db_query('select configuration_key as cfgKey, configuration_value as cfgValue from ' . TABLE_CONFIGURATION);
	while ($configuration = tep_db_fetch_array($configuration_query)) {
		define($configuration['cfgKey'], $configuration['cfgValue']);
	}
	include(DIR_WS_FUNCTIONS . 'general.php');
	
	
	include_once(DIR_FS_CATALOG ."ext/invipay/PaygateApiClient.class.php");
	if(MODULE_PAYMENT_INVIPAY_DEMO == '0'){
		$api_url = 'https://api.invipay.com/api/rest';
	} else {
		$api_url = 'http://demo.invipay.com/services/api/rest';
	}
	$client = new PaygateApiClient($api_url, MODULE_PAYMENT_INVIPAY_PUBLIC_KEY, MODULE_PAYMENT_INVIPAY_PRIVATE_KEY); 
	$paymentData = $client->paymentStatusFromCallbackPost(CallbackDataFormat::JSON); 
	
		$paymentId = $paymentData->getPaymentId(); 
	
		$statusData = $client->getPayment($paymentId);
		$paymentStatus = $statusData->getStatus();
	
	$languages_query = tep_db_query('select languages_id, directory from '.TABLE_LANGUAGES.' where code = "PL" or code = "pl"');
	$languages = tep_db_fetch_array($languages_query);
	$languages_id = $languages['languages_id'];
	
	$order_query = tep_db_query('select orders_id from orders_invipay where payment_id = "'.$paymentId.'"');
	$order = tep_db_fetch_array($order_query);
	$check_status_query = tep_db_query("select customers_name, customers_email_address, orders_status, date_purchased from " . TABLE_ORDERS . " where orders_id = '" . (int)$order['orders_id'] . "'");
	$check_status = tep_db_fetch_array($check_status_query);
	$orders_status_array = array();
	$orders_status_query = tep_db_query("select orders_status_id, orders_status_name from " . TABLE_ORDERS_STATUS . " where language_id = '" . (int)$languages_id . "'");
	while ($orders_status = tep_db_fetch_array($orders_status_query)) {
		$orders_status_array[$orders_status['orders_status_id']] = $orders_status['orders_status_name'];
	}
	switch($paymentStatus){
		case 'CANCELED':
		case 'OUT_OF_LIMIT':
			$status = MODULE_PAYMENT_INVIPAY_OUT_OF_LIMIT_ORDER_STATUS_ID;
		break;
		case 'TIMEDOUT':
			$status = MODULE_PAYMENT_INVIPAY_TIMEDOUT_ORDER_STATUS_ID;
		break;
		case 'COMPLETED':
			$status = MODULE_PAYMENT_INVIPAY_COMPLETED_ORDER_STATUS_ID;
		break;
	}
	tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . tep_db_input($status) . "', last_modified = now() where orders_id = '" . (int)$order['orders_id'] . "'");

	$comments = '';
	$customer_notified = '0';
	include($admin_dir.DIR_WS_LANGUAGES.$languages['directory'].'/orders.php');
	$email = STORE_NAME . "\n" . EMAIL_SEPARATOR . "\n" . EMAIL_TEXT_ORDER_NUMBER . ' ' . (int)$order['orders_id'] . "\n\n" . sprintf(EMAIL_TEXT_STATUS_UPDATE, $orders_status_array[$status]);
	include(DIR_WS_CLASSES . 'mime.php');
	include(DIR_WS_CLASSES . 'email.php');
	tep_mail($check_status['customers_name'], $check_status['customers_email_address'], EMAIL_TEXT_SUBJECT, $email, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);

	$customer_notified = '1';
	tep_db_query("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('" . (int)$order['orders_id'] . "', '" . tep_db_input($status) . "', now(), '" . tep_db_input($customer_notified) . "', '" . tep_db_input($comments)  . "')");

}
?>