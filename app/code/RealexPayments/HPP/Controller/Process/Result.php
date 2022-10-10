<?php

namespace RealexPayments\HPP\Controller\Process;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Registry;

/**
 * Result implementation for Magento versions greater than or equal to 2.3.0
 */
class Result extends Result\Base implements CsrfAwareActionInterface
{
    /**
     * @var \RealexPayments\HPP\Helper\Data
     */
    private $_helper;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    private $_orderFactory;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private $_order;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_url;

    /**
     * Core registry.
     *
     * @var \Magento\Framework\Registry\Registry
     */
    private $coreRegistry;

    /**
     * @var \RealexPayments\HPP\Logger\Logger
     */
    private $_logger;

    /**
     * @var \RealexPayments\HPP\API\RealexPaymentManagementInterface
     */
    private $_paymentManagement;

    /**
     * Result constructor.
     *
     * @param \Magento\Framework\App\Action\Context                    $context
     * @param \RealexPayments\HPP\Helper\Data                          $helper
     * @param \Magento\Sales\Model\OrderFactory                        $orderFactory
     * @param Registry                              $coreRegistry
     * @param \RealexPayments\HPP\Logger\Logger                        $logger
     * @param \RealexPayments\HPP\API\RealexPaymentManagementInterface $paymentManagement
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \RealexPayments\HPP\Helper\Data $helper,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        Registry $coreRegistry,
        \RealexPayments\HPP\Logger\Logger $logger,
        \RealexPayments\HPP\API\RealexPaymentManagementInterface $paymentManagement
    ) {
        $this->_helper = $helper;
        $this->_orderFactory = $orderFactory;
        $this->_url = $context->getUrl();
        $this->coreRegistry = $coreRegistry;
        $this->_logger = $logger;
        $this->_paymentManagement = $paymentManagement;
        parent::__construct($context, $helper, $coreRegistry, $logger, $paymentManagement);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        try {
            $response = $this->getRequest()->getParams();
            //the default
            $params['returnUrl'] = $this->_url->getUrl('checkout/cart');

            if ($response) {
                $result = $this->_handleResponse($response);
                $params['returnUrl'] = $this->_url
                    ->getUrl('realexpayments_hpp/process/sessionresult', $this->_buildSessionParams($result));
            }
        } catch (\Exception $e) {
            $this->_logger->critical($e);
        }
        $this->coreRegistry->register(\RealexPayments\HPP\Block\Process\Result::REGISTRY_KEY, $params);

        $this->_view->loadLayout();
        $this->_view->getLayout()->initMessages();
        $this->_view->renderLayout();
    }

    /**
     * @param array $response
     *
     * @return bool
     */
    private function _handleResponse($response)
    {
        if (empty($response)) {
            $this->_logger->critical(__('Empty response received from gateway'));
            return false;
        }

        $this->_helper->logDebug(__('Gateway response:') . print_r($this->_helper->stripTrimFields($response), true));

        // validate response
        $authStatus = $this->_validateResponse($response);
        if (!$authStatus) {
            $this->_logger->critical(__('Invalid response received from gateway.'));

            return false;
        }
        //get the actual order id
        list($incrementId, $orderTimestamp) = explode('_', $response['ORDER_ID']);

        if ($incrementId) {
            $order = $this->_getOrder($incrementId);
            if ($order->getId()) {
                // process the response
                return $this->_paymentManagement->processResponse($order, $response);
            } else {
                $this->_logger->critical(__('Gateway response has an invalid order id.'));

                return false;
            }
        } else {
            $this->_logger->critical(__('Gateway response does not have an order id.'));

            return false;
        }
    }

    /**
     * Validate response using sha1 signature.
     *
     * @param array $response
     *
     * @return bool
     */
    private function _validateResponse($response)
    {
        $timestamp = $response['TIMESTAMP'];
        $result = $response['RESULT'];
        $orderid = $response['ORDER_ID'];
        $message = $response['MESSAGE'];
        $authcode = $response['AUTHCODE'];
        $pasref = $response['PASREF'];
        $realexsha1 = $response['SHA1HASH'];

        $merchantid = $this->_helper->getConfigData('merchant_id');

        $sha1hash = $this->_helper->signFields("$timestamp.$merchantid.$orderid.$result.$message.$pasref.$authcode");

        //Check to see if hashes match or not
        if ($sha1hash !== $realexsha1) {
            return false;
        }

        return true;
    }

    /**
     * Build params for the session redirect.
     *
     * @param bool $result
     *
     * @return array
     */
    private function _buildSessionParams($result)
    {
        $result = ($result) ? '1' : '0';
        $timestamp = strftime('%Y%m%d%H%M%S');
        $merchantid = $this->_helper->getConfigData('merchant_id');
        // if no order id exists
        if (!$this->_order) {
            return false;
        } else {
            $orderid = $this->_order->getIncrementId();
        }
        $sha1hash = $this->_helper->signFields("$timestamp.$merchantid.$orderid.$result");

        return ['timestamp' => $timestamp, 'order_id' => $orderid, 'result' => $result, 'hash' => $sha1hash];
    }

    /**
     * Get order based on increment id.
     *
     * @param $incrementId
     *
     * @return \Magento\Sales\Model\Order
     */
    private function _getOrder($incrementId)
    {
        if (!$this->_order) {
            $this->_order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
        }

        return $this->_order;
    }

    public function createCsrfValidationException(\Magento\Framework\App\RequestInterface $request): ?\Magento\Framework\App\Request\InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool
    {
        return true;
    }
}
