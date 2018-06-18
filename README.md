Commweb Payment Transactions for Server-Hosted Payment                                                                         
========================================================

https://www.commbank.com.au

CommWeb is a fast and reliable payments acceptance service for your online shop. Get world-class security and streamline your payments administration.
The GET method with a Query String containing the transaction request fields, and a HTTPS Redirect, is used to send the transaction request via the CommWeb Virtual Payment Client to the payment Server, when using Server-Hosted Payments.

```
public function commwebOrderHandler()
{
  $returnUrl = '/commweb-response-handler.html';
  
  $payment = $this->get('paymentService')->getPayment($this->getParam('payment_id'));
  $returnUrl .= '?token=' . $payment['token'];
  
  $paymentParams = $this->getAllParams();
  $commweb = $this->get('CommwebService');
  $paymentData = $commweb->getPaymentData($paymentParams, $payment, 'http');
  
  $vpcURL = $commweb->getHttpPaymentUrl($paymentData);
  $queryParts = parse_url($vpcURL);
  
  // log request information
  header("Location: " . $vpcURL);
 }
 
 public function commwebResponseHandler()
 {
  $commweb = $this->get('CommwebService');
 
  $errorTxt = $commweb->getHttpResponseErrorTxt($this->getAllParams());
  if ($errorTxt) {
    return $this->redirectPaymentError($errorTxt, $returnUrl);
  }
  
  $this->get('paymentService')->paymentProcessedSuccessfully($this->getParam('payment_id');
 }
```
