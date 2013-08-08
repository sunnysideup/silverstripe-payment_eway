<?php

/**
 * @see http://www.eway.com.au/developers/api/shared-payments
 * @see http://www.eway.com.au/docs/api-documentation/sharedpaymentpagedoc.pdf
 */
class EwayPayment extends Payment {

	static $db = array(
		'AuthorisationCode' => 'Text'
	);

	// Eway Information

	protected static $privacy_link = 'http://www.eway.com.au/Company/About/Privacy.aspx';

	protected static $logo = 'payment_eway/images/payments/eway.gif';

	// Company Information

	protected static $page_title = 'http://www.eway.com.au/Company/About/Privacy.aspx';

	protected static $company_name = 'payment_eway/images/payments/eway.gif';

	protected static $company_logo = 'themes/mythemes/images/logo.png';

	// URLs

	protected static $url = 'https://au.ewaygateway.com/Request';

	protected static $confirmation_url = 'https://au.ewaygateway.com/Result';

	// Test Mode

	protected static $test_customer_id = '87654321';

	protected static $test_customer_username = 'TestAccount';

	protected static $test_mode = false;

	static function set_test_mode() {self::$test_mode = true;}

	// Payment Informations

	protected static $customer_id;

	protected static $customer_username;

	static function set_customer_details($id, $userName) {
		self::$customer_id = $id;
		self::$customer_username = $userName;
	}

	// Credit Cards

	protected static $credit_cards = array(
		'Visa' => 'payment/images/payments/methods/visa.jpg',
		'MasterCard' => 'payment/images/payments/methods/mastercard.jpg',
		'American Express' => 'payment/images/payments/methods/american-express.gif',
		'Dinners Club' => 'payment/images/payments/methods/dinners-club.jpg',
		'JCB' => 'payment/images/payments/methods/jcb.jpg'
	);

	static function remove_credit_card($creditCard) {unset(self::$credit_cards[$creditCard]);}

	// PayPal Pages Style Optional Informations

	function getPaymentFormFields() {
		$logo = '<img src="' . self::$logo . '" alt="Credit card payments powered by eWAY"/>';
		$privacyLink = '<a href="' . self::$privacy_link . '" target="_blank" title="Read eWAY\'s privacy policy">' . $logo . '</a><br/>';
		$paymentsList = '';
		if(self::$credit_cards) {
			foreach(self::$credit_cards as $name => $image) {
				$paymentsList .= '<img src="' . $image . '" alt="' . $name . '"/>';
			}
		}
		$fields = new FieldSet(
			new LiteralField('EwayInfo', $privacyLink),
			new LiteralField('EwayPaymentsList', $paymentsList)
		);
		return $fields;
	}

	function getPaymentFormRequirements() {return null;}

	function processPayment($data, $form) {

		// 1) Get secured Eway url

		$url = $this->EwayURL();

		$response = file_get_contents($url);
		if(!Director::isLive()) {
			Debug::log($url);
			Debug::log($response);
		}
		if($response) {
			$response = Convert::xml2array($response);
			if(isset($response['Result']) && $response['Result'] == 'True' && isset($response['URI']) && $response['URI']) {

				// 2) Redirect to the secured Eway url

				$page = new Page();

				$page->Title = 'Redirection to eWAY...';
				$page->Logo = '<img src="' . self::$logo . '" alt="Payments powered by eWAY"/>';
				$page->Form = $this->EwayForm($response['URI']);

				$controller = new Page_Controller($page);

				$form = $controller->renderWith('PaymentProcessingPage');

				return new Payment_Processing($form);
			}
		}

		$this->Status = 'Failure';
		if($response && isset($response['Error'])) {
			$this->Message = $response['Error'];
		}

		$this->write();

		return $this->redirectToOrder();
	}

