Commweb Payment Transactions for Merchant-Hosted Payment                                                                         
========================================================

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
