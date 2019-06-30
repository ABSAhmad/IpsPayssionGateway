<?php
/**
 * @brief		Payssion Gateway
 * @author		<a href='https://flawlessmanagement.com'>FlawlessManagement</a>
 * @copyright	(c) 2016 FlawlessManagement
 * @package		IPS Community Suite
 * @subpackage	Nexus
 * @since		18 May 2016
 * @version		1.0.0
 */

if ( !file_exists( '../../../init.php' ) ) {
	header('HTTP/1.1 404 Not Found');
	die;
}

/* Send an empty HTTP 200 OK response to acknowledge receipt of the notification */
header('HTTP/1.1 200 OK');

require_once '../../../init.php';

/* Load Transaction */
try
{

	$transaction = \IPS\nexus\Transaction::load( \IPS\Request::i()->order_id );

	if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING && $transaction->status !== \IPS\nexus\Transaction::STATUS_WAITING )
	{
		throw new \OutofRangeException;
	}
}
catch ( \OutOfRangeException $e )
{
	\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=nexus&module=payments&controller=checkout&do=transaction&id=&t=" . \IPS\Request::i()->order_id, 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https ) );
}

try
{
	$g_settings     = json_decode( $transaction->method->settings, TRUE );
	$apiKey         = $g_settings['api_key'];
	$secretKey      = $g_settings['secret_key'];
	$paymentMethods = $g_settings['payment_methods'];

	/* Assign payment notification values to local variables */
	$pmId         = \IPS\Request::i()->pm_id;
	$amount       = \IPS\Request::i()->amount;
	$currency     = \IPS\Request::i()->currency;
	$trackId      = \IPS\Request::i()->order_id;
	$state        = \IPS\Request::i()->state;
	$hash         = \IPS\Request::i()->notify_sig;

	$sigArray['apiKey']         = $g_settings['api_key'];
	$sigArray['secretKey']      = $g_settings['secret_key'];
	$sigArray['pmId']           = $pmId;
	$sigArray['amount']         = $amount;
	$sigArray['currency']       = $currency;
	$sigArray['transactionId']  = $trackId;
	$sigArray['state']          = $state;

	if ( \IPS\payssion\Payssion::verifySig( $sigArray, $hash ) )
	{
		/* Handle payment notification */
		switch ($state) {
			case 'completed':
				$transaction->auth = NULL;
				$transaction->approve(NULL);
				$transaction->save();
				$transaction->sendNotification();
				break;
			case 'paid_partial':
			case 'pending':
				$transaction->status = \IPS\nexus\Transaction::STATUS_WAITING;
				$transaction->save();
				$transaction->sendNotification();
				break;
			case 'failed':
			case 'expired':
			case 'error':
			default:
				$transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
				$transaction->void();
				$transaction->save();
				$transaction->sendNotification();
				break;
		}
	}
	else
	{
		$transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
		$transaction->void();
		$transaction->save();
		$transaction->sendNotification();
	}
}
catch ( \Exception $e )
{
	\IPS\Output::i()->redirect( $transaction->invoice->checkoutUrl()->setQueryString( array( '_step' => 'checkout_pay', 'err' => $e->getMessage() ) ) );
}