<?php

/**
 * @see http://www.eway.com.au/developers/api/shared-payments
 * @see http://www.eway.com.au/docs/api-documentation/sharedpaymentpagedoc.pdf
 * http://www.eway.com.au/developers/resources/response-codes
 *
 *
 * test CC:
 * 4444333322221111
 * any expiry, name, etc...
 *
 */
class EwayPayment extends EcommercePayment {

	private static $db = array(
		'AuthorisationCode' => 'Text'
	);

	// Eway Information

	private static $privacy_link = 'https://www.eway.com.au/Company/About/Privacy.aspx';

	private static $logo = '/payment_eway/images/eway.png';

	// Company Information

	private static $page_title = 'Your Title';

	private static $company_name = 'Your Company Name';

	private static $payment_explanation = 'Your payment will be processed by the eWay payment processing site.';

	/**
	 * e.g. /themes/mytheme/images/myimage.png
	 * make sure the location is SSL if you add it
	 * @var String
	 */
	private static $company_logo = 'Your company Logo file location';

	// URLs

	private static $url = 'https://au.ewaygateway.com/Request';


	private static $confirmation_url = 'https://au.ewaygateway.com/Result';

	// Test Mode

	private static $test_customer_id = '87654321';

	private static $test_customer_username = 'TestAccount';

	/**
	 * NB: this is a string... anything will divert to LIVE
	 * unless it is set to "yes"
	 * @var String
	 */
	private static $test_mode = 'no';

	// Account Information

	private static $customer_id;

	private static $customer_username;

	// Credit Cards

	private static $credit_cards = array(
		//'Visa' => 'payment/images/payments/methods/visa.jpg',
		//'MasterCard' => 'payment/images/payments/methods/mastercard.jpg',
		//'American Express' => 'payment/images/payments/methods/american-express.gif',
		//'Dinners Club' => 'payment/images/payments/methods/dinners-club.jpg',
		//'JCB' => 'payment/images/payments/methods/jcb.jpg'
	);


	protected $testCodes = array(
"0" => " --- SELECT RESPONSE TYPE ---",
"00" => "Transaction Approved approved",
"01" => "Refer to Issuer",
"02" => "Refer to Issuer, special",
"03" => "No Merchant",
"04" => "Pick Up Card",
"05" => "Do Not Honour",
"06" => "Error",
"07" => "Pick Up Card, Special",
"08" => "Honour With Identification approved",
"09" => "Request In Progress",
"10" => "Approved For Partial Amount approved",
"11" => "Approved, VIP approved",
"12" => "Invalid Transaction",
"13" => "Invalid Amount",
"14" => "Invalid Card Number",
"15" => "No Issuer",
"16" => "Approved, Update Track 3 approved",
"19" => "Re-enter Last Transaction",
"21" => "No Action Taken",
"22" => "Suspected Malfunction",
"23" => "Unacceptable Transaction Fee",
"25" => "Unable to Locate Record On File",
"30" => "Format Error",
"31" => "Bank Not Supported By Switch",
"33" => "Expired Card, Capture",
"34" => "Suspected Fraud, Retain Card",
"35" => "Card Acceptor, Contact Acquirer, Retain Card",
"36" => "Restricted Card, Retain Card",
"37" => "Contact Acquirer Security Department, Retain Card",
"38" => "PIN Tries Exceeded, Capture",
"39" => "No Credit Account",
"40" => "Function Not Supported",
"41" => "Lost Card",
"42" => "No Universal Account",
"43" => "Stolen Card",
"44" => "No Investment Account",
"51" => "Insufficient Funds",
"52" => "No Cheque Account",
"53" => "No Savings Account",
"54" => "Expired Card",
"55" => "Incorrect PIN",
"56" => "No Card Record",
"57" => "Function Not Permitted to Cardholder",
"58" => "Function Not Permitted to Terminal",
"59" => "Suspected Fraud",
"60" => "Acceptor Contact Acquirer",
"61" => "Exceeds Withdrawal Limit",
"62" => "Restricted Card",
"63" => "Security Violation",
"64" => "Original Amount Incorrect",
"66" => "Acceptor Contact Acquirer, Security",
"67" => "Capture Card",
"75" => "PIN Tries Exceeded",
"82" => "CVV Validation Error",
"90" => "Cutoff In Progress",
"91" => "Card Issuer Unavailable",
"92" => "Unable To Route Transaction",
"93" => "Cannot Complete, Violation Of The Law",
"94" => "Duplicate Transaction",
"96" => "System Error"
 );

	function getPaymentFormFields() {
		$logo = '<img src="' . $this->config()->get('logo') . '" alt="Credit card payments powered by eWAY"/>';
		$privacyLink = '<a href="' . $this->config()->get('privacy_link') . '" target="_blank" title="Read eWAY\'s privacy policy">' . $logo . '</a>';
		$paymentsList = '';
		if($cards = $this->config()->get('credit_cards')) {
			foreach($cards as $name => $image) {
				$paymentsList .= '<img src="' . $image . '" alt="' . $name . '"/>';
			}
		}
		$paymentsList .= "<p>".$this->Config()->get("payment_explanation")."</p>";
		$fields = new FieldList(
			new LiteralField('EwayInfo', $privacyLink),
			new LiteralField('EwayPaymentsList', $paymentsList)
		);
		if(Director::isDev()) {
			$fields->push(new DropdownField("PaymentTypeTest", "Required outcome", $this->testCodes));
		}
		return $fields;
	}

