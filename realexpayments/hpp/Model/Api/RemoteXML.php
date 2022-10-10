<?php

namespace RealexPayments\HPP\Model\Api;

use RealexPayments\HPP\Model\Config\Source\SettleMode;

class RemoteXML implements \RealexPayments\HPP\Api\RemoteXMLInterface
{
    /**
     * @var \RealexPayments\HPP\Helper\Data
     */
    private $_helper;

    /**
     * @var \RealexPayments\HPP\Logger\Logger
     */
    private $_logger;

    /**
     * @var \RealexPayments\HPP\Model\Api\Request\RequestFactory
     */
    private $_requestFactory;

    /**
     * @var \RealexPayments\HPP\Model\Api\Response\ResponseFactory
     */
    private $_responseFactory;

    /**
     * @var \Magento\Sales\Model\Order\Status\HistoryFactory
     */
    private $_orderHistoryFactory;

    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    private $_transactionRepository;

    /**
     * RemoteXML constructor.
     *
     * @param \RealexPayments\HPP\Helper\Data                        $helper
     * @param \RealexPayments\HPP\Logger\Logger                      $logger
     * @param \RealexPayments\HPP\Model\Api\Request\RequestFactory   $requestFactory
     * @param \RealexPayments\HPP\Model\Api\Response\ResponseFactory $responseFactory
     * @param \Magento\Sales\Api\TransactionRepositoryInterface      $transactionRepository
     */
    public function __construct(
        \RealexPayments\HPP\Helper\Data $helper,
        \RealexPayments\HPP\Logger\Logger $logger,
        \RealexPayments\HPP\Model\Api\Request\RequestFactory $requestFactory,
        \RealexPayments\HPP\Model\Api\Response\ResponseFactory $responseFactory,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
    ) {
        $this->_helper = $helper;
        $this->_logger = $logger;
        $this->_requestFactory = $requestFactory;
        $this->_responseFactory = $responseFactory;
        $this->_transactionRepository = $transactionRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function settle($payment, $amount)
    {
        $order = $payment->getOrder();
        $storeId = $order->getStoreId();
        $additional = $payment->getAdditionalInformation();
        $is_paypal = !empty($additional['PAYMENTMETHOD']) && $additional['PAYMENTMETHOD'] == 'paypal';

        $request = $this->_requestFactory->create();
        if ($is_paypal) {
            $request->setPaymentMethod('paypal');
            $request->setType(Request\Request::TYPE_PAYMENT_SETTLE);
        } else {
            $request->setType(Request\Request::TYPE_SETTLE);
        }
        $request = $request
                    ->setStoreId($storeId)
                    ->setMerchantId($additional['MERCHANT_ID'])
                    ->setOrderId($additional['ORDER_ID'])
                    ->setPasref($additional['PASREF'])
                    ->setAmount($amount)
                    ->setCurrency($order->getBaseCurrencyCode())
                    ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function multisettle($payment, $amount, $complete = false)
    {
        $order = $payment->getOrder();
        $storeId = $order->getStoreId();
        $additional = $payment->getAdditionalInformation();
        $is_paypal = !empty($additional['PAYMENTMETHOD']) && $additional['PAYMENTMETHOD'] == 'paypal';

        $request = $this->_requestFactory->create();
        if ($is_paypal) {
            $request->setPaymentMethod('paypal');
            $request->setType(Request\Request::TYPE_PAYMENT_SETTLE);

            $request->setMultiSettleType($complete ? 'complete' : 'partial');
        } else {
            $request->setType(Request\Request::TYPE_MULTISETTLE);
        }
        $request = $request
                    ->setStoreId($storeId)
                    ->setMerchantId($additional['MERCHANT_ID'])
                    ->setOrderId($additional['ORDER_ID'])
                    ->setPasref($additional['PASREF'])
                    ->setAccount($additional['ACCOUNT'])
                    ->setAuthCode($additional['AUTHCODE'])
                    ->setAmount($amount)
                    ->setCurrency($order->getBaseCurrencyCode())
                    ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function rebate($payment, $amount, $comments)
    {
        $storeId = $payment->getOrder()->getStoreId();
        $refundhash = sha1(
            $this->_helper->setStoreId($storeId)->getEncryptedConfigData('rebate_secret')
        );
        $transaction = $this->_getTransaction($payment);
        $additional = $this->_toStrUpperArrayKeys($payment->getAdditionalInformation());
        $is_apm = !empty($additional['PAYMENTMETHOD']);
        $orderId = !empty($additional['ORDERID']) ? $additional['ORDERID'] : $additional['ORDER_ID'];
        $multiSettleMode = !empty($additional['AUTO_SETTLE_FLAG'])
            && $additional['AUTO_SETTLE_FLAG'] === SettleMode::SETTLEMODE_MULTI;

        if ($multiSettleMode && !$is_apm) {
            $orderId = '_multisettle_' . $additional['ORDER_ID'];
        }

        $transactionAdditionalInfo = $this->_toStrUpperArrayKeys(
            $transaction->getAdditionalInformation(
                \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS
            )
        );

        if (!empty($transactionAdditionalInfo['PASREF']) && ($multiSettleMode || $is_apm)) {
            $pasref = $transactionAdditionalInfo['PASREF'];
        } else {
            $pasref = !empty($additional['PASREF']) ? $additional['PASREF'] : '';
        }

        $request = $this->_requestFactory->create();
        if ($is_apm) {
            $request->setPaymentMethod($additional['PAYMENTMETHOD']);
            $request->setType(Request\Request::TYPE_PAYMENT_CREDIT);
        } else {
            $request->setType(Request\Request::TYPE_REBATE);
        }

        if (isset($additional['AUTHCODE'])) {
            $request->setAuthCode($additional['AUTHCODE']);
        }

        $request = $request->setStoreId($storeId)
                  ->setMerchantId($additional['MERCHANT_ID'])
                  ->setAccount($additional['ACCOUNT'])
                  ->setOrderId($orderId)
                  ->setPasref($pasref)
                  ->setAmount($amount)
                  ->setCurrency($payment->getOrder()->getBaseCurrencyCode())
                  ->setComments($comments)
                  ->setRefundHash($refundhash)
                  ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function void($payment, $comments)
    {
        $storeId = $payment->getOrder()->getStoreId();
        $transaction = $this->_getTransaction($payment);
        $additional = $payment->getAdditionalInformation();
        $orderId = $additional['ORDER_ID'];
        if ($additional['AUTO_SETTLE_FLAG'] == SettleMode::SETTLEMODE_MULTI) {
            $rawFields = $transaction->getAdditionalInformation(
                \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS
            );
            $pasref = $rawFields['PASREF'];
        } else {
            $pasref = $additional['PASREF'];
        }

        $is_paypal = !empty($additional['PAYMENTMETHOD']) && $additional['PAYMENTMETHOD'] == 'paypal';

        $request = $this->_requestFactory->create();
        if ($is_paypal) {
            $request->setPaymentMethod('paypal');
            $request->setType(Request\Request::TYPE_PAYMENT_VOID);
        } else {
            $request->setType(Request\Request::TYPE_VOID);
        }
        $request = $request
                  ->setStoreId($storeId)
                  ->setMerchantId($additional['MERCHANT_ID'])
                  ->setAccount($additional['ACCOUNT'])
                  ->setOrderId($orderId)
                  ->setPasref($pasref)
                  ->setComments($comments)
                  ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function payerEdit($merchantId, $account, $payerRef, $customer)
    {
        $storeId = $customer->getStoreId();

        $request = $this->_requestFactory->create()
                  ->setStoreId($storeId)
                  ->setType(Request\Request::TYPE_PAYER_EDIT)
                  ->setMerchantId($merchantId)
                  ->setAccount($account)
                  ->setOrderId(uniqid())
                  ->setPayerRef($payerRef)
                  ->setPayer($customer)
                  ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function releasePayment($payment, $comments)
    {
        $storeId = $payment->getOrder()->getStoreId();
        $additional = $payment->getAdditionalInformation();
        $request = $this->_requestFactory->create()
                    ->setStoreId($storeId)
                    ->setType(Request\Request::TYPE_RELEASE)
                    ->setMerchantId($additional['MERCHANT_ID'])
                    ->setAccount($additional['ACCOUNT'])
                    ->setOrderId($additional['ORDER_ID'])
                    ->setPasref($additional['PASREF'])
                    ->setComments($comments)
                    ->setStoreId($payment->getOrder()->getStoreId())
                    ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function holdPayment($payment, $comments)
    {
        $storeId = $payment->getOrder()->getStoreId();
        $additional = $payment->getAdditionalInformation();
        $request = $this->_requestFactory->create()
                    ->setStoreId($storeId)
                    ->setType(Request\Request::TYPE_HOLD)
                    ->setMerchantId($additional['MERCHANT_ID'])
                    ->setAccount($additional['ACCOUNT'])
                    ->setOrderId($additional['ORDER_ID'])
                    ->setPasref($additional['PASREF'])
                    ->setComments($comments)
                    ->build();

        return $this->_sendRequest($request);
    }

    /**
     * {@inheritdoc}
     */
    public function query($payment)
    {
        $additional = $payment->getAdditionalInformation();
        $request = $this->_requestFactory->create()
            ->setType(Request\Request::TYPE_QUERY)
            ->setMerchantId($additional['MERCHANT_ID'])
            ->setOrderId($additional['ORDER_ID'])
            ->setAccount($additional['ACCOUNT'])
            ->build();

        return $this->_sendRequest($request, Request\Request::TYPE_QUERY);
    }

    /**
     * @desc Send the request to the remote xml api
     *
     * @param  string  $request
     * @param  string  $requestType
     *
     * @return \RealexPayments\HPP\Model\Api\Response\Response
     */
    private function _sendRequest($request, $requestType = '')
    {
        $url = $this->_helper->getRemoteApiUrl();
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        if (!empty($request)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($httpStatus != '200') {
            $this->_helper->logDebug(print_r(['status' => $httpStatus, 'body' => $response], true));

            return false;
        }

        return $this->_responseFactory->create()->parse($response, $requestType);
    }

    private function _getTransaction($payment)
    {
        $transaction = $this->_transactionRepository->getByTransactionId(
            $payment->getParentTransactionId(),
            $payment->getId(),
            $payment->getOrder()->getId()
        );

        return $transaction;
    }

    private function _toStrUpperArrayKeys($array)
    {
        $new_array = array();
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $key = strtoupper($key);
            }
            $new_array[$key] = $value;
        }

        return $new_array;
    }
}
