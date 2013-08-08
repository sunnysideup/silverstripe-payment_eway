<?php

/**
 * @see http://www.eway.com.au/developers/api/shared-payments
 * @see http://www.eway.com.au/docs/api-documentation/sharedpaymentpagedoc.pdf
 */
class EwayPayment extends EcommercePayment {

	private static $db = array(
		'AuthorisationCode' => 'Text'
	);

	// Eway Information

	private static $privacy_link = 'http://www.eway.com.au/Company/About/Privacy.aspx';

	private static $logo = 'payment/images/payments/eway.gif';

	// Company Information

	private static $page_title = 'http://www.eway.com.au/Company/About/Privacy.aspx';

	private static $company_name = 'payment_eway/images/payments/eway.gif';

	private static $company_logo = 'themes/mythemes/images/logo.png';

	// URLs

	private static $url = 'https://au.ewaygateway.com/Request';
	private static $confirmation_url = 'https://au.ewaygateway.com/Result';

	// Test Mode

	private static $test_customer_id = '87654321';
	private static $test_customer_username = 'TestAccount';

	private static $test_mode = true;

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

	function getPaymentFormFields() {
		$logo = '<img src="' . $this->config()->get('logo') . '" alt="Credit card payments powered by eWAY"/>';
		$privacyLink = '<a href="' . $this->config()->get('privacy_link') . '" target="_blank" title="Read eWAY\'s privacy policy">' . $logo . '</a><br/>';
		$paymentsList = '';
		if($this->config()->get('credit_cards')) {
			foreach($this->config()->get('credit_cards') as $name => $image) {
				$paymentsList .= '<img src="' . $image . '" alt="' . $name . '"/>';
			}
		}
		$fields = new FieldList(
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

		if($this->config()->get('test_mode')) {
			$inputs['CustomerID'] = $this->config()->get('test_customer_id');
			$inputs['UserName'] = $this->config()->get('test_customer_username');
		}
		else {
			$inputs['CustomerID'] = $this->config()->get('customer_id');
			$inputs['UserName'] = $this->config()->get('customer_username');
		}
		$inputs['Amount'] = number_format($this->Amount->getAmount(), 2);
		$inputs['Currency'] = $this->Amount->getCurrency();
		$inputs['ReturnURL'] = $inputs['CancelURL'] = Director::absoluteBaseURL() . EwayPayment_Handler::complete_link($this);

		$inputs['CompanyName'] = $this->config()->get('company_name');
		$inputs['MerchantReference'] = $inputs['MerchantInvoice'] = $order->ID;
		//$inputs['InvoiceDescription'] =
		$inputs['PageTitle'] = $this->config()->get('page_title');
		$inputs['PageDescription'] = 'Please fill the details below to complete your order.';
		$inputs['CompanyLogo'] = Director::absoluteBaseURL() . $this->config()->get('company_logo');

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

		return $this->config()->get('url') . '?' . http_build_query($inputs);
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
		if($this->config()->get('test_mode')) {
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

	static function complete_link(EwayPayment $payment) {
		return $this->config()->get('url_segment') . "/complete?code={$payment->ID}-{$payment->AuthorisationCode}";
	}

	/**
	 * Manages the 'return' and 'cancel' PayPal replies
	 */
	function complete() {
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
