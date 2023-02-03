<?php
/**
 * Plugin Name: First Atlantic Commerce Integration
 * Plugin URI: https://www.linkedin.com/in/odain-chevannes
 * Description: Quick and Dirty integration to handle payment requests through the FAC gateway
 * Version: 1.0
 * Author: Odain Chevannes
 * Author URI: https://www.linkedin.com/in/odain-chevannes
 */

if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

require_once(__DIR__ . '/XML/Serializer.php');
require_once(__DIR__ . '/XML/Unserializer.php');

// Modification Types. psuedo enum

/**
 * Class ModificationTypes
 */
final class ModificationTypes
{
    const Capture = 1;
    const Refund = 2;
    const Reversal = 3;
    const Cancel = 4;
}

// exceptions
class FacAuthorizationException extends Exception
{
}


add_action('rest_api_init', 'FacApi::register_endpoints');

add_action('admin_menu', 'FacApi::fac_admin_menu');
add_action('admin_init', 'FacApi::fac_settings_init');
add_action('fac_recurring_daily_txn', 'FacApi::schedule_recurring_txns');

register_activation_hook(__FILE__, "FacApi::init");
register_deactivation_hook(__FILE__, 'FacApi::fac_deactivation');

register_uninstall_hook(__FILE__, "FacApi::fac_uninstall");

add_filter("plugin_action_links_" . plugin_basename(__FILE__), 'FacApi::fac_plugin_settings_link');

class FacApi
{
    // we call the soap action to get the token we need to bring up the remote page
    // so that we don't have to handle the card number
    /**
     * @param WP_REST_Request $request
     * @return string[]
     * @throws SoapFault
     */
    public static function get_hosted_page(WP_REST_Request $request)
    {
        $opts = $options = get_option('fac_api_options');
        // echo $opts['test_mode'];
        // FAC Integration Domain
        $domain = $opts['fac_api_field_test_mode'] == true ? $opts['fac_api_field_test_domain'] : $opts['fac_api_field_live_domain'];

        // Ensure you append the ?wsdl query string to the URL
        $wsdlurl = 'https://' . $domain . '/PGService/HostedPage.svc?wsdl';
        $soapUrl = 'https://' . $domain . '/PGService/HostedPage.svc';

        // Set up client to use SOAP 1.1 and NO CACHE for WSDL. You can choose between
        // exceptions or status checking. Here we use status checking. Trace is for Debug only
        // Works better with MS Web Services where
        // WSDL is split into several files. Will fetch all the WSDL up front.
        $options = array(
            'location' => $soapUrl,
            'soap_version' => SOAP_1_1,
            'exceptions' => 0,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE
        );

        // WSDL Based calls use a proxy, so this is the best way
        // to call FAC PG Operations.
        $client = new SoapClient($wsdlurl, $options);

        // This should not be in your code in plain text!
        $password = $opts['fac_api_field_transaction_pwd'];

        // Use your own FAC ID
        $facId = $opts['fac_api_field_merchant_id'];

        // Acquirer is always this
        $acquirerId = $opts['fac_api_field_acquirer_id'];

        // THESE next variables COME FROM THE PREVIOUS PAGE (hence $_POST) but you could drive
        // these from any source such as config files, server cache etc.

        // Must be Unique per order. Put your own format here. The field allows up to 150
        // alphanumeric characters.
        $orderNumber = $_POST["OrderId"];
        // Passed in as a decimal but 12 chars is required
        $amount = $_POST["Amount"];
        // Page Set
        $pageset = $_POST["PageSet"];
        // Page Name
        $pagename = $_POST["PageName"];
        // TransCode
        $transCode = $_POST["TransCode"];
        // Where the response will end up. Should be a page your site and will get two parameters
        // ID = Single Use Key passed to payment page and RespCode = normal response code for Auth
        $CardHolderResponseUrl = $_POST["CardHolderResponseUrl"];


        // Formatted Amount. Must be in twelve charecter, no decimal place, zero padded format
        $amountFormatted = str_pad('' . ($amount * 100), 12, "0", STR_PAD_LEFT);
        // 840 = USD, put your currency code here
        $currency = '840';

        // Each call must have a signature with the password as the shared secret
        $signature = self::Sign($password, $facId, $acquirerId, $orderNumber, $amountFormatted, $currency);

        // this is <userid>_<product_code>_<order_id>
        $txn_details = explode("_", $orderNumber);
        $user_id = $txn_details[0];

        // You only need to initialise the message sections you need. So for a basic Auth
        // only Credit Cards and Transaction details are required.
        // Transaction Details.
        $TransactionDetails = array('AcquirerId' => $acquirerId,
            'Amount' => $amountFormatted,
            'Currency' => $currency,
            'CurrencyExponent' => 2,
            'IPAddress' => '',
            'MerchantId' => $facId,
            'OrderNumber' => $orderNumber,
            'Signature' => $signature,
            'SignatureMethod' => 'SHA1',
            'CustomerReference' => $user_id,
            'TransactionCode' => 392);
        // 128 - return my tokenized PAN dude!
        // 256 - 3ds my dude
        // 264 - capture auth + 3ds
        // 392  - capture auth, 3ds and tokenize


        // The request data is named 'Request' for reasons that are not clear!
        $HostedPageRequest = array('Request' => array('TransactionDetails' => $TransactionDetails,
            'CardHolderResponseURL' => $CardHolderResponseUrl));
        // Call the Authorize through the Soap Client
        $result = $client->HostedPageAuthorize($HostedPageRequest);

        // You should CHECK the results here!!!
        // print_r($HostedPageRequest);
        // print_r($result);

        $returnObj = array(
            'status' => '',
            'description' => '',
            'forwardUrl' => ''
        );

        if ($result->ResponseCode == 0) {
            // Extract Token
            $token = $result->HostedPageAuthorizeResult->SingleUseToken;
            // Construct the URL. This may be different for Production. Check with FAC
            $PaymentPageUrl = 'https://' . $domain . '/MerchantPages/' . $pageset . '/' . $pagename . '/';
            // Create the location header to effect a redirect. Add token required by page
            $RedirectURL = $PaymentPageUrl . $token;
            // Redirect user to the Payment page
            $returnObj['status'] = "success";
            $returnObj['forwardUrl'] = $RedirectURL;
            $returnObj['request'] = $HostedPageRequest;
        } else {
            $returnObj['status'] = "failure";
            $returnObj['description'] = "Error " . $result->ResponseCode . ": " . $result . ResponseCodeDescription;
        }
        return $returnObj;

    }

