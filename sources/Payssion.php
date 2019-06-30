<?php
/**
 * @brief       Payssion Gateway
 * @author      <a href='https://flawlessmanagement.com'>Ahmad @ FlawlessManagement</a>
 * @copyright   (c) 2016 FlawlessManagement
 * @package     IPS Community Suite
 * @subpackage  Nexus
 * @since       17 May 2016
 * @version     1.0
 */

namespace IPS\payssion;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

require_once 'payssion/PayssionClient.php';

/**
 * Payssion Gateway
 */
class _Payssion extends \IPS\nexus\Gateway
{
    const SUPPORTS_REFUNDS = FALSE;
    const SUPPORTS_PARTIAL_REFUNDS = FALSE;

    protected static $paymentMethods = [
        'alfaclick_ru'      => "Alfa-Click",
        'alipay_cn'         => "Alipay",
        'bitcoin'           => "Bitcoin",
        'boleto_br'         => "Boleto",
        'yamoneyac'         => "BankCard (Yandex.Money)",
        'yamoneygp'         => "Cash (Yandex.Money)",
        'cashu'             => "CashU",
        'dotpay_pl'         => "DotPay",
        'euroset_ru'        => "Euroset",
        'faktura_ru'        => "Faktura",
        'ideal_nl'          => "iDeal",
        'molpay'            => "MOLPay",
        'moneta_ru'         => "Moneta",
        'onecard'           => "OneCard",
        'openbucks'         => "Openbucks",
        'oxxo_mx'           => "Oxxo",
        'paysafecard'       => "Paysafecard",
        'poli_au'           => "PoliPayment AU",
        'poli_nz'           => "PoliPayment NZ",
        'promsvyazbank_ru'  => "Promsvyazbank",
        'qiwi'              => "QIWI",
        'banktransfer_ru'   => "Russian Bank Transfer",
        'rsb_ru'            => "Russian Standard Bank",
        'russianpost_ru'    => "Russian Post Centres",
        'sberbank_ru'       => "Sberbank",
        'sofort'            => "SOFORT Banking",
        'trustpay'          => "Trustpay",
        'unionpay'          => "Unionpay",
        'yamoney'           => "Yandex.Money",
        'webmoney'          => "Webmoney",
    ];

    /**
     * Initialize PaymentGateway
     *
     * @param $api_key
     * @param $secret_key
     * @param bool $sandbox
     * @return \PayssionClient
     */
    public function initGateway( $api_key, $secret_key, $sandbox = false )
    {
        return new \PayssionClient( $api_key, $secret_key, !$sandbox );
    }

    /**
     * Payment Screen Fields
     *
     * @param   \IPS\nexus\Invoice      $invoice    Invoice
     * @param   \IPS\nexus\Money        $amount     The amount to pay now
     * @param   \IPS\nexus\Customer     $member     The member the payment screen is for (if in the ACP charging to a member's card) or NULL for currently logged in member
     * @param   array                   $recurrings Details about recurring costs
     * @param   string                  $type       'checkout' means the cusotmer is doing this on the normal checkout screen, 'admin' means the admin is doing this in the ACP, 'card' means the user is just adding a card
     *
     * @return    array
     */
    public function paymentScreen( \IPS\nexus\Invoice $invoice, \IPS\nexus\Money $amount, \IPS\nexus\Customer $member = NULL, $recurrings = array(), $type = 'checkout' )
    {
        /* Get gateway settings */
        $settings = json_decode( $this->settings, TRUE );

        /* Check if 'all' has been selected */
        if ( $settings['payment_methods'] == -1 )
        {
            $settings['payment_methods'] = static::$paymentMethods;
        }
        else
        {
            /* Settings do only contain the array keys, not the names so we need to recover them here */
            $flipped = array_flip( $settings['payment_methods'] );
            $settings['payment_methods'] = array_merge( $flipped, array_intersect_key( static::$paymentMethods, $flipped ) );
        }

        return [
            'payssion_pmid' => new \IPS\Helpers\Form\Select( 'payssion_pmid', 'alipay_cn', TRUE,
                [
                    'options' => $settings['payment_methods']
                ]
            )
        ];
    }

