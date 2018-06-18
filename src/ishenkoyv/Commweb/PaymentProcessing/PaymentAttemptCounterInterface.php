<?php

/*
 * Copyright 2018 Yurii Ishchenko <ishenkoyv@gmail.com>
 *
 * Licensed under the MIT License (the "License");
 */

namespace Ishenkoyv\Commweb\PaymentProcessing;

use Ishenkoyv\Commweb\Exception\ResponseInvalidSignatureException;

/**
 * PaymentAttemptCounterInterface 
 * 
 * @author Yuriy Ishchenko <ishenkoyv@gmail.com> 
 */
interface PaymentAttemptCounterInterface
{
    public function countCommwebGatewayAttempts($paymentToken);
}