    // This is essentially a web hook
    // once the user submits the form to FAC, we need to send another request
    // to get the result of that authorization request
    public static function get_hosted_page_result(WP_REST_Request $request)
    {
        $opts = $options = get_option('fac_api_options');
        // echo $opts['test_mode'];
        // FAC Integration Domain
        $domain = $opts['fac_api_field_test_mode'] == true ? $opts['fac_api_field_test_domain'] : $opts['fac_api_field_live_domain'];

        // IMPORTANT: Convert URL Parameters to variables
        $ID = $_GET['ID'];

        $host = 'ecm.firstatlanticcommerce.com';
        // Ensure you append the ?wsdl query string to the URL for WSDL URL
        $wsdlurl = 'https://' . $domain . '/PGService/HostedPage.svc?wsdl';
        // No WSDL parameter for location URL
        $loclurl = 'https://' . $domain . '/PGService/HostedPage.svc';
        // Set up client to use SOAP 1.1 and NO CACHE for WSDL. You can choose between
        // exceptions or status checking. Here we use status checking. Trace is for Debug only
        // Works better with MS Web Services where
        // WSDL is split into several files. Will fetch all the WSDL up front.
        $options = array(
            'location' => $loclurl,
            'soap_version' => SOAP_1_1,
            'exceptions' => 0,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE
        );
        // WSDL Based calls use a proxy, so this is the best way
        // to call FAC PG Operations as it creates the methods for you
        $client = new SoapClient($wsdlurl, $options);
        // Call the HostedPageResults through the Client. Note the param
        // name is case sensitive, so 'Key' does not work.
        $result = $client->HostedPageResults(array('key' => $ID));
        // NOW: You have access to all the response fields and can evaluate as you want to
        // and use them to display something to the user in an HTML page like the HTML snippet
        // below. It is very simple and you have not had any exposure to the card number at all.
        // While it is not necessary to make this soap call, it is advisable that you implement this
        // and get the full response details to ensure the correct amount has been charged etc.
        // You should also store the results in case of any chargeback issues and to check the response
        // code has not been tampered with.
        if ($result->HostedPageResultsResult->AuthResponse->CreditCardTransactionResults->ReasonCode != 1) {
//            echo "<h1>";
//            echo FacApi::mepr_remove_current_member();
//            echo "</h1>";

            FacApi::mepr_fail_page($result->HostedPageResultsResult->AuthResponse->CreditCardTransactionResults->ReasonCodeDescription);
        } else {
            //$tokenized_pan = $result->HostedPageResultsResult->AuthResponse->CreditCardTransactionResults->TokenizedPAN;
            self::write_log($result);
            $txn_amount = ltrim($result->HostedPageResultsResult->PurchaseAmount, "0");
            $txn_amount = substr_replace($txn_amount, ".", -2, 0);
            $orderId = $result->HostedPageResultsResult->AuthResponse->OrderNumber;
            $token_pan = $result->HostedPageResultsResult->AuthResponse->CreditCardTransactionResults->TokenizedPAN;
            try {

                // create the recurring txn in memberpress plugin
                $create_sub_request = FacApi::mepr_create_sub($orderId, $txn_amount, implode("|", $result), $token_pan);

                if (wp_remote_retrieve_response_code($create_sub_request) == 200) {
                    // we outta here!
                    FacApi::mepr_success_page();
                } else {
                    // could not create sub we should reverse transaction
                    FacApi::modify_trxn($orderId, $txn_amount);

                    $responseData1 = json_decode(wp_remote_retrieve_body($create_sub_request));
                    FacApi::mepr_fail_page("Could not create subscription. Payment has been reversed. Details: "
                        . substr($responseData1->message, 0, 100));
                }

            } catch (Exception $ex) {
                FacApi::mepr_fail_page("Could not create subscription. Payment could not be reversed at this time. " . $ex->getMessage());
            }

        }
    }


