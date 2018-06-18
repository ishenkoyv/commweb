<?php

/*
 * Copyright 2018 Yurii Ishchenko <ishenkoyv@gmail.com>
 *
 * Licensed under the MIT License (the "License");
 */

namespace Ishenkoyv\Commweb;

use Ishenkoyv\Commweb\Exception\ResponseInvalidSignatureException,
    Ishenkoyv\Commweb\Exception\PaymentAttemptCounterInterface;

/**
 * ServerHostedPayment 
 * 
 * @author Yuriy Ishchenko <ishenkoyv@gmail.com> 
 */
class ServerHostedPayment
{
    protected $accessCode;
    protected $merchant;
    protected $secureSecret;
    protected $baseUrl;

    protected $paymentAttemptsCounterProcessor;

    protected $vpcURL = "https://migs.mastercard.com.au/vpcdps";

    public function __construct($accessCode, $merchantName, $secureSecret, $baseUrl)
    {
        $this->accessCode = $accessCode;
        $this->merchant = $merchantName;
        $this->secureSecret = $secureSecret;
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function setPaymentAttemptsCounterProcessor(PaymentAttemptCounterInterface $paymentAttemptsCounterProcessor)
    {
        $this->paymentAttemptsCounterProcessor = $paymentAttemptsCounterProcessor;

        return $this;
    }

    public function setVpcURL($vpcURL)
    {
        $this->vpcURL = $vpcURL;
    }

    public function getVpcURL()
    {
        return $this->vpcURL;
    }

    public function getPaymentData($params, $payment, $type = 'curl')
    {
        $result = array();

        $acceptedParams = array(
            'vpc_MerchTxnRef',
            'vpc_OrderInfo',
            'vpc_Amount',
            'vpc_ReturnURL',
            'vpc_AccessCode',
            'vpc_Merchant',
            'vpc_Version',
            'vpc_Command',
            'vpc_Locale',
        );
        if ("curl" === $type) {
            $acceptedParams += array(
                'vpc_CardNum',
                'vpc_CardExp',
                'vpc_CardSecurityCode',
            );
        }

        foreach ($params as $key => $value) {
            if (false !== array_search($key, $acceptedParams)
                && strlen($value) > 0
            ) {
                $result[$key] = $value;
            }
        }

        $result['vpc_AccessCode'] = $this->accessCode;
        $result['vpc_Merchant'] = $this->merchant;
        $result['vpc_Version'] = 1;
        $result['vpc_Command'] = 'pay';
        $result['vpc_Locale'] = 'en';
        $result['virtualPaymentClientURL'] = "https://migs.mastercard.com.au/vpcpay";

        if ("curl" === $type) {
            $result['vpc_CardNum'] =
                (!empty($params['cardNumOne']) ? $params['cardNumOne'] : '') .
                (!empty($params['cardNumTwo']) ? $params['cardNumTwo'] : '') .
                (!empty($params['cardNumThree']) ? $params['cardNumThree'] : '') .
                (!empty($params['cardNumFour']) ? $params['cardNumFour'] : '');

            $result['vpc_CardExp'] =
                (!empty($params['cardExpiryYear']) ? $params['cardExpiryYear'] : '') .
                (!empty($params['cardExpiryMonth']) ? $params['cardExpiryMonth'] : '');
        } else {
            // Http payment
            $result['vpc_ReturnURL'] = $this->baseUrl
                . '/payment/common-wealth-receipt.html?type='
                . (!empty($params['type']) ? $params['type'] : '')
                . (!empty($params['id']) ? '&id=' . $params['id'] : '')
                . (!empty($params['update']) ? '&update=' . $params['update'] : '')
                . (!empty($params['force']) ? '&force=' . $params['force'] : '')
                . '&token=' . $payment['token'];
        }

        $paymentAttempts = $this->paymentAttemptsCounterProcessor
            ->countCommwebGatewayAttempts($payment['token']);

        $result['vpc_MerchTxnRef'] = $payment['token'] . '/' . $paymentAttempts;

        $result['vpc_OrderInfo'] = $payment['payment_id'];
        $result['vpc_Amount'] = (int) sprintf('%01.0f', ($payment['quantity'] * $payment['amount'] * 1.1 * 100));

        return $result;
    }

    /**
     * Get refund transaction data from CommWeb log record
     *
     * @param array $params Item from payment_gateway_log_commweb
     * @param string $operator This field is a special AMA user created
     *                         to allow this function to operate.
     * @param mixed $password The password used to authorise the AMA user
     *                        access to this function.
     *
     * @return array Data for refund request
     */
    public function getRefundData($params, $operator, $password)
    {
        $result = array();

        $acceptedParams = array(
            'vpc_MerchTxnRef',
            'vpc_TransactionNo',
            'vpc_Amount',
        );

        $result['vpc_AccessCode'] = $this->accessCode;
        $result['vpc_Merchant'] = $this->merchant;
        $result['vpc_Version'] = 1;
        $result['vpc_Command'] = 'refund';
        $result['vpc_Locale'] = 'en';
        $result['virtualPaymentClientURL'] = "https://migs.mastercard.com.au/vpcpay";

        foreach ($params as $key => $value) {
            if (false !== array_search('vpc_' . $key, $acceptedParams)
                && strlen($value) > 0
            ) {
                $vpcKey = 'vpc_' . $key;

                switch ($vpcKey) {
                    case 'vpc_TransactionNo':
                        $vpcKey = 'vpc_TransNo';
                        break;
                }

                $result[$vpcKey] = $value;
            }
        }

        $result['vpc_User'] = $operator;
        $result['vpc_Password'] = $password;

        return $result;
    }

    public function getHttpPaymentUrl($data)
    {
        // add the start of the vpcURL querystring parameters
        $vpcURL = (!empty($data['virtualPaymentClientURL'])
            ? $data['virtualPaymentClientURL'] : 'https://migs.mastercard.com.au/vpcpay')
            . "?";

        $hashData = '';
        ksort($data);

        // set a parameter to show the first pair in the URL
        $appendAmp = 0;

        foreach ($data as $key => $value) {
            // create the md5 input and URL leaving out any fields that have no value
            if (strlen($value) > 0) {
                // this ensures the first paramter of the URL is preceded by the '?' char
                if ($appendAmp == 0) {
                    $vpcURL .= urlencode($key) . '=' . urlencode($value);
                    $appendAmp = 1;
                } else {
                    $vpcURL .= '&' . urlencode($key) . "=" . urlencode($value);
                }

                if ('virtualPaymentClientURL' == $key) {
                    continue;
                }

                $hashData .= $key . "=" . $value . "&";
            }
        }

        $hashData = rtrim($hashData, '&');

        // Create the secure hash and append it to the Virtual Payment Client Data if
        // the merchant secret has been provided.
        if (strlen($this->secureSecret) > 0) {
            $vpcURL .= "&vpc_SecureHash=" . strtoupper(hash_hmac('SHA256', $hashData, pack('H*', $this->secureSecret)));
            $vpcURL .= "&vpc_SecureHashType=SHA256";
        }

        return $vpcURL;
    }

    public function sendRequest($data)
    {
        // create a variable to hold the POST data information and capture it
        $postData = "";

        $ampersand = "";

        foreach ($data as $key => $value) {
            // create the POST data input leaving out any fields that have no value
            if (strlen($value) > 0) {
                $postData .= $ampersand . urlencode($key) . '=' . urlencode($value);
                $ampersand = "&";
            }
        }

        // Get a HTTPS connection to VPC Gateway and do transaction
        // turn on output buffering to stop response going to browser
        ob_start();

        // initialise Client URL object
        $ch = curl_init();

        // set the URL of the VPC
        curl_setopt($ch, CURLOPT_URL, $this->vpcURL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        curl_exec($ch);

        $response = ob_get_contents();
        ob_end_clean();

        // set up message paramter for error outputs
        $message = "";

        $responseCurlErrorNo = null;
        $responseCurlError = null;
        // serach if $response contains html error code
        if (strchr($response, "<html>") || strchr($response, "<html>")) {
            $message = $response;
        } else {
            // check for errors from curl
            if (curl_error($ch)) {
                $responseCurlErrorNo = curl_errno($ch);
                $responseCurlError = curl_error($ch);

                $message = "curl_errno=" . $responseCurlErrorNo
                    . "<br/>" . $responseCurlError;
            }
        }

        // close client URL
        curl_close($ch);

        // Extract the available receipt fields from the VPC Response
        // If not present then let the value be equal to 'No Value Returned'
        $map = array();

        // process response if no errors
        if (strlen($message) == 0) {
            $pairArray = explode("&", $response);
            foreach ($pairArray as $pair) {
                $param = explode("=", $pair);
                $map[urldecode($param[0])] = urldecode($param[1]);
            }
            $message = !empty($map["vpc_Message"]) ? $map["vpc_Message"] : '';
        }

        $result = array(
            'url' => $this->vpcURL,
            'postData' => $postData,
            'responseCurlErrorNo' => $responseCurlErrorNo,
            'responseCurlError' => $responseCurlError,
            'response' => $response,
            'message' => $message,
            'map' => $map,
        );

        return $result;
    }

    public function getHttpResponseErrorTxt($data)
    {
        $errorTxt = '';

        $supportedResponseParams = array(
            'vpc_AVSRequestCode',
            'vpc_AVSResultCode',
            'vpc_AcqAVSRespCode',
            'vpc_AcqCSCRespCode',
            'vpc_AcqResponseCode',
            'vpc_Amount',
            'vpc_AuthorizeId',
            'vpc_BatchNo',
            'vpc_CSCResultCode',
            'vpc_Card',
            'vpc_Command',
            'vpc_Locale',
            'vpc_MerchTxnRef',
            'vpc_Merchant',
            'vpc_Message',
            'vpc_OrderInfo',
            'vpc_ReceiptNo',
            'vpc_TransactionNo',
            'vpc_TxnResponseCode',
            'vpc_Version',
        );

        if (isset($data['vpc_TxnResponseCode'])
            && $data['vpc_TxnResponseCode'] == "0") {
            $hashData = '';

            foreach ($data as $key => $value) {
                if (false !== array_search($key, $supportedResponseParams)
                   && strlen($value) > 0
                ) {
                    $hashData .= $key . "=" . $value . "&";
                }
            }

            $hashData = rtrim($hashData, '&');

            $vpc_Txn_Secure_Hash = !empty($data['vpc_SecureHash'])
                ? $data['vpc_SecureHash'] : '';

            // Invalid hash
            if (strtoupper($vpc_Txn_Secure_Hash) !== strtoupper(hash_hmac('SHA256', $hashData, pack('H*', $this->secureSecret)))) {
                // throw new ResponseInvalidSignatureException('CommWeb response wrong signature vpc_MerchTxnRef: ' . $data['vpc_MerchTxnRef']);
                $errorTxt = 'Invalid Payment Gateway response signature. Please contact cm3 support';
            }
        } else {
            $errorTxt = $this->getResponseDescription($data['vpc_TxnResponseCode']);
        }

        return $errorTxt;
    }

    public function getResponseErrorTxt($map, $response)
    {
        $errorTxt = '';

        $pairArray = explode("&", $response);

        foreach ($pairArray as $pair) {
            $param = explode("=", $pair);
            $map[urldecode($param[0])] = urldecode($param[1]);
        }
        $message = !empty($map["vpc_Message"]) ? $map["vpc_Message"] : '';

        $txnResponseCode = isset($map["vpc_TxnResponseCode"])
            ? $map["vpc_TxnResponseCode"] : "7";

        $responseDescription = $this->getResponseDescription($txnResponseCode);

        switch ($txnResponseCode) {
            case '0':
                // Do nothing if success
                break;
            case '7':
                $messageParts = explode(':', $message);

                if (count($messageParts) > 1) {
                    array_shift($messageParts);
                }

                $messageParts = array_map('trim', $messageParts);
                $errorTxt = implode(' ', $messageParts);

                $errorTxt = str_replace(
                    array(
                        ' CardNum',
                        'CardExp',
                        'CardSecurityCode',
                    ),
                    array(
                        '',
                        'Card Expiry Date',
                        'Card Security Code'
                    ),
                    $errorTxt
                );
                $errorTxt = empty($errorTxt)
                    ? $responseDescription
                    : $errorTxt;

                break;
            default:
                $errorTxt = $responseDescription;
                break;
        }

        return $errorTxt;
    }

    // This method uses the QSI Response code retrieved from the Digital
    // Receipt and returns an appropriate description for the QSI Response Code
    //
    // @param $responseCode String containing the QSI Response Code
    //
    // @return String containing the appropriate description
    //
    public function getResponseDescription($responseCode)
    {
        switch ($responseCode) {
            case "0":
                $result = "Transaction Successful";
                break;
            case "?":
                $result = "Transaction status is unknown";
                break;
            case "1":
                $result = "Unknown Error";
                break;
            case "2":
                $result = "Bank Declined Transaction";
                break;
            case "3":
                $result = "No Reply from Bank";
                break;
            case "4":
                $result = "Expired Card";
                break;
            case "5":
                $result = "Insufficient funds";
                break;
            case "6":
                $result = "Error Communicating with Bank";
                break;
            case "7":
                $result = "Payment Server System Error";
                break;
            case "8":
                $result = "Transaction Type Not Supported";
                break;
            case "9":
                $result = "Bank declined transaction (Do not contact Bank)";
                break;
            case "A":
                $result = "Transaction Aborted";
                break;
            case "B":
                $result = "Transaction Declined";
                break;
            case "C":
                $result = "Transaction Cancelled";
                break;
            case "D":
                $result = "Deferred transaction has been received and is awaiting processing";
                break;
            case "F":
                $result = "3D Secure Authentication failed";
                break;
            case "I":
                $result = "Card Security Code verification failed";
                break;
            case "L":
                $result = "Shopping Transaction Locked (Please try the transaction again later)";
                break;
            case "N":
                $result = "Card security code is invalid or not matched";
                break;
            case "P":
                $result = "Card security code not processed";
                break;
            case "R":
                $result = "Transaction was not processed - Reached limit of retry attempts allowed";
                break;
            case "S":
                $result = "Duplicate SessionID (OrderInfo)";
                break;
            case "T":
                $result = "Address Verification Failed";
                break;
            case "U":
                $result = "Card Issuer is not registered and/or certified";
                break;
            case "V":
                $result = "Address Verification and Card Security Code Failed";
                break;
            default:
                $result = "Unable to be determined";
        }

        return $result;
    }
    
}