    /**
     * Authorize
     *
     * @param   \IPS\nexus\Transaction                  $transaction    Transaction
     * @param   array|\IPS\nexus\Customer\CreditCard    $values         Values from form OR a stored card object if this gateway supports them
     * @param   \IPS\nexus\Fraud\MaxMind\Request|NULL   $maxMind        *If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made
     * @param   array                                   $recurrings     Details about recurring costs
     * @param   string|NULL                             $source         'checkout' if the customer is doing this at a normal checkout, 'renewal' is an automatically generated renewal invoice, 'manual' is admin manually charging. NULL is unknown
     *
     * @return  \IPS\DateTime|NULL                      Auth is valid until or NULL to indicate auth is good forever
     *
     * @throws  \LogicException                         Message will be displayed to user
     */
    public function auth( \IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL )
    {
        /* Get gateway settings */
        $settings = json_decode( $this->settings, TRUE );

        /* We need a transaction ID */
        $transaction->save();

        /* User selected payment method */
        $payment_id = $values['payssion_pmid'];

        /* Get product names for the description */
        $summary = $transaction->invoice->summary();
        foreach ( $summary['items'] as $item )
        {
            $productNames[] = $item->quantity .' x '. $item->name;
        }

        /* Set payment parameters for POST request */
        $params['amount']       = (string) $transaction->amount->amount;
        $params['currency']     = (string) $transaction->amount->currency;
        $params['pm_id']        = (string) $payment_id;
        $params['order_id']     = $transaction->id;
        $params['payer_name']   = $this->getFullName( $transaction ) ? $this->getFullName( $transaction ) : 'Anonymous';
        $params['payer_email']  = \IPS\Member::loggedIn()->email;
        $params['description']  = implode( ', ', $productNames );
        $params['notify_url']   = \IPS\Settings::i()->base_url . 'applications/payssion/interface/gateway.php';
        $params['success_url']  = (string) $transaction->invoice->checkoutUrl();
        $params['redirect_url'] = (string) $transaction->invoice->checkoutUrl();


        $sigParams['apiKey'] = $settings['api_key'];
        $sigParams['secretKey'] = $settings['secret_key'];
        $sigParams['pmId'] = $params['pm_id'];
        $sigParams['amount'] = $params['amount'];
        $sigParams['currency'] = $params['currency'];
        $sigParams['transactionId'] = $params['order_id'];
        $params['api_sig'] = $this->sign( $sigParams );

        var_dump($settings['api_key'], $settings['secret_key'], (bool) \IPS\NEXUS_TEST_GATEWAYS);die;

        /* Get payssion object */
        $payssion = $this->initGateway( $settings['api_key'], $settings['secret_key'], (bool) \IPS\NEXUS_TEST_GATEWAYS );


        try {
            $response = $payssion->create( $params );
        } catch ( \Exception $e ) {
            echo "An error has occurred: " . $e->getMessage();
            die;
        }

        if ( $response['result_code'] == 200 )
        {
            /* Check if we have to redirect the user */
            if ( $response['todo'] == "redirect" )
            {
                /* Let's redirect the user */
                \IPS\Output::i()->redirect( \IPS\Http\Url::external( $response["redirect_url"] ) );
            }
        }
        else
        {
            /* Error, let the user know what went wrong */
            echo "An error has occurred, please contact an admin with the following message: " . $response['description'];
            \IPS\Log::log( $response['description'], 'payssion_payment' );
            die;
        }
    }

    /**
     * Void
     *
     * @param   \IPS\nexus\Transaction  $transaction    Transaction
     * @return  void
     * @throws  \Exception
     */
    public function void( \IPS\nexus\Transaction $transaction )
    {
        /* Nothing to do as gateway doesn't support refunds */
    }

    /* !ACP Configuration */

    /**
     * Settings
     *
     * @param   \IPS\Helpers\Form   $form   The form
     *
     * @return  void
     */
    public function settings( &$form )
    {
        $settings = json_decode( $this->settings, TRUE );

        $form->add( new \IPS\Helpers\Form\Text( 'payssion_api_key', isset( $settings['api_key'] ) ? $settings['api_key'] : '', TRUE ) );
        $form->add( new \IPS\Helpers\Form\Text( 'payssion_secret_key', isset( $settings['secret_key'] ) ? $settings['secret_key'] : '', TRUE ) );
        $form->add( new \IPS\Helpers\Form\Select( 'payssion_payment_methods', isset($settings['payment_methods']) ? $settings['payment_methods'] : '-1', TRUE, array( 'options' => static::$paymentMethods, 'multiple' => TRUE, 'unlimited' => '-1', 'unlimitedLang' => 'all' ) ) );
    }

    /**
     * Test Settings
     *
     * @param   array   $settings   Settings
     *
     * @return  array
     */
    public function testSettings( $settings )
    {
        return $settings;
    }

    /**
     * Get first name for Payssion
     *
     * @param   \IPS\nexus\Transaction  $transaction    Transaction
     *
     * @return  string
     */
    protected function getFirstName( \IPS\nexus\Transaction $transaction )
    {
        return $transaction->invoice->member->member_id ? $transaction->invoice->member->cm_first_name : $transaction->invoice->guest_data['member']['cm_first_name'];
    }

    /**
     * Get last name for Payssion
     *
     * @param   \IPS\nexus\Transaction  $transaction    Transaction
     *
     * @return  string
     */
    protected function getLastName( \IPS\nexus\Transaction $transaction )
    {
        return $transaction->invoice->member->member_id ? $transaction->invoice->member->cm_last_name : $transaction->invoice->guest_data['member']['cm_last_name'];
    }

    /**
     * Get full name for Payssion
     *
     * @param   \IPS\nexus\Transaction  $transaction    Transaction
     *
     * @return  string
     */
    protected function getFullName( \IPS\nexus\Transaction $transaction )
    {
        return $this->getFirstName( $transaction ) . ' ' . $this->getLastName( $transaction );
    }

    /**
     * @param array $data
     * @param bool $ipn
     *
     * @return array|string
     */
    public static function sign( array $data, $ipn = false )
    {
        $sig = array(
            $data['apiKey'],
            $data['pmId'],
            $data['amount'],
            $data['currency'],
            $data['transactionId'],
            $ipn ? $data['state'] : '',
            $data['secretKey']
        );
        $sig = md5( implode( '|', $sig ) );

        return $sig;
    }

    /**
     * @param array $data
     * @param $hash
     * @return bool
     */
    public static function verifySig( array $data, $hash )
    {
        return $hash == static::sign( $data, true );
    }
}