	function getPaymentFormRequirements() {return null;}

	function processPayment($data, $form) {

		// 1) Get secured Eway url

		$url = $this->EwayURL();
		$response = file_get_contents($url);
		if($response) {
			$response = Convert::xml2array($response);
			if(isset($response['Result']) && $response['Result'] == 'True' && isset($response['URI']) && $response['URI']) {

				// 2) Redirect to the secured Eway url

				$page = new Page();

				$page->Title = 'Redirection to eWAY...';
				$page->Logo = '<img src="' . $this->config()->get('logo') . '" alt="Payments powered by eWAY"/>';
				$page->Form = $this->EwayForm($response['URI']);

				$controller = new Page_Controller($page);

				$form = $controller->renderWith('PaymentProcessingPage');

				return EcommercePayment_Processing::create($form);
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

		if($this->config()->get('test_mode') == 'yes') {
			$inputs['CustomerID'] = $this->config()->get('test_customer_id');
			$inputs['UserName'] = $this->config()->get('test_customer_username');
		}
		else {
			$inputs['CustomerID'] = $this->config()->get('customer_id');
			$inputs['UserName'] = $this->config()->get('customer_username');
		}
		if($this->config()->get('test_mode') == 'yes' && isset($_REQUEST["PaymentTypeTest"])) {
			$amount = round($this->Amount->getAmount())+(intval($_REQUEST["PaymentTypeTest"])/100);
		}
		else {
			$amount = $this->Amount->getAmount();
		}
		$inputs['Amount'] = number_format($amount, 2, '.' , ''); //$decimals = 2, $decPoint = '.' , $thousands_sep = ''
		$inputs['Currency'] = $this->Amount->getCurrency();
		$inputs['ReturnURL'] = $inputs['CancelURL'] = Director::absoluteBaseURL() . EwayPayment_Handler::complete_link($this);

		$inputs['CompanyName'] = $this->config()->get('company_name');
		$inputs['MerchantReference'] = $inputs['MerchantInvoice'] = $order->ID;
		//$inputs['InvoiceDescription'] =
		$inputs['PageTitle'] = $this->config()->get('page_title');
		$inputs['PageDescription'] = 'Please fill the details below to complete your order.';
		if($logo = $this->config()->get('company_logo')) {
			$inputs['CompanyLogo'] = Director::absoluteBaseURL() . $logo;
		}

		// 7) Prepopulating Customer Informations

		$address = $this->Order()->BillingAddress();

		$inputs['CustomerFirstName'] = $address->FirstName;
		$inputs['CustomerLastName'] = $address->Surname;
		$inputs['CustomerAddress'] = "$address->Address $address->Address2";
		$inputs['CustomerPostCode'] = $address->PostalCode;
		$inputs['CustomerCity'] = $address->City;
		$inputs['CustomerCountry'] = (class_exists("Geoip") ? Geoip::countryCode2name($address->Country) : $address->Country);
		$inputs['CustomerPhone'] = $address->Phone;
		$inputs['CustomerEmail'] = $address->Email;
		$inputs['CustomerState'] = $address->RegionCode;
		if($this->config()->get('test_mode') == 'yes') {
			$inputs['CompanyName'] = "TEST FOR ".$inputs['CompanyName'];
			debug::log(print_r($inputs, 1));
			debug::log($this->config()->get('url'));
		}
		return $this->config()->get('url') . '?' . http_build_query($inputs);
	}

	function EwayForm($url) {
		Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
		return <<<HTML
			<form id="EwayForm" method="post" action="$url">
				<input type="submit" value="Pay Now" />
				<p>Continue through to payment page - if this does not happen automatically then please click above.</p>
			</form>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					window.setTimeout(
						function(){
							jQuery("input[type='submit']").hide();
							jQuery('#EwayForm').submit();
						},
						200
					);
				});
			</script>
HTML;
	}

	function EwayConfirmationURL($code) {
		$inputs = array('AccessPaymentCode' => $code);
		if($this->config()->get('test_mode') == 'yes') {
			$inputs['CustomerID'] = $this->config()->get('test_customer_id');
			$inputs['UserName'] = $this->config()->get('test_customer_username');
		}
		else {
			$inputs['CustomerID'] = $this->config()->get('customer_id');
			$inputs['UserName'] = $this->config()->get('customer_username');
		}
		return $this->config()->get('confirmation_url') . '?' . http_build_query($inputs);
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

	private static $allowed_actions = array(
		"complete"
	);

	private static $url_segment = 'ewaypayment_handler';

	public static function complete_link(EwayPayment $payment) {
		return Config::inst()->get('EwayPayment_Handler', 'url_segment') . "/complete?code={$payment->ID}-{$payment->AuthorisationCode}";
	}

	/**
	 * Manages the 'return' and 'cancel' replies
	 */
	function complete() {
		$this->extend("EwayPayment_Handler_completion_start");
		if(isset($_REQUEST['code']) && $code = $_REQUEST['code']) {
			$params = explode('-', $code);
			if(count($params) == 2) {
				$payment = EwayPayment::get()->byID(intval($params[0]));
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
