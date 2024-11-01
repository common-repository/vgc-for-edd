<?php
/*
    Plugin Name:			Very Good Collection Payment Gateway for Easy Digital Downloads
	Plugin URI: 			http://verygoodcollection.com/
	Description:            Very Good Collection payment gateway for Easy Digital Downloads
	Version:                1.0
	Author: 				Very Good Collection
	License:        		GPL-2.0+
	License URI:    		http://www.gnu.org/licenses/gpl-2.0.txt
*/
 

// Very Good Collection Remove CC Form
add_action( 'edd_vgc_cc_form', '__return_false' );

// Registers the gateway
function waf_vgcedd_register_gateway($gateways) {
	$gateways['vgc'] = array('admin_label' => 'Very Good Collection', 'checkout_label' => __('Very Good Collection', 'waf_vgcedd'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'waf_vgcedd_register_gateway');

// Add Payment Gateway section
function waf_vgcedd_register_gateway_section( $gateway_sections ) {
	$gateway_sections['vgc'] = __( 'Very Good Collection', 'waf_vgcedd' );
	return $gateway_sections;
}
add_filter( 'edd_settings_sections_gateways', 'waf_vgcedd_register_gateway_section', 1, 1 );

// Get currently supported currencies from very good collection endpoint
function waf_vgcedd_get_supported_currencies($string = false){
	$currency_request = wp_remote_get("https://verygoodcollection.com/api/currency-supported2");
	$currency_array = array();
	if ( ! is_wp_error( $currency_request ) && 200 == wp_remote_retrieve_response_code( $currency_request ) ){
		$currencies = json_decode(wp_remote_retrieve_body($currency_request));
		if($currencies->currency_code && $currencies->currency_name){
			foreach ($currencies->currency_code as $index => $item){
				if($string === true){
					$currency_array[] = $currencies->currency_name[$index];
				}else{
					$currency_array[$currencies->currency_code[$index]] = $currencies->currency_name[$index];
				}
			}
		}
	}
	if($string === true){
		return implode(", ", $currency_array);
	}
	return $currency_array;
}

// Add the settings to the Payment Gateway section
function waf_vgcedd_register_gateway_settings($gateway_settings) {
    $vgc_settings = array (
        'vgc_settings' => array(
            'id'   => 'vgc_settings',
            'name' => '<strong>' . __( 'Very Good Collection Settings', 'waf_vgcedd' ) . '</strong>',
            'type' => 'header',
        ),
		'vgc_currency_supported' => array(
			'id'   => 'vgc_currency_supported',
			'name' => __( 'Our Supported Currencies', 'waf_vgcedd' ),
			'desc' => waf_vgcedd_get_supported_currencies(true),
			'type' => 'descriptive_text',
		),
        'vgc_invoice_prefix' => array(
            'id'    => 'vgc_invoice_prefix',
            'name'  => __( 'Invoice Prefix', 'waf_vgcedd' ),
            'type'  => 'text',
            'desc'  => __( 'Please enter a prefix for your invoice numbers. If you use your Very Good Collection account for multiple stores ensure this prefix is unique as Very Good Collection will not allow orders with the same invoice number.', 'waf_vgc' ),
            'default'   => 'EDD_',
            'desc_tip'  => false,
            'size' => 'regular',
        ),
        'vgc_merchant_key' => array(
            'id'    => 'vgc_merchant_key',
            'name'       => __( 'Merchant Key', 'waf_vgcedd' ),
            'type'        => 'text',
            'desc' => __( 'Required: Enter your Merchant Key here. You can get your Public Key from <a href="https://verygoodcollection.com/user/merchant">here</a>', 'waf_vgc' ),
            'default'     => '',
            'desc_tip'    => false,
            'size' => 'regular',
        ),
        'vgc_public_key' => array(
            'id'    => 'vgc_public_key',
            'name'       => __( 'Public Key', 'waf_vgcedd' ),
            'type'        => 'text',
            'desc' => __( 'Required: Enter your Public Key here. You can get your Public Key from <a href="https://verygoodcollection.com/user/api">here</a>', 'waf_vgc' ),
            'default'     => '',
            'desc_tip'    => false,
            'size'  => 'regular',
        ),
        'vgc_secret_key' => array(
            'id'    => 'vgc_secret_key',
            'name'       => __( 'Secret Key', 'waf_vgcedd' ),
            'type'        => 'text',
            'desc' => __( 'Required: Enter your Secret Key here. You can get your Secret Key from <a href="https://verygoodcollection.com/user/api">here</a>', 'waf_vgc' ),
            'default'     => '',
            'desc_tip'    => false,
            'size'  => 'regular',
        )
    );

    $vgc_settings            = apply_filters( 'edd_vgc_settings', $vgc_settings );
    $gateway_settings['vgc'] = $vgc_settings;

    return $gateway_settings;
}
add_filter( 'edd_settings_gateways', 'waf_vgcedd_register_gateway_settings', 1, 1 );

// Processes the payment
function waf_vgcedd_process_payment($purchase_data) {

    if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
		wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
	}

	// Get VGC settings 
	$merchant_key = edd_get_option( 'vgc_merchant_key' );
	$public_key = edd_get_option( 'vgc_public_key' );
	$secret_key = edd_get_option( 'vgc_secret_key' );
    $invoice_prefix = edd_get_option( 'vgc_invoice_prefix' );
	$payment_mode = $purchase_data['post_data']['edd-gateway'];

	// Collect payment data
	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => edd_get_currency(),
		'downloads'    => $purchase_data['downloads'],
		'user_info'    => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'status'       => ! empty( $purchase_data['buy_now'] ) ? 'private' : 'pending'
	);

	// Record the pending payment
	$payment = edd_insert_payment( $payment_data );

	if ($payment) {

		// EDD()->session->set( 'edd_resume_payment', $payment );

		// Empty the shopping cart
		// edd_empty_cart();

		// Setup Very Good Collection arguments
		$currency = edd_get_currency();
        $currency_array = waf_vgcedd_get_supported_currencies();
        $currency_code = array_search($currency, $currency_array);
        $tx_ref = $invoice_prefix . $payment;
        $amount = $purchase_data['price'];
        $email = $purchase_data['user_email'];
        $callback_url = get_site_url() . "/wp-json/wafvgc/v1/process-success?payment_id=" . $payment . "&invoice_prefix=" . $invoice_prefix . "&secret_key=" . $secret_key . "&payment_mode=" . $payment_mode;
        $first_name = $purchase_data['user_info']['first_name'];
        $last_name = $purchase_data['user_info']['last_name'];
        $title = "Payment For Items on " . get_bloginfo('name');

		// Validate data before send payment VGC request
		$invalid = 0;
		$error_msg = array();
        if (!empty($merchant_key) && !empty($public_key) && wp_http_validate_url($callback_url)) {
            $merchant_key = sanitize_text_field($merchant_key);
            $public_key = sanitize_text_field($public_key);
            $callback_url = esc_url($callback_url);
        } else {
			array_push($error_msg, 'The payment setting of this website is not correct, please contact Administrator');
            $invalid++;
        }
        if (!empty($tx_ref)) {
            $tx_ref = sanitize_text_field($tx_ref);
        } else {
			array_push($error_msg, 'It seems that something is wrong with your order. Please try again');
            $invalid++;
        }
        if (!empty($amount) && is_numeric($amount)) {
            $amount = floatval(sanitize_text_field($amount));
        } else {
			array_push($error_msg, 'It seems that you have submitted an invalid price for this order. Please try again');
            $invalid++;
        }
        if (!empty($email) && is_email($email)) {
            $email = sanitize_email($email);
        } else {
			array_push($error_msg, 'Your email is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($first_name)) {
            $first_name = sanitize_text_field($first_name);
        } else {
			array_push($error_msg, 'Your first name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($last_name)) {
            $last_name = sanitize_text_field($last_name);
        } else {
			array_push($error_msg, 'Your last name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($title)) {
            $title = sanitize_text_field($title);
        } else {
			array_push($error_msg, 'The order title is empty or not valid. Please check and try again');
            $invalid++;
        }
        if (!empty($currency_code) && is_numeric($currency_code)) {
            $currency = sanitize_text_field($currency_code);
        } else {
			array_push($error_msg, 'The currency code is not valid. Please check and try again');
            $invalid++;
        }

		if ($invalid === 0) { ?>
			<!DOCTYPE html>
			<html>
			<head>
				<title>Very Good Collection Secure Verification</title>
				<script language="Javascript">
					window.onload = function(){
						document.forms['waf_vgc_payment_post_form'].submit();
					}
				</script>
			</head>
			<body>
				<div>
				</div>
				<h3>We are redirecting to Very Good Collection, please wait ...</h3>
				<form id="waf_vgc_payment_post_form" name="waf_vgc_payment_post_form" method="POST" action="https://verygoodcollection.com/ext_transfer" >
					<input type="hidden" name="merchant_key" value="<?php esc_attr_e($merchant_key); ?>" />
					<input type="hidden" name="public_key" value="<?php esc_attr_e($public_key);  ?>" />
					<input type="hidden" name="callback_url" value="<?php echo esc_url($callback_url);  ?>" />
					<input type="hidden" name="return_url" value="<?php echo esc_url($callback_url);  ?>" />
					<input type="hidden" name="tx_ref" value="<?php esc_attr_e($tx_ref);  ?>" />
					<input type="hidden" name="amount" value="<?php esc_attr_e($amount);  ?>" />
					<input type="hidden" name="email" value="<?php esc_attr_e($email); ?>" />
					<input type="hidden" name="first_name" value="<?php esc_attr_e($first_name); ?>" />
					<input type="hidden" name="last_name" value="<?php esc_attr_e($last_name); ?>" />
					<input type="hidden" name="title" value="<?php esc_attr_e($title); ?>" />
					<input type="hidden" name="description" value="<?php esc_attr_e($title); ?>" />
					<input type="hidden" name="quantity" value="1" />
					<input type="hidden" name="currency" value="<?php esc_attr_e($currency_code); ?>" />
					<input type="submit" value="submit" style="display: none"/>
				</form>
			</body>
			</html>

		<?php
		} else {
			edd_set_error( 'payment_declined', implode(", ",$error_msg));
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);	
		}
	} else {
		edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'Payment creation failed while processing a manual (free or test) purchase. Payment data: %s', 'easy-digital-downloads' ), json_encode( $payment_data ) ), $payment );
		// If errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_vgc', 'waf_vgcedd_process_payment' );

// Set transaction_id for first time payment
function waf_vgcedd_get_payment_transaction_id( $payment_id ) {

	$transaction_id = '';

	return apply_filters( 'edd_paypal_set_payment_transaction_id', $transaction_id, $payment_id );
}
add_filter( 'edd_get_payment_transaction_id-vgc', 'waf_vgcedd_get_payment_transaction_id', 10, 1 );

// Register process success rest api
add_action('rest_api_init', 'waf_vgcedd_add_callback_url_endpoint_process_success');

function waf_vgcedd_add_callback_url_endpoint_process_success() {
	register_rest_route(
		'wafvgc/v1/',
		'process-success',
		array(
			'methods' => 'GET',
			'callback' => 'waf_vgcedd_process_success'
		)
	);
}

// Callback function of process success rest api
function waf_vgcedd_process_success($request_data) {

	$parameters = $request_data->get_params();
	$payment_mode = $parameters['payment_mode'];

	if ($parameters['payment_id']) {

		$payment_id = intval(sanitize_text_field($parameters['payment_id']));
		$invoice_prefix = $parameters['invoice_prefix'];
		$secret_key = $parameters['secret_key'];
		$payment = new EDD_Payment( $payment_id );

		// Verify VGC payment
		$vgc_request = wp_remote_get("https://verygoodcollection.com/api/verify-payment/{$invoice_prefix}{$payment_id}/{$secret_key}");

		if (!is_wp_error($vgc_request) && 200 == wp_remote_retrieve_response_code($vgc_request)) {
			$vgc_payment = json_decode(wp_remote_retrieve_body($vgc_request));
			$status = $vgc_payment->status;
			$payment_total = $payment->total;
			$amount_paid = $vgc_payment->data->amount;
			$reference_id = $vgc_payment->data->reference;

			if ($status === "success") {
				// Empty the shopping cart
				edd_empty_cart();

				if ($amount_paid < $payment_total) {
					
					// Mark as pending
					edd_update_payment_status( $payment_id, 'pending' );
					edd_insert_payment_note( $payment_id, __("Amount paid is not the same as the total order amount.", 'easy-digital-downloads'));

				} else {

					//Complete payment
					edd_update_payment_status($payment_id, 'publish');
					edd_set_payment_transaction_id( $payment_id, $reference_id );
					edd_insert_payment_note( $payment_id, __("Payment via Very Good Collection successful with Reference ID: " . $reference_id, 'easy-digital-downloads'));

				}

				// Get the success url
				$return_url = add_query_arg(
								array(
									'payment-confirmation' => 'vgc',
									'payment-id' => $payment_id
								), 
								get_permalink(edd_get_option('success_page', false))
							);

				wp_redirect($return_url);
				die();

			} elseif ($status === "cancelled") {

				edd_update_payment_status($payment_id, 'failed');
				edd_insert_payment_note( $payment_id, __("Payment was canceled.", 'easy-digital-downloads'));
				edd_set_error( 'payment_declined', 'Payment was canceled.');
				edd_send_back_to_checkout('?payment-mode=' . $payment_mode);
				die();

			} else {
				// edd_empty_cart();
				edd_update_payment_status($payment_id, 'failed');
				edd_insert_payment_note( $payment_id, __("Payment was declined by Very Good Collection.", 'easy-digital-downloads'));
				edd_set_error( 'payment_declined', 'Payment was declined by Very Good Collection.');
				edd_send_back_to_checkout('?payment-mode=' . $payment_mode);
				die();

			}
		}
	}
	die();
}