	function EwayURL() {

		// 1) Main Informations

		$order = $this->Order();
		//$items = $order->Items();
		$member = $order->Member();

		// 2) Main Settings

		if(self::$test_mode) {
			$inputs['CustomerID'] = self::$test_customer_id;
			$inputs['UserName'] = self::$test_customer_username;
		}
		else {
			$inputs['CustomerID'] = self::$customer_id;
			$inputs['UserName'] = self::$customer_username;
		}
		$inputs['Amount'] = number_format($this->Amount->getAmount(), 2, '.' , ''); //$decimals = 2, $decPoint = '.' , $thousands_sep = ''
		$inputs['Currency'] = $this->Amount->getCurrency();
		$inputs['ReturnURL'] = $inputs['CancelURL'] = Director::absoluteBaseURL() . EwayPayment_Handler::complete_link($this);

		$inputs['CompanyName'] = self::$company_name;
		$inputs['MerchantReference'] = $inputs['MerchantInvoice'] = $order->ID;
		//$inputs['InvoiceDescription'] =
		$inputs['PageTitle'] = self::$page_title;
		$inputs['PageDescription'] = 'Please fill the details below to complete your order.';
		$inputs['CompanyLogo'] = Director::absoluteBaseURL() . self::$company_logo;

		// 7) Prepopulating Customer Informations

		$address = $this->Order()->BillingAddress();

		$inputs['CustomerFirstName'] = $address->FirstName;
		$inputs['CustomerLastName'] = $address->Surname;
		$inputs['CustomerAddress'] = "$address->Address $address->Address2";
		$inputs['CustomerPostCode'] = $address->PostalCode;
		$inputs['CustomerCity'] = $address->City;
		$inputs['CustomerCountry'] = Geoip::countryCode2name($address->Country);
		$inputs['CustomerPhone'] = $address->Phone;
		$inputs['CustomerEmail'] = $address->Email;
		$inputs['CustomerState'] = $address->RegionCode;

		return self::$url . '?' . http_build_query($inputs);
	}

	function EwayForm($url) {
		Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
		return <<<HTML
			<form id="EwayForm" method="post" action="$url">
				<input type="submit" value="Submit" />
			</form>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery("input[type='submit']").hide();
					jQuery('#EwayForm').submit();
				});
			</script>
HTML;
	}

	function EwayConfirmationURL($code) {
		$inputs = array('AccessPaymentCode' => $code);
		if(self::$test_mode) {
			$inputs['CustomerID'] = self::$test_customer_id;
			$inputs['UserName'] = self::$test_customer_username;
		}
		else {
			$inputs['CustomerID'] = self::$customer_id;
			$inputs['UserName'] = self::$customer_username;
		}
		return self::$confirmation_url . '?' . http_build_query($inputs);
	}

	function populateDefaults() {
		parent::populateDefaults();
		$this->AuthorisationCode = md5(uniqid(rand(), true));
 	}
}

/**
 * Handler for responses from the Eway site
 */
class EwayPayment_Handler extends Controller {

	static $URLSegment = 'eway';

	static function complete_link(EwayPayment $payment) {
		return self::$URLSegment . "/complete?code={$payment->ID}-{$payment->AuthorisationCode}";
	}

	/**
	 * Manages the 'return' and 'cancel' PayPal replies
	 */
	function complete() {
		if(isset($_REQUEST['code']) && $code = $_REQUEST['code']) {
			$params = explode('-', $code);
			if(count($params) == 2) {
				$payment = DataObject::get_by_id('EwayPayment', $params[0]);
				if($payment && $payment->AuthorisationCode == $params[1]) {
					if(isset($_REQUEST['AccessPaymentCode'])) {
						$url = $payment->EwayConfirmationURL($_REQUEST['AccessPaymentCode']);
						$response = file_get_contents($url);
						if($response) {
							$response = Convert::xml2array($response);
							if(isset($response['ResponseCode']) && $response['ResponseCode'] == '00') {
								$payment->Status = 'Success';
							}
							else {
								$payment->Status = 'Failure';
							}
						}
						else {
							$payment->Status = 'Failure';
						}
						$payment->write();
						$payment->redirectToOrder();
					}
				}
			}
		}
	}
}
