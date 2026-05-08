<?php

class PaynetworxValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        // Verify this payment method is available for the current cart
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === 'paynetworx') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->errors[] = $this->module->l('This payment method is not available.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        // Idempotency: if an order already exists for this cart, redirect to confirmation
        $existingOrderId = (int) Order::getOrderByCartId((int) $cart->id);
        if ($existingOrderId > 0) {
            Tools::redirect(
                'index.php?controller=order-confirmation'
                . '&id_cart='   . (int) $cart->id
                . '&id_module=' . (int) $this->module->id
                . '&id_order='  . $existingOrderId
                . '&key='       . $customer->secure_key
            );
            return;
        }

        // Double-submit protection
        $nonce        = Tools::getValue('paynetworx_nonce');
        $sessionNonce = $this->context->cookie->paynetworx_nonce;
        unset($this->context->cookie->paynetworx_nonce);

        if (empty($nonce) || !hash_equals((string) $sessionNonce, (string) $nonce)) {
            $this->errors[] = $this->module->l('Invalid payment session. Please try again.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        // Collect and sanitize card inputs
        $rawNumber   = preg_replace('/\D/', '', Tools::getValue('card_number'));
        $expiryMonth = (int) Tools::getValue('card_expiry_month');
        $expiryYear  = (int) Tools::getValue('card_expiry_year');
        $cvc         = preg_replace('/\D/', '', Tools::getValue('card_cvc'));

        // Expand 2-digit year to 4-digit (e.g. 27 → 2027)
        if ($expiryYear < 100) {
            $expiryYear = 2000 + $expiryYear;
        }

        // Server-side validation
        if (!$this->validateCardInputs($rawNumber, $expiryMonth, $expiryYear, $cvc)) {
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        $currency = $this->context->currency;
        $total    = round((float) $cart->getOrderTotal(true, Cart::BOTH), 2);

        $cardData = [
            'pan'       => $rawNumber,
            'exp_month' => $expiryMonth,
            'exp_year'  => $expiryYear,
            'cvc'       => $cvc,
        ];

        $billingData = $this->buildBillingData($cart, $customer);

        // Claim the cart slot — UNIQUE KEY on id_cart prevents concurrent double-charges
        $inserted = Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'paynetworx_transactions`
             (`id_cart`, `amount`, `currency`, `status`, `created_at`)
             VALUES (' . (int) $cart->id . ', ' . (float) $total . ', \''
            . pSQL($currency->iso_code) . '\', \'pending\', NOW())'
        );

        if (!$inserted || Db::getInstance()->Affected_Rows() === 0) {
            $this->errors[] = $this->module->l('A payment for this order is already being processed. Please wait.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
            return;
        }

        require_once _PS_MODULE_DIR_ . 'paynetworx/classes/PaynetworxApi.php';

        $api = new PaynetworxApi(
            trim(Configuration::get('PAYNETWORX_TOKEN_USER')),
            trim(Configuration::get('PAYNETWORX_TOKEN_PASS')),
            Configuration::get('PAYNETWORX_MODE')
        );

        try {
            PrestaShopLogger::addLog(
                'Paynetworx: charge attempt cart=' . (int) $cart->id
                    . ' amount=' . $total . ' ' . $currency->iso_code
                    . ' card=' . substr($rawNumber, 0, 6) . '******' . substr($rawNumber, -4),
                1, null, 'Cart', (int) $cart->id, true
            );

            $result = $api->authCapture($total, $currency->iso_code, $cardData, $billingData, (int) $cart->id);

            PrestaShopLogger::addLog(
                'Paynetworx: gateway response cart=' . (int) $cart->id
                    . ' http=' . $result['status']
                    . ' approved=' . (isset($result['body']['Approved']) ? var_export($result['body']['Approved'], true) : 'null'),
                1, null, 'Cart', (int) $cart->id, true
            );

            if ($result['status'] >= 200 && $result['status'] < 300
                && isset($result['body']['Approved'])
                && $result['body']['Approved'] === true
            ) {
                $transactionId = isset($result['body']['TransactionID']) ? (string) $result['body']['TransactionID'] : '';
                $authCode      = isset($result['body']['AuthCode'])      ? (string) $result['body']['AuthCode']      : '';

                // Record the successful charge before creating the PS order
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'paynetworx_transactions`
                     SET `transaction_id` = \'' . pSQL($transactionId) . '\',
                         `auth_code`      = \'' . pSQL($authCode) . '\',
                         `status`         = \'charged\'
                     WHERE `id_cart` = ' . (int) $cart->id
                );

                try {
                    $this->module->validateOrder(
                        (int) $cart->id,
                        (int) Configuration::get('PS_OS_PAYMENT'),
                        $total,
                        $this->module->displayName,
                        'TransactionID: ' . $transactionId . ' | AuthCode: ' . $authCode,
                        ['transaction_id' => $transactionId],
                        (int) $currency->id,
                        false,
                        $customer->secure_key
                    );

                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'paynetworx_transactions`
                         SET `id_order` = ' . (int) $this->module->currentOrder . ',
                             `status`   = \'complete\'
                         WHERE `id_cart` = ' . (int) $cart->id
                    );

                    Tools::redirect(
                        'index.php?controller=order-confirmation'
                        . '&id_cart='   . (int) $cart->id
                        . '&id_module=' . (int) $this->module->id
                        . '&id_order='  . (int) $this->module->currentOrder
                        . '&key='       . $customer->secure_key
                    );
                } catch (Exception $e) {
                    // Charge succeeded but PS order creation failed — needs manual reconciliation
                    PrestaShopLogger::addLog(
                        'CRITICAL — Paynetworx: charge approved (TransactionID=' . $transactionId
                        . ') but validateOrder() failed — MANUAL REVIEW REQUIRED: ' . $e->getMessage(),
                        4, null, 'Cart', (int) $cart->id, true
                    );
                    $this->errors[] = $this->module->l(
                        'Your payment was received but order creation failed. Please contact support with reference: ' . $transactionId,
                        'validation'
                    );
                    $this->redirectWithNotifications('index.php?controller=order&step=4');
                }
            } else {
                $detail = $this->extractGatewayMessage($result['body'] ?? []);
                if ($detail) {
                    PrestaShopLogger::addLog(
                        'Paynetworx: decline cart=' . (int) $cart->id . ' — ' . $detail,
                        2, null, 'Cart', (int) $cart->id, true
                    );
                }

                // Release the lock so the customer can retry with a different card
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'paynetworx_transactions`
                     WHERE `id_cart` = ' . (int) $cart->id . ' AND `status` = \'pending\''
                );

                $this->errors[] = $this->module->l('Your payment was declined. Please try a different card.', 'validation');
                $this->redirectWithNotifications('index.php?controller=order&step=4');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Paynetworx: exception cart=' . (int) $cart->id . ' — ' . $e->getMessage(),
                3, null, 'Cart', (int) $cart->id, true
            );

            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'paynetworx_transactions`
                 WHERE `id_cart` = ' . (int) $cart->id . ' AND `status` = \'pending\''
            );

            $this->errors[] = $this->module->l('A payment error occurred. Please try again or contact support.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=4');
        }
    }

    private function validateCardInputs($number, $month, $year, $cvc)
    {
        if (!ctype_digit($number) || strlen($number) < 13 || strlen($number) > 19) {
            $this->errors[] = $this->module->l('Invalid card number.', 'validation');
            return false;
        }

        if (!$this->luhnCheck($number)) {
            $this->errors[] = $this->module->l('Invalid card number.', 'validation');
            return false;
        }

        if ($month < 1 || $month > 12) {
            $this->errors[] = $this->module->l('Invalid expiry month.', 'validation');
            return false;
        }

        // $year has already been expanded to 4 digits by the caller
        $currentYear  = (int) date('Y');
        $currentMonth = (int) date('n');

        if ($year < $currentYear || ($year === $currentYear && $month < $currentMonth)) {
            $this->errors[] = $this->module->l('Your card has expired.', 'validation');
            return false;
        }

        if (strlen($cvc) < 3 || strlen($cvc) > 4) {
            $this->errors[] = $this->module->l('Invalid CVC.', 'validation');
            return false;
        }

        return true;
    }

    private function buildBillingData(Cart $cart, Customer $customer)
    {
        $address = new Address($cart->id_address_invoice);
        $country = new Country($address->id_country);

        $stateCode = '';
        if ($address->id_state) {
            $state     = new State((int) $address->id_state);
            $stateCode = $state->iso_code;
        }

        $data = [
            'Name'       => trim($address->firstname . ' ' . $address->lastname),
            'Line1'      => $address->address1,
            'City'       => $address->city,
            'PostalCode' => $address->postcode,
            'Country'    => $country->iso_code,
            'Email'      => $customer->email,
        ];

        if ($stateCode) {
            $data['State'] = $stateCode;
        }
        if (!empty($address->address2)) {
            $data['Line2'] = $address->address2;
        }

        $phone = !empty($address->phone) ? $address->phone : $address->phone_mobile;
        if ($phone) {
            $data['Phone'] = $phone;
        }

        return $data;
    }

    private function extractGatewayMessage(array $body)
    {
        foreach (['ResponseText', 'Error', 'Message'] as $key) {
            if (!empty($body[$key])) {
                return (string) $body[$key];
            }
        }
        return '';
    }

    private function luhnCheck($number)
    {
        $sum  = 0;
        $even = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];
            if ($even) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum  += $digit;
            $even  = !$even;
        }
        return ($sum % 10) === 0;
    }
}