    /**
     * @param string $orderId the order number
     * @param string $order_total the transaction amount
     * @param string $token_pan the tokenized card number/ card number
     * @param string $customer_ref the customer reference number for the  tokenized PAN
     * @param DateTime $card_exp this is not important when using the tokenize pan
     * @param string $cvv the card verification value, this is not needed for tokenized transactions
     * @throws FacAuthorizationException
     */
    static function authorize_trxn($orderId, $order_total, $token_pan, $customer_ref,
                                   $card_exp = null, $cvv = "123")
    {
        $opts = $options = get_option('fac_api_options');


        // XML Urls are named after the Operation in a Rest-ful manner
        $url = $opts['fac_api_field_test_mode'] == true ?
            $opts['fac_api_field_test_url'] : $opts['fac_api_field_live_url'];
        // This should not be in your code in plain text!
        $password = $opts['fac_api_field_transaction_pwd'];

        // Use your own FAC ID
        $facId = $opts['fac_api_field_merchant_id'];

        // Acquirer is always this
        $acquirerId = $opts['fac_api_field_acquirer_id'];
        // This is set in the merchant portal. transactions can fail if time is behind keep this updated
        $timeZone = $opts['fac_api_field_time_zone_gmt'];
        // Must be Unique per order. Put your own format here
        $orderNumber = $orderId;
        // 12 chars, always, no decimal place
        $amount = FacApi::format_float($order_total);
        // Formatted Amount. Must be in twelve charecter, no decimal place, zero padded format
        $amountFormatted = str_pad('' . ($amount * 100), 12, "0", STR_PAD_LEFT);
        // 840 = USD, put your currency code here
        $currency = '840';
        $signature = FacApi::Sign($password, $facId, $acquirerId, $orderNumber, $amountFormatted,
            $currency);
        // You only need to initialise the message sections you need. So for a basic
        //Auth
        // only Credit Cards and Transaction details are required.
        // Card Details. Arrays serialise to elements in XML/SOAP
        $CardDetails = array('CardCVV2' => $cvv,
            'CardExpiryDate' => date("my", $card_exp),
            'CardNumber' => $token_pan,
            'IssueNumber' => '',
            'StartDate' => '');

        // Transaction Details.
        $TransactionDetails = array('Amount' => $amountFormatted,
            'Currency' => $currency,
            'CurrencyExponent' => 2,
            'IPAddress' => '',
            'MerchantId' => $facId,
            'OrderNumber' => $orderNumber,
            'Signature' => $signature,
            'AcquirerId' => $acquirerId,
            'SignatureMethod' => 'SHA1',
            'TransactionCode' => '0',
            'CustomerReference' => $customer_ref);


        // The request data is named 'Request' for reasons that are not clear!
        $AuthorizeRequest = array(
            'TransactionDetails' => $TransactionDetails,
            'CardDetails' => $CardDetails
        );

        $options = array(
            "indent" => " ",
            "linebreak" => "\n",
            "typeHints" => false,
            "addDecl" => true,
            "encoding" => "UTF-8",
            "rootName" => "AuthorizeRequest",
            "defaultTagName" => "item",
            "rootAttributes" => array("xmlns" =>
                "http://schemas.firstatlanticcommerce.com/gateway/data")
        );
        $serializer = new XML_Serializer($options);
        if ($serializer->serialize($AuthorizeRequest)) {
            $xmlRequest = $serializer->getSerializedData();

            //debug
            //echo '<pre>';
            // htmlspecialchars($xmlRequest);
            //echo '</pre>';
            $ch = curl_init($url);
            //curl_setopt($ch, CURLOPT_MUTE, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            curl_close($ch);

            // Let's convert to an Object graph, easier to process
            $options = array('complexType' => 'object');
            $deserializer = new XML_Unserializer($options);
            // Pass in the response XML as a string
            $result = $deserializer->unserialize($response, false);
            // As with Serializing, we must call getUnserialzedData afterwards
            $AuthorizeResponse = $deserializer->getUnserializedData();
            // Display the result objects
            //echo '<h2>Result</h2><pre>';
            //print_r($AuthorizeResponse);


            if ($AuthorizeResponse->CreditCardTransactionResults->ReasonCode == 1) {
                return $AuthorizeResponse;
            } else {
                $msg = $AuthorizeResponse->CreditCardTransactionResults->ReasonCode . ' - ' . $AuthorizeResponse->CreditCardTransactionResults->ReasonCodeDescription;
                throw new FacAuthorizationException($msg);
            }

            //echo '</pre>';
        } else {
            throw new FacAuthorizationException(__('Can\'t read response from First Atlantic Commerce SOAP Endpoint', 'memberpress'));
        }
        return false;
    }

    /**
     *  Initialize this plugin
     */
    static function init()
    {
        // this file is basically an addon for memberpress
        // so we will copy our custom files to the memberpress directories
        copy(__DIR__ . "/MeprFirstAtlanticCommerceGateway.php", ABSPATH . "wp-content/plugins/memberpress/app/gateways/MeprFirstAtlanticCommerceGateway.php");
        copy(__DIR__ . "/MeprBaseGateway.php", ABSPATH . "wp-content/plugins/memberpress/app/lib/MeprBaseGateway.php");

        // create the database for keeping track of recurring transactions
        Self::create_plugin_database_table();
        // daily sweep for all subs
        Self::schedule_recurring_txns();
    }

    static function fac_deactivation()
    {
        wp_clear_scheduled_hook('fac_recurring_daily_txn');
    }

    static function fac_uninstall()
    {
        FacApi::remove_plugin_database_table();
        unlink(ABSPATH . "wp-content/plugins/memberpress/app/gateways/MeprFirstAtlanticCommerceGateway.php");
    }

