<?php
/**
 * @author Zenda
 * @copyright Copyright (c) 2019 Zenda (https://www.zenda.global/)
 * @package Zenda_Shipping
 */

namespace Zenda\Shipping\Model;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Quote\Model\Quote\Address\RateResult\Error;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'zenda';

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $_rateMethodFactory;

    /**
     * @var \Zenda\Shipping\Model\Service
     */
    protected $_service;

    /**
     * @var \Magento\Directory\Model\CountryFactory
     */
    protected $_countryFactory;

    /**
     * @var string
     */
    protected $_countryIsoCode = 'iso3_code';

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateRequest
     */
    protected $_request;

    /**
     * @var Shipping
     */
    protected $_shipping;

    /**
     * @var float
     */
    protected $_epsilon = 0.00001;

    /**
     * @var string
     */
    protected $_dynamicText = ' (%1%2 shipping + %3%4 prepaid tax and duty)';

    /**
     * Carrier constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param Service $service
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param Shipping $shipping
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        Service $service,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        Shipping $shipping,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_service = $service;
        $this->_countryFactory = $countryFactory;
        $this->_shipping = $shipping;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Returns allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    /**
     * Zenda shipping Rates Collector
     *
     * @param RateRequest $request
     *
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $this->_request = $request;

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        $shippingPrice = $this->_getShippingPrice();
        $taxAndDuty = $this->_getTaxAndDuty($shippingPrice);

        // Get all errors
        $apiErrors = $this->_service->getErrors();
        $shippingErrors = $this->_shipping->getErrors();
        $errors = array_merge($apiErrors, $shippingErrors);

        if (!empty($errors)) {
            $this->_debug($errors);
            return $this->getErrorMessage();
        } elseif ($shippingPrice > $this->_epsilon) {
            $totalShippingPrice = $shippingPrice + $taxAndDuty;
            $method = $this->_createResultMethod($totalShippingPrice, $shippingPrice, $taxAndDuty);
            $result->append($method);
        }

        return $result;
    }

    /**
     * Get the total shipping cost for all parcels
     *
     * @return bool|float
     */
    protected function _getShippingPrice()
    {
        $totalShippingPrice = 0.00;

        if ($this->getConfigFlag('enable_flat_rate')) {
            $totalShippingPrice = (float)$this->getConfigData('flat_rate_price');
        } else {
            $packages = $this->_shipping->composePackages($this, $this->_request);
            if (!empty($packages)) {
                foreach ($packages as $key => $package) {
                    // Sum up the shipping price
                    $totalShippingPrice += $this->_service->getShippingPrice(
                        $this->_getPackageCurrencyCode(),
                        $this->_getOriginDetails(),
                        $this->_getDestinationDetails(),
                        (float)$package['weight'],
                        (float)$package['volume']
                    );
                }
            }
        }

        return $totalShippingPrice;
    }

    /**
     * Get the cart's tax and duty
     *
     * @param $shippingPrice
     * @return float
     */
    protected function _getTaxAndDuty($shippingPrice)
    {
        $products = [];
        foreach ($this->_request->getAllItems() as $item) {
            $products[] = [
                'SKUCode' => $item->getProduct()->getSku(),
                'description' => $item->getProduct()->getName(),
                'value' => $item->getPrice(),
                'qty' => $item->getQty()
            ];
        }

        $totalTaxAndDuty = $this->_service->getTaxAndDuty(
            $shippingPrice,
            $this->_getOriginDetails()['countryCode'],
            $this->_getDestinationDetails()['countryCode'],
            $this->_request->getPackageValue(),
            $this->_getPackageCurrencyCode(),
            $products
        );

        return $totalTaxAndDuty;
    }

    /**
     * Get package currency code (e.g. USD)
     *
     * @return mixed
     */
    protected function _getPackageCurrencyCode()
    {

        return $this->_request->getPackageCurrency()->getCode();
    }

    /**
     * Get the package currency symbol (e.g. $)
     *
     * @return mixed
     */
    protected function _getPackageCurrencySymbol()
    {
        return $this->_request->getPackageCurrency()->getCurrencySymbol();
    }

    /**
     * @param $totalShippingPrice
     * @param int|float $shippingPrice
     * @param $taxAndDuty
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    protected function _createResultMethod($totalShippingPrice, $shippingPrice, $taxAndDuty)
    {
        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->getCarrierCode());
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $dynamicText = __(
            $this->_dynamicText,
            $this->_getPackageCurrencySymbol(),
            number_format($shippingPrice, 2),
            $this->_getPackageCurrencySymbol(),
            number_format($taxAndDuty, 2)
        );
        $method->setMethodTitle($this->getConfigData('name') . $dynamicText);

        $method->setPrice($totalShippingPrice);
        $method->setCost($totalShippingPrice);

        return $method;
    }

    /**
     * Get origin country details
     *
     * @return array
     */
    protected function _getOriginDetails()
    {
        if ($this->_request->getOrigCountry()) {
            $countryId = $this->_request->getOrigCountry();
        } else {
            $countryId = $this->_scopeConfig->getValue(
                \Magento\Sales\Model\Order\Shipment::XML_PATH_STORE_COUNTRY_ID,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $this->_request->getStoreId()
            );
        }

        $countryCode = $this->_countryFactory->create()->load($countryId)->getData($this->_countryIsoCode);

        if ($this->_request->getOrigPostcode()) {
            $postalCode = $this->_request->getOrigPostcode();
        } else {
            $postalCode =
                $this->_scopeConfig->getValue(
                    \Magento\Sales\Model\Order\Shipment::XML_PATH_STORE_ZIP,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $this->_request->getStoreId()
                );
        }

        return [
            'countryCode' => $countryCode,
            'postalCode' => $postalCode
        ];
    }

    /**
     * Get destination country details
     *
     * @return array
     */
    protected function _getDestinationDetails()
    {
        if ($this->_request->getDestCountryId()) {
            $destCountryId = $this->_request->getDestCountryId();
        } else {
            $destCountryId = self::USA_COUNTRY_ID;
        }

        $countryCode = $this->_countryFactory->create()->load($destCountryId)->getData($this->_countryIsoCode);

        if ($this->_request->getDestPostcode()) {
            $postalCode = $this->_request->getDestPostcode();
        } else {
            $postalCode = '';
        }

        return [
            'countryCode' => $countryCode,
            'postalCode' => $postalCode
        ];
    }

    /**
     * Get error messages
     *
     * @return bool|Error
     */
    protected function getErrorMessage()
    {
        if ($this->getConfigData('showmethod')) {
            /* @var $error Error */
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->getCarrierCode());
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            return $error;
        } else {
            return false;
        }
    }
}
