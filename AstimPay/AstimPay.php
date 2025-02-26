<?php

/**
 * AstimPay FOSSBilling Gateway Module
 *
 * Copyright (c) 2024 AstimPay
 * Website: https://astimpay.com
 * Email: info@astimpay.com
 * Developer: AstimPay Team
 * 
 */


class Payment_Adapter_AstimPay extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    private $config = [];

    protected ?\Pimple\Container $di;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (!isset($this->config['api_key'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'AstimPay', ':missing' => 'API KEY']);
        }

        if (!isset($this->config['api_url'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'AstimPay', ':missing' => 'API URL']);
        }

        if (!isset($this->config['exchange_rate'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'AstimPay', ':missing' => 'USD to BDT exchange rate [1 USD = ? BDT]']);
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments'   =>  true,
            'description'     =>  'Simplify Your Payment Management with AstimPay',
            'logo' => [
                'logo' => 'AstimPay/AstimPay.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form'  => [
                'api_key' => [
                    'text', [
                        'label' => 'API key:',
                    ],
                ],
                'api_url' => [
                    'text', [
                        'label' => 'API URL (V1):',
                    ],
                ],
                'exchange_rate' => [
                    'text', [
                        'label' => 'USD to BDT exchange rate [1 USD = ? BDT]:',
                    ],
                ]
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);

        $data = $this->apGetPaymentFields($invoice);

        $url = $this->apInitPayment($data);

        return $this->apGenerateForm($url);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if (!$this->upIsIpnValid($data)) {
            throw new Payment_Exception('Invalid Request');
        }

        $response = $this->apVerifyPayment($data);

        if (isset($response['status']) && $response['status'] == 'COMPLETED') {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $response['metadata']['invoice_id'], 'Invoice not found');
            $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');
            $amount = $this->apCurrencyConvertForTransaction($response['amount'], $response['metadata']['currency']);

            $tx_data = [
                'id'            =>  $id,
                'invoice_id'    =>  $response['metadata']['invoice_id'],
                'txn_status'    =>  $response['status'],
                'txn_id'        =>  $response['transaction_id'],
                'amount'        =>  $amount,
                'currency'      =>  $invoice->currency,
                'type'          =>  $response['payment_method'],
                'status'        =>  'complete',
            ];

            $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
            $transactionService->update($transaction, $tx_data);

            $bd = [
                'amount'        =>  $amount,
                'description'   =>  $response['payment_method'] . ' Transaction ID: ' . $response['transaction_id'],
                'type'          =>  'transaction',
                'rel_id'        =>  $transaction->id,
            ];

            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
            $clientService = $this->di['mod_service']('client');

            if ($this->apIsIpnDuplicate($response)) {
                throw new Payment_Exception('Cannot process duplicate IPN');
            }

            $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceService->payInvoiceWithCredits($invoice);
            $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);

            $this->apDoRredirect($response['metadata']['return_url']);
        } else {
            throw new Payment_Exception('Invalid Request');
        }
    }

    /**
     * Set Payment Fields for AstimPay
     * @var array $invoice
     */
    public function apGetPaymentFields($invoice)
    {
        $first_name = isset($invoice['client']['first_name']) ? $invoice['client']['first_name'] : "John";
        $last_name = isset($invoice['client']['last_name']) ? $invoice['client']['last_name'] : "";
        $email = isset($invoice['client']['email']) ? $invoice['client']['email'] : "test@test.com";

        $fields = [
            'full_name'     => $first_name . ' ' . $last_name,
            'email'         => $email,
            'amount'        => $this->apCurrencyConvert($invoice['subtotal'], $invoice['currency']),
            'metadata'      => [
                'invoice_id'    => $invoice['id'],
                'currency'      => $invoice['currency'],
                'return_url'    => $this->config['return_url']
            ],
            'redirect_url'  =>   $this->config['notify_url'],
            'return_type'   => 'GET',
            'cancel_url'    => $this->config['cancel_url'],
            'webhook_url'   => $this->config['notify_url']
        ];

        return $fields;
    }

    /**
     * Generate Payment Form
     * @var string $url
     * @var string $method
     */
    private function apGenerateForm($url, $method = 'get')
    {
        $form  = '';
        $form .= '<form name="payment_form" action="' . $url . '" method="' . $method . '">' . PHP_EOL;
        $form .=  '<input class="bb-button bb-button-submit" type="submit" value="Pay Now" id="payment_button"/>' . PHP_EOL;
        $form .=  '</form>' . PHP_EOL . PHP_EOL;

        if (isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
            $form .= sprintf('<h2>%s</h2>', __trans('Redirecting to Payment Page...'));
            $form .= "<script type='text/javascript'>$(document).ready(function(){    document.getElementById('payment_button').style.display = 'none';    document.forms['payment_form'].submit();});</script>";
        }

        return $form;
    }

    /**
     * Init Payment
     * @var array $requestData
     */
    private function apInitPayment($requestData)
    {
        $host = parse_url($this->config['api_url'],  PHP_URL_HOST);
        $apiUrl = "https://{$host}/api/checkout-v1";

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                "API-KEY: " . $this->config['api_key'],
                "accept: application/json",
                "content-type: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Payment_Exception("cURL Error #:" . $err);
        } else {
            $result = json_decode($response, true);
            if (isset($result['status']) && isset($result['payment_url'])) {
                return $result['payment_url'];
            } else {
                throw new Payment_Exception($result['message']);
            }
        }
        throw new Payment_Exception("Please recheck configurations");
    }

    /**
     * Verify Payment
     * @var array $data
     */
    private function apVerifyPayment($data)
    {
        $raw_data = json_decode($data['http_raw_post_data'], true);
        if (empty($raw_data)) {
            $raw_data = $data['get'];
        }

        $invoice_id = $raw_data['invoice_id'];

        $host = parse_url($this->config['api_url'],  PHP_URL_HOST);
        $verifyUrl = "https://{$host}/api/verify-payment";

        $invoice_data = [
            'invoice_id'    => $invoice_id
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $verifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($invoice_data),
            CURLOPT_HTTPHEADER => [
                "API-KEY: " . $this->config['api_key'],
                "accept: application/json",
                "content-type: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Payment_Exception("cURL Error #:" . $err);
        } else {
            return json_decode($response, true);
        }
        throw new Payment_Exception("Please recheck configurations");
    }


    public function apDoRredirect($url)
    {
        header('Location:' . $url);
        exit;
    }

    private function upIsIpnValid($data)
    {
        $raw_data = json_decode($data['http_raw_post_data'], true);
        return isset($data['get']['invoice_id']) || isset($raw_data['invoice_id']) ? true : false;
    }

    public function apIsIpnDuplicate($response)
    {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                  AND txn_status = :transaction_status
                  AND type = :transaction_type
                  AND amount = :transaction_amount
                LIMIT 2';

        $bindings = [
            ':transaction_id' => $response['transaction_id'],
            ':transaction_status' => $response['status'],
            ':transaction_type' => $response['payment_method'],
            ':transaction_amount' => $response['amount'],
        ];

        $rows = $this->di['db']->getAll($sql, $bindings);
        if (count($rows) > 1) {
            return true;
        }


        return false;
    }

    public function apCurrencyConvert($amount, $currency = null)
    {
        if ($currency == 'BDT') {
            return $amount;
        }

        return $amount * $this->config['exchange_rate'];
    }

    public function apCurrencyConvertForTransaction($amount, $currency = null)
    {
        if ($currency == 'BDT') {
            return $amount;
        }
        return $amount / $this->config['exchange_rate'];
    }
}
