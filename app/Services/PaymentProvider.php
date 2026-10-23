<?php

namespace App\Services;

use App\Models\SubscriptionOrder;

interface PaymentProvider
{
    /** @return array{provider_order_id: string, payment_uri: ?string} */
    public function createIntent(SubscriptionOrder $order): array;

    public function name(): string;
}