    /**
     * show settings link on plugin page
     */
    static function fac_plugin_settings_link($links)
    {
        $settings_link = '<a href="https://' . $_SERVER['SERVER_NAME'] . '/wp-admin/admin.php?page=fac-api">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register API endpoints
     */
    static function register_endpoints()
    {
        // POST /fac/v1/authorize3ds/check
        register_rest_route('fac/v1', 'authorize3ds/check', [
            'methods' => 'GET',
            'callback' => 'FacApi::get_hosted_page_result',
            'args' => array(
                'ID' => array(
                    'required' => false,
                ),
                'RespCode' => array(
                    'required' => false,
                ),
                'ReasonCode' => array(
                    'required' => false,
                )
            )
        ]);

        // POST /fac/v1/authorize3ds
        register_rest_route('fac/v1', 'authorize3d', [
            'methods' => 'POST',
            'callback' => 'FacApi::get_hosted_page'
        ]);
    }

    /**
     * we update the cardholder's token here.
     *
     * We get the token from the initial 3ds transaction and stored it (if the txn was a recurring txn)
     * The token is used to create a secure representation of the card details
     * so that we don't have to store the card data on the merchant's end.
     *
     */
    static function update_tokenized_card($customer_ref, $expiry_date, $pan_token)
    {
        $opts = $options = get_option('fac_api_options');
        // echo $opts['test_mode'];
        // FAC Integration Domain
        $domain = $opts['fac_api_field_test_mode'] == true ? $opts['fac_api_field_test_domain'] : $opts['fac_api_field_live_domain'];
        // Ensure you append the ?wsdl query string to the URL for WSDL URL
        $wsdlurl = 'https://' . $domain . '/PGService/Tokenization.svc?wsdl';
        // No WSDL parameter for location URL
        $loclurl = 'https://' . $domain . '/PGService/Tokenization.svc';
        // Set up client to use SOAP 1.1 and NO CACHE for WSDL. You can choose between
        // exceptions or status checking. Here we use status checking. Trace is for Debug only
        // Works better with MS Web Services where
        // WSDL is split into several files. Will fetch all the WSDL up front.
        $options = array(
            'location' => $loclurl,
            'soap_version' => SOAP_1_1,
            'exceptions' => 0,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE
        );
        // WSDL Based calls use a proxy, so this is the best way
        // to call FAC PG Operations as it creates the methods for you
        $client = new SoapClient($wsdlurl, $options);


        // This should not be in your code in plain text!
        $password = $opts['fac_api_field_transaction_pwd'];

        // Use your own FAC ID
        $facId = $opts['fac_api_field_merchant_id'];

        // Acquirer is always this
        $acquirerId = $opts['fac_api_field_acquirer_id'];

        // Each call must have a signature with the password as the shared secret
        $signature = self::Sign($password, $facId, $acquirerId);

        $Request = array('CustomerReference' => $customer_ref,
            'ExpiryDate' => $expiry_date,
            'MerchantNumber' => $facId,
            'TokenPAN' => $pan_token,
            'Signature' => $signature
        );

        // The request data is named 'Request' for reasons that are not clear!
        $UpdateTokenRequest = array('Request' => array('CustomerReference' => $customer_ref,
            'ExpiryDate' => $expiry_date,
            'MerchantNumber' => $facId,
            'TokenPAN' => $pan_token,
            'Signature' => $signature
        ));

        // Call the Authorize through the Soap Client
        $result = $client->UpdateToken($UpdateTokenRequest);

        return $result->UpdateTokenResponse->Success;
    }

    /**
     * Gets a secure representation of the customers card detail for future use with the gateway, without storing the
     * card details on the merchant's system
     * @param string $customer_ref customer reference
     * @param string $expiry_date the expiry date of the card
     * @param string $pan the card number
     * @return mixed
     * @throws SoapFault
     */
    static function get_tokenized_card($customer_ref, $expiry_date, $pan)
    {
        $opts = $options = get_option('fac_api_options');
        // echo $opts['test_mode'];
        // FAC Integration Domain
        $domain = $opts['fac_api_field_test_mode'] == true ? $opts['fac_api_field_test_domain'] : $opts['fac_api_field_live_domain'];
        // Ensure you append the ?wsdl query string to the URL for WSDL URL
        $wsdlurl = 'https://' . $domain . '/PGService/Tokenization.svc?wsdl';
        // No WSDL parameter for location URL
        $loclurl = 'https://' . $domain . '/PGService/Tokenization.svc';
        // Set up client to use SOAP 1.1 and NO CACHE for WSDL. You can choose between
        // exceptions or status checking. Here we use status checking. Trace is for Debug only
        // Works better with MS Web Services where
        // WSDL is split into several files. Will fetch all the WSDL up front.
        $options = array(
            'location' => $loclurl,
            'soap_version' => SOAP_1_1,
            'exceptions' => 0,
            'trace' => 1,
            'cache_wsdl' => WSDL_CACHE_NONE
        );
        // WSDL Based calls use a proxy, so this is the best way
        // to call FAC PG Operations as it creates the methods for you
        $client = new SoapClient($wsdlurl, $options);


        // This should not be in your code in plain text!
        $password = $opts['fac_api_field_transaction_pwd'];

        // Use your own FAC ID
        $facId = $opts['fac_api_field_merchant_id'];

        // Acquirer is always this
        $acquirerId = $opts['fac_api_field_acquirer_id'];

        // Each call must have a signature with the password as the shared secret
        $signature = self::Sign($password, $facId, $acquirerId);

        $Request =

            // The request data is named 'Request' for reasons that are not clear!
        $UpdateTokenRequest = array('Request' => array('CustomerReference' => $customer_ref,
            'ExpiryDate' => $expiry_date,
            'MerchantNumber' => $facId,
            'CardNumber' => $pan,
            'Signature' => $signature,
            'CustomerReference' => $customer_ref
        ));

        // Call the Authorize through the Soap Client
        return $client->Tokenize($UpdateTokenRequest);
    }

    /**
     * Table set up to track recurring transaction on the merchant end
     */
    static function create_plugin_database_table()
    {
        global $table_prefix, $wpdb;

        $tblname = 'fac_recurring_transaction';
        $wp_track_table = $table_prefix . "$tblname";

        #Check to see if the table exists already, if not, then create it
        if ($wpdb->get_var("show tables like '$wp_track_table'") != $wp_track_table) {

            $sql = "CREATE TABLE `" . $wp_track_table . "` ( ";
            $sql .= "  `id`  int(11)   NOT NULL auto_increment, ";
            $sql .= "  `tokenized_pan`  varchar(30)   NOT NULL, ";
            $sql .= "  `membership_id`  int   NOT NULL, ";
            $sql .= "  `customer_ref`  varchar(30)   NOT NULL, ";
            $sql .= "  `txn_amount`  varchar(20)   NOT NULL, ";
            $sql .= "  `txn_cycles`  varchar(6)   NOT NULL, ";
            $sql .= "  `txn_cycles_num`  int   NOT NULL, ";
            $sql .= "  `txn_count`  int(11)   NOT NULL, ";
            $sql .= "  `txn_complete`  boolean   NOT NULL, ";
            $sql .= "  `interval_type`  varchar(20)   NOT NULL, ";
            $sql .= "  `next_execution`  datetime   NOT NULL, ";
            $sql .= "  PRIMARY KEY `order_id` (`id`) ";
            $sql .= ") ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ; ";
            require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
            dbDelta($sql);

//            self::write_log("==================================");
//            self::write_log("EXECUTING SQL");
//            self::write_log("==================================");
//            self::write_log($sql);
//
//            self::write_log("verifying table created");
//            self::write_log("show tables like '$wp_track_table'");
//            self::write_log($wpdb->get_var("show tables like '$wp_track_table'"));
        }
    }

    static function remove_plugin_database_table()
    {
        // drop tables
        global $table_prefix, $wpdb;

        $tblname = 'fac_recurring_transaction';
        $wp_track_table = $table_prefix . "$tblname";
        $wpdb->query("DROP TABLE IF EXISTS $wp_track_table");
    }


    /**
     * @param string $subId id for the subscription
     * @param string $membership_id id for the membership/product being subscribed to
     * @param string $customer_reference the unique customer number
     * @param string $amount the transaction amount
     * @param string $pan the tokenized card number
     * @param string $cycles the time interval
     * @param int $cycle_num the number of intervals
     * @param int $count the number of payments already processed in this subscription
     */
    static function db_create_recurring_txn($subId, $membership_id, $customer_reference, $amount, $pan, $cycles, $cycle_num, $count = 1)
    {
        global $table_prefix, $wpdb;

        $tblname = 'fac_recurring_transaction';
        $wp_track_table = $table_prefix . "$tblname";

        $execution_date = new DateTime();

        if ($cycles == 'months')
            $execution_date->add(new DateInterval("P1M"));
        else if ($cycles == 'years') {
            $execution_date->add(new DateInterval("P1Y"));
        } else if ($cycles == 'weeks')
            $execution_date->add(new DateInterval("P7D"));

        $result = $wpdb->insert($wp_track_table, array(
                'id' => (int)$subId,
                'tokenized_pan' => $pan,
                'membership_id' => (int)$membership_id,
                'customer_ref' => $customer_reference,
                'txn_cycles' => $cycles,
                'txn_amount' => $amount,
                'txn_cycles_num' => (int)$cycle_num,
                'txn_count' => $count,
                'interval_type' => $cycles,
                'txn_complete' => false,
                'next_execution' => $execution_date->format("Y-m-d")
            )
        );
        FacApi::write_log($result);
        if ($result == false) {
            throw new Exception("could not add txn to database. " . $wpdb->last_error);
        }
    }

    /**
     * updates a recurring txn when an authorization is done
     * @param string $id the id for the txn in the database
     * @param string $next_execution the next execution date for this transaction
     * @param string $limit the maximum number of payments to be done
     * @param string $count the current transaction number
     */
    static function db_record_complete_txn($id, $next_execution=null, $limit = null, $count = null)
    {
        // we are just going to mak this as complete
        if ($limit == null && $count == null && $next_execution=null) {
            $limit = 1;
            $count = 1;
        }

        $completed = false;
        if ($count >= $limit) {
            // if count and limit were specified we want to check if this is the last payment
            $completed = true;
        }
        global $table_prefix, $wpdb;

        // get the table name
        $tblname = 'fac_recurring_transaction';
        $wp_track_table = $table_prefix . "$tblname";

        // data to update
        $data = array(
            'txn_complete' => $completed,
            'txn_count' => $count + 1,
            'next_execution' => $next_execution);

        // clause
        $where = array('id' => $id);

        // do update in db
        $wpdb->update($wp_track_table, $data, $where);
    }

    /**
     * checks the txn table for pending recurring transactions and execute them if they are due today
     */
    static function fac_recurring_daily_txn()
    {
        $today = new DateTime();
        $today_str = date("Ymd");

        // fetch all the pending transactions
        $txns = Self::db_get_recurring_txn();

        if ($txns) {
            foreach ($txns as $txn) {
                $txn_date_str = date("Ymd", strtotime($txn->next_execution));
                // should this txn be done today?
                if ($txn_date_str == $today_str) {
                    // create a card expiry date to make this request valid
                    // any future date is acceptable
                    $exp = $today->add(new DateInterval("P1Y"));

                    $execution_date = new DateTime();
                    if ($txn->interval_type == 'months')
                        $execution_date->add(new DateInterval("P1M"));
                    else if ($txn->interval_type == 'years') {
                        $execution_date->add(new DateInterval("P1Y"));
                    } else if ($txn->interval_type == 'weeks')
                        $execution_date->add(new DateInterval("P7D"));

                    try {
                        $orderId = "R_" . generateRandomString();

                        $auth = Self::authorize_trxn(
                            $orderId,
                            $txn->txn_amount,
                            $txn->tokenized_pan,
                            $txn->customer_ref,
                            date("Ymd", $exp));

                            // record the txn in memberpress
                            self::mepr_create_transaction(
                                $txn->customer_ref,
                                $txn->membership_id,
                                $orderId,
                                $txn->id,
                                $txn->txn_amount,
                                print_r($auth),
                                false
                            );
                            // update this record
                            Self::db_record_complete_txn($txn->id, date('Y-m-d', $execution_date), $txn->txn_cycles_num, $txn->txn_count + 1);

                    } catch (Exception $ex) {
                        Self::write_log($ex);
                    }
                }
            }
        }
    }

    /**
     * Wordpress logs!
     */
    static function write_log($log)
    {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }


    static function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    // get sub interval
    static function get_subscription_interval($sub)
    {
        if ($sub->period_type == 'months')
            return "M";
        else if ($sub->period_type == 'years') {
            return "Y";
        } else if ($sub->period_type == 'weeks')
            return "W";
    }

    /**
     * Find all the pending recurring transactions
     */
    static function db_get_recurring_txn()
    {
        global $wpdb;
        global $table_prefix, $wpdb;

        $tblname = 'fac_recurring_transaction';
        $wp_track_table = $table_prefix . "$tblname";

        $execution_date = new DateTime();

        return $wpdb->get_results("select * from $wp_track_table " . "txn_complete = false");
    }

    /**
     * create a cron job to check subs and pay the txns that are due
     */
    public static function schedule_recurring_txns()
    {
        if (!wp_next_scheduled('fac_recurring_daily_txn')) {
            wp_schedule_event(time(), 'daily', 'fac_recurring_daily_txn');
        }
    }

    /**
     * memberpress txn was successful
     */
    static function mepr_success_page()
    {
        $page = "https://" . $_SERVER['SERVER_NAME'] . "/thank-you/";
        wp_redirect($page);
        exit();
    }

    /**
     * memberpress txn failed
     */
    static function mepr_fail_page($err)
    {
        $paymentFailedPage = "https://" . $_SERVER['SERVER_NAME'] . "/failed-payment?err=" . $err;
        wp_redirect($paymentFailedPage);
        exit();
    }

    /**
     * @param string $orderId This consist of the user id, product id and order number
     * @param string $price The cost of the sub
     * @param string $resp the response from the gateway
     * @param string $pan the tokenized card number
     * @param int $limit maximum number of payment cycles
     * @param string $limitType weekly, monthly, yearly etc.
     * @param boolean $limit_cycles is this trxn limited
     */
    static function mepr_create_sub($orderId, $price, $resp, $pan, $limit = 1, $limitType = "years", $limit_cycles = false)
    {
        
        $opts = $options = get_option('fac_api_options');
        $meprPassword = $opts["fac_api_field_time_mepr_api_pwd"];

        $url = "https://" . $_SERVER['SERVER_NAME'] . "/wp-json/mp/v1/subscriptions";
        $headers = array("MEMBERPRESS-API-KEY" => $meprPassword);

        $txn_details = explode("_", $orderId);
        $user_id = $txn_details[0];
        $membership_id = $txn_details[1];
        $subId = $txn_details[2];

        // get the details for the membership we are adding the user to
        $membership = FacApi::mepr_get_membership($membership_id);

        // get the user's current subs
        $subs = FacApi::mepr_get_subs();

        if (isset($membership)) {
            $limitType = $membership->period_type;
            $limit = $membership->limit_cycles_num;
            $limit_cycles = $membership->limit_cycles;
        }

        // delete existing subs
        if (isset($subs)) {
            foreach ($subs as $item) {
                if ($item->member->id == $user_id) {
                    FacApi::mepr_delete_sub($item->id);
                    FacApi::db_record_complete_txn($item->id);
                }
            }
        }

        $data = array(
            "subscr_id" => $subId,
            "member" => $user_id,
            "period_type" => $limitType,
            "membership" => $membership_id,
            "gateway" => "qkh4bw-1x7", // not sure if this will change
            "limit_cycles_action" => "expire",
            "limit_cycles" => $limit_cycles,
            "limit_cycles_num" => $limit,
            "status" => "active",
            "total" => $price,
            "response" => $resp,
            "created_at" => date('c')
        );

        $result = wp_remote_post($url, array(
            "body" => $data,
            "method" => "POST",
            "headers" => $headers
        ));
        FacApi::write_log("=================================");
        FacApi::write_log("CREATING SUBSCRIPTION");
        FacApi::write_log("=================================");
        FacApi::write_log($result);

        // sub created
        if (wp_remote_retrieve_response_code($result) == 200) {
            $sub = json_decode(wp_remote_retrieve_body($result));

            // create the txn for the sub
            $txn_result = FacApi::mepr_create_transaction(
                $user_id,
                $membership_id,
                date("Ymdgi") . "_" . $orderId,
                $sub->id,
                $price,
                "",
                true
            );
            FacApi::write_log("=================================");
            FacApi::write_log("CREATING TRANSACTION");
            FacApi::write_log("=================================");
            FacApi::write_log($txn_result);

            if (wp_remote_retrieve_response_code($txn_result) != 200) {
                $responseData = json_decode(wp_remote_retrieve_body($txn_result));
                throw new Exception(substr($responseData->message, 0, 100));
            }

            $limit = $limit_cycles ? $limit : 99999; // no limit

            // save this to db for future processing
            FacApi::db_create_recurring_txn($sub->id, $user_id, $txn_details, $price, $pan, $limitType, $limit, 1);
            return $txn_result;
        }

        //return $result;
    }

    static function mepr_delete_sub($id)
    {
        $opts = $options = get_option('fac_api_options');
        $meprPassword = $opts["fac_api_field_time_mepr_api_pwd"];

        $url = "https://" . $_SERVER['SERVER_NAME'] . "/wp-json/mp/v1/subscriptions/" . $id;
        $basicauth = 'Basic ' . base64_encode($opts['fac_api_field_admin_username'] . ':' . $opts['fac_api_field_admin_password']);

        $headers = array(
            "MEMBERPRESS-API-KEY" => $meprPassword,
            'Authorization' => $basicauth);


        $result = wp_remote_post($url, array(
            "method" => "DELETE",
            "headers" => $headers
        ));
        FacApi::write_log("============================================================");
        FacApi::write_log("DELETING SUBSCRIPTION WITH ID " . $id);
        FacApi::write_log("============================================================");
        FacApi::write_log($result);
        return $result;
    }

    static function mepr_get_membership($id)
    {
        $opts = $options = get_option('fac_api_options');
        $meprPassword = $opts["fac_api_field_time_mepr_api_pwd"];

        $url = "https://" . $_SERVER['SERVER_NAME'] . "/wp-json/mp/v1/memberships/" . $id;
        $headers = array("MEMBERPRESS-API-KEY" => $meprPassword);

        $result = wp_remote_get($url, array(
            "headers" => $headers
        ));
        FacApi::write_log($result);
        if (wp_remote_retrieve_response_code($result) == 200) {
            return json_decode($result["body"]);
        }
        return null;
    }

    static function mepr_get_subs()
    {
        $opts = $options = get_option('fac_api_options');
        $meprPassword = $opts["fac_api_field_time_mepr_api_pwd"];

        $url = "https://" . $_SERVER['SERVER_NAME'] . "/wp-json/mp/v1/subscriptions?page=1&per_page=10000";
        $headers = array("MEMBERPRESS-API-KEY" => $meprPassword);

        $result = wp_remote_get($url, array(
            "method" => "GET",
            "headers" => $headers
        ));
        //FacApi::write_log($result);
        if (wp_remote_retrieve_response_code($result) == 200) {
            return json_decode($result["body"]);
        }
        return null;
    }

    /**
     * @param string $user_id the member id if the current user
     * @param string $membership_id this identifies the subscription/product
     * @param string $trans_num the transaction id
     * @param string $sub_id The subscription that this txn is for
     * @param string $price The cost of the sub
     * @param string $resp the response from the gateway
     * @param boolean $send_welcome_email send a welcome email, for first time payment for a sub
     */
    static function mepr_create_transaction($user_id, $membership_id, $trans_num, $sub_id, $price, $resp,
                                            $send_welcome_email = false)
    {
        $opts = $options = get_option('fac_api_options');
        $meprPassword = $opts["fac_api_field_time_mepr_api_pwd"];

        $url = "https://" . $_SERVER['SERVER_NAME'] . "/wp-json/mp/v1/transactions";
        $headers = array("MEMBERPRESS-API-KEY" => $meprPassword);

        $data = array(
            "trans_num" => $trans_num,
            "amount" => $price,
            "member" => $user_id,
            "membership" => $membership_id,
            "subscription" => $sub_id,
            "gateway" => "qkh4bw-1x7", // not sure if this will change
            "status" => "complete",
            "total" => $price,
            "tax_rate" => "0.000",
            "response" => $resp,
            "send_welcome_email" => $send_welcome_email, // only do this on signup
            "send_receipt_email" => true
        );

        $result = wp_remote_post($url, array(
            "body" => $data,
            "method" => "POST",
            "headers" => $headers
        ));

        FacApi::write_log($result);
        return $result;
    }

    // formats a number by adding the decimal places
    static function format_float($number, $num_decimals = 2)
    {
        return number_format($number, $num_decimals, '.', '');
    }

    // How to sign a FAC Authorize message
    static function Sign($passwd, $facId, $acquirerId, $orderNumber = "", $amount = "", $currency = "")
    {
        $stringtohash =
            $passwd . $facId . $acquirerId . $orderNumber . $amount . $currency;
        $hash = sha1($stringtohash, true);
        return base64_encode($hash);
    }

    /**
     * This modifies an existing transaction
     * @param string $orderId the order reference number.
     * @param string $amount the transaction amount
     * @param int $modification_type refund, void, cancel recurring
     */
    static function modify_trxn($orderId, $amount, $modification_type = ModificationTypes::Reversal)
    {
        $opts = $options = get_option('fac_api_options');
        // echo $opts['test_mode'];
        // FAC Integration Domain
        $url = $opts['fac_api_field_test_mode'] == true ? $opts['fac_api_field_test_url'] . '/TransactionModification' : $opts['fac_api_field_live_url'] . '/TransactionModification';
        // This should not be in your code in plain text!
        // This should not be in your code in plain text!
        $password = $opts['fac_api_field_transaction_pwd'];

        // Use your own FAC ID
        $facId = $opts['fac_api_field_merchant_id'];

        // Acquirer is always this
        $acquirerId = $opts['fac_api_field_acquirer_id'];
        // Must be a previously Authorized Transaction Order Number
        $orderNumber = $orderId;
        // the total price for the subcription
        $amount = Self::format_float($amount);
        // Formatted Amount. Must be in twelve charecter, no decimal place, zero padded format
        $amountFormatted = str_pad('' . ($amount * 100), 12, "0", STR_PAD_LEFT);
        // Transaction Details.
        $TransactionModificationRequest = array('AcquirerId' => $acquirerId,
            'Amount' => $amountFormatted,
            'CurrencyExponent' => 2,
            'MerchantId' => $facId,
            'ModificationType' => $modification_type,
            'OrderNumber' => $orderNumber,
            'Password' => $password);
        // Note the rootAttributes includes the FAC namespace. Do not use the
        // namepace option as it will prefix the name, which is not right and
        // will cause the call to fail
        $options = array(
            "indent" => " ",
            "linebreak" => "\n",
            "typeHints" => false,
            "addDecl" => true,
            "encoding" => "UTF-8",
            "rootName" => "TransactionModificationRequest",
            "defaultTagName" => "item",
            "rootAttributes" => array("xmlns" =>
                "http://schemas.firstatlanticcommerce.com/gateway/data")
        );

        $serializer = new XML_Serializer($options);
        if ($serializer->serialize($TransactionModificationRequest)) {
            $xmlRequest = $serializer->getSerializedData();

            //echo '<pre>';
            //echo htmlspecialchars($xmlRequest);
            //echo '</pre>';

            $ch = curl_init($url);
            //curl_setopt($ch, CURLOPT_MUTE, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            curl_close($ch);

            // Let's convert to an Object graph, easier to process
            $options = array('complexType' => 'object');
            $deserializer = new XML_Unserializer($options);
            // Pass in the response XML as a string
            $result = $deserializer->unserialize($response, false);
            // As with Serializing, we must call getUnserialzedData afterwards
            $TransactionModificationResponse = $deserializer->getUnserializedData();
            // Display the result objects
            //echo '<h2>Result</h2><pre>';
            //echo ''.$response;
            //echo '</pre>';

            if ($TransactionModificationResponse->ResponseCode == 1) {


            } else {
                $msg = $TransactionModificationResponse->ReasonCode . ' - ' . $TransactionModificationResponse->ReasonCodeDescription;
                throw new Exception($msg);
            }

            //echo '</pre>';
        } else {
            throw new Exception(__('Can\'t read response from First Atlantic Commerce SOAP Endpoint', 'memberpress'));
        }
    }

    /**
     * Redirects the user to the hosted checkout page after
     * Inserting the transaction details
     */
    static function display_fac_hosted_payment_form()
    {
        ?>
        <div id="payment_form">

        </div>
        <style>
            #ifrm {
                width: 100%;
                height: 500px;
            }
        </style>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                // Handler when the DOM is fully loaded
                var xmlhttp = new XMLHttpRequest();
                xmlhttp.onreadystatechange = function () {
                    if (this.readyState == 4 && this.status == 200) {
                        var response = JSON.parse(this.response);
                        if (response.status === 'success') {
                            //console.log(response.request);
                            window.location.href = response.forwardUrl;
                            // var el = document.getElementById('payment_form');
                            // var ifrm = document.createElement('embed');
                            // ifrm.setAttribute('id', 'ifrm'); // assign an id
                            // el.appendChild(ifrm);
                            // ifrm.setAttribute('src', response.forwardUrl);
                        }
                        // display some error
                        else {
                            alert('Server error: ' + response.description);
                        }
                    }
                };

                xmlhttp.open("POST", window.location.origin + "/wp-json/fac/v1/authorize3d", true);

                //Send the proper header information along with the request
                xmlhttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xmlhttp.setRequestHeader('Access-Control-Allow-Origin', '*');
                xmlhttp.send('Amount=' + amount +
                    '&PageSet=' + 'Payment' +
                    '&PageName=' + 'Secure' +
                    '&TransCode=' + '264' +
                    '&CardHolderResponseUrl=' + window.location.origin + "/wp-json/fac/v1/authorize3ds/check" +
                    '&OrderId=' + orderId
                );
            });

        </script>
        <?php
    }

    static function fac_admin_menu()
    {
        // Add an admin page to change the settings for this plugin
        add_menu_page(
            "FAC Payment Integration",
            "FAC Payment",
            "manage_options",
            "fac-api",
            "FacApi::admin_page_contents",
            "dashicons-hammer",
            69);
    }

    static function admin_page_contents()
    {
        // check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // add error/update messages

        // check if the user have submitted the settings
        // WordPress will add the "settings-updated" $_GET parameter to the url
        if (isset($_GET['settings-updated'])) {
            // add settings saved message with the class of "updated"
            add_settings_error('fac_api_messages', 'fac_api_message', __('Settings Saved', 'fac-api'), 'updated');
        }

        // show error/update messages
        settings_errors('fac_api_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                // output security fields for the registered setting "wporg"
                settings_fields('fac-api');
                // output setting sections and their fields
                // (sections are registered for "wporg", each field is registered to a specific section)
                do_settings_sections('fac-api');
                // output save settings button
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * custom option and settings
     */
    static function fac_settings_init()
    {
        register_setting('fac-api', 'fac_api_options');

        add_settings_section(
            'fac_settings_section',
            __('First Atlantic Commerce Integration', 'fac-api'), 'FacApi::fac_api_settings_callback',
            'fac-api'
        );

        add_settings_field(
            'fac_api_field_merchant_id', // As of WP 4.6 this value is used only internally.
            __('Merchant ID', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_merchant_id',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_admin_username', // As of WP 4.6 this value is used only internally.
            __('WP Admin User', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_admin_username',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_admin_password', // As of WP 4.6 this value is used only internally.
            __('WP Admin Password', 'fac-api'),
            'FacApi::fac_api_field_password',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_admin_password',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_transaction_pwd', // As of WP 4.6 this value is used only internally.
            __('Transaction Password', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_transaction_pwd',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_test_mode', // As of WP 4.6 this value is used only internally.
            __('Test Mode Enabled', 'fac-api'),
            'FacApi::fac_api_field_checkbox',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_test_mode',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_test_url', // As of WP 4.6 this value is used only internally.
            __('Test URL', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_test_url',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_live_url', // As of WP 4.6 this value is used only internally.
            __('Live URL', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_live_url',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_live_domain', // As of WP 4.6 this value is used only internally.
            __('Live Domain', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_live_domain',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_test_domain', // As of WP 4.6 this value is used only internally.
            __('Test Domain', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_test_domain',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );

        add_settings_field(
            'fac_api_field_order_prefix', // As of WP 4.6 this value is used only internally.
            __('Order Prefix', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_order_prefix',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_acquirer_id', // As of WP 4.6 this value is used only internally.
            __('Acquirer ID', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_acquirer_id',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_time_zone_gmt', // As of WP 4.6 this value is used only internally.
            __('Timezone GMT', 'fac-api'),
            'FacApi::fac_api_field_number',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_time_zone_gmt',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
        add_settings_field(
            'fac_api_field_time_mepr_api_pwd', // As of WP 4.6 this value is used only internally.
            __('MemberPress API Key', 'fac-api'),
            'FacApi::fac_api_field_text',
            'fac-api',
            'fac_settings_section',
            array(
                'label_for' => 'fac_api_field_time_mepr_api_pwd',
                'class' => 'row',
                'fac_api_custom_data' => 'custom',
            )
        );
    }

    static function fac_api_settings_callback($args)
    {
        ?>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('API Settings.', 'fac-api'); ?></p>
        <?php
    }

    static function fac_api_field_text($args)
    {
        $options = get_option('fac_api_options');
        ?>
        <input id="<?php echo esc_attr($args['label_for']); ?>"
               type="text"
               data-custom="<?php echo esc_attr($args['fac_api_custom_data']); ?>"
               class="mepr-auto-trim"
               name="fac_api_options[<?php echo esc_attr($args['label_for']); ?>]"
               value="<?php echo $options[$args['label_for']] ?>"/>
        <?php
    }

    static function fac_api_field_password($args)
    {
        $options = get_option('fac_api_options');
        ?>
        <input id="<?php echo esc_attr($args['label_for']); ?>"
               type="password"
               data-custom="<?php echo esc_attr($args['fac_api_custom_data']); ?>"
               class="mepr-auto-trim"
               name="fac_api_options[<?php echo esc_attr($args['label_for']); ?>]"
               value="<?php echo $options[$args['label_for']] ?>"/>
        <?php
    }

    static function fac_api_field_number($args)
    {
        $options = get_option('fac_api_options');
        ?>
        <input id="<?php echo esc_attr($args['label_for']); ?>"
               type="number"
               data-custom="<?php echo esc_attr($args['fac_api_custom_data']); ?>"
               class="mepr-auto-trim"
               name="fac_api_options[<?php echo esc_attr($args['label_for']); ?>]"
               value="<?php echo $options[$args['label_for']] ?>"/>
        <?php
    }

    static function fac_api_field_checkbox($args)
    {
        $options = get_option('fac_api_options');
        $html = '<input type="checkbox" id="checkbox_example" name="fac_api_options[' . esc_attr($args['label_for']) . ']" value="1"' . checked(1, $options[$args['label_for']], false) . '/>';
        echo $html;
    }
}
