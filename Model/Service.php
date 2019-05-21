<?php
/**
 * @author Zenda
 * @copyright Copyright (c) 2019 Zenda (https://www.zenda.global/)
 * @package Zenda_Shipping
 */

namespace Zenda\Shipping\Model;

class Service
{
    /**
     * @var string
     */
    protected $_code = 'zenda';

    /**
     * API Test Url
     *
     * @var string
     */
    protected $_testApiUrl = 'https://uat2-api.zenda.global/v1/';

    /**
     * API Production Url
     *
     * @var string
     */
    protected $_productionApiUrl = 'https://prd-api.zenda.global/v1/';

    /**
     * API Scope
     *
     * @var string
     */
    protected $_scope = 'openid';

    /**
     * Connection timeout
     *
     * @var int
     */
    protected $_connectionTimeout = 30;

    /**
     * @var int
     */
    protected $_maxRedirects = 0;

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory
     */
    protected $_httpClientFactory;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    protected $_serializer;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_datetime;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * Errors placeholder
     *
     * @var string[]
     */
    protected $_errors = [];

    /**
     * @var \Magento\Framework\Session\SessionManagerInterface
     */
    protected $_coreSession;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Session\SessionManagerInterface $coreSession
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_httpClientFactory = $httpClientFactory;
        $this->_serializer = $serializer;
        $this->_datetime = $dateTime;
        $this->_storeManager = $storeManager;
        $this->_coreSession = $coreSession;
    }

    /**
     * Get the base API URL
     *
     * If is_account_live is set to Yes, it will be the production API URL.
     * Else, it will get the development API URL
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getBaseApiUrl()
    {
        return ($this->_getConfigFlag('is_account_live'))
            ? $this->_productionApiUrl
            : $this->_testApiUrl;
    }

    /**
     * User authorization URL
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getAuthorizationUrl()
    {
        return $this->getBaseApiUrl() . 'token';
    }


    /**
     * URL for retrieving shipping cost (no tax and duty)
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getShippingCostUrl()
    {
        return $this->getBaseApiUrl() . 'quotes/shipments';
    }

    /**
     * URL for retrieving tax and duty for the shopping cart
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCartTaxAndDutyUrl()
    {
        return $this->getBaseApiUrl() . 'quotes/baskets';
    }


    /**
     *  Send the HTTP request and return an HTTP response object
     *
     * @param $url
     * @param $additionalHeader
     * @param $requestBody
     * @param string $method
     * @param bool $decodeResponseBody
     *
     * @return mixed|string
     * @throws \Zend_Http_Client_Exception
     */
    private function _request(
        $url,
        $additionalHeader,
        $requestBody,
        $method = \Zend_Http_Client::POST,
        $decodeResponseBody = true
    ) {
        $client = $this->_httpClientFactory->create();
        $client->setUri($url);
        $client->setConfig([
                'maxredirects' => $this->_maxRedirects,
                'timeout'      => $this->_connectionTimeout
            ]
        );
        $headers = ['Content-Type: application/json'];
        if ($additionalHeader) {
            $headers = array_merge(
                $headers,
                $additionalHeader
            );
        }
        $client->setHeaders($headers);
        $client->setMethod($method);
        $client->setRawData(utf8_encode($this->_serializer->serialize($requestBody)));

        $responseBody = $client->request($method)->getBody();
        if ($decodeResponseBody) {
            $responseBody = $this->_serializer->unserialize($responseBody);
        }

        return $responseBody;
    }

    /**
     * Authenticate the user to get the token details
     *
     * @return $this
     * @throws \Zend_Http_Client_Exception
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function authenticate()
    {
        if ($this->_isAccessTokenExpired()) {
            $requestBody = [
                'username' => $this->_getConfigData('username'),
                'password' => $this->_getConfigData('password'),
                'scope'    => $this->_scope
            ];

            $responseBody = $this->_request(
                $this->getAuthorizationUrl(),
                false,
                $requestBody
            );

            // Save the authentication details into the session if there's an access token fetched
            if (isset($responseBody['access_token'])) {
                // Calculate when will the access token expires then save it to session
                $accessTokenExpiry = $responseBody['expires_in'];
                $accessTokenExpiry = time() + $accessTokenExpiry;
                $responseBody['access_token_expires_at'] = $accessTokenExpiry;

                $this->_coreSession->setAuthDetails($responseBody);
            }

            if (!$responseBody || !isset($responseBody['access_token'])) {
                $msg = "API connection could not be established. {$responseBody['error']}: {$responseBody['error_description']}.";
                throw new \Exception($msg);
            }
        }

        return $this;
    }

    /**
     * Check if access token is invalid
     *
     * @return bool
     */
    private function _isAccessTokenExpired()
    {
        $authDetails = $this->_getAuthDetails();
        $accessTokenExpired = true;

        if (isset($authDetails['access_token']) && isset($authDetails['access_token_expires_at'])) {
            $accessTokenExpiry = $authDetails['access_token_expires_at'];
            $currentTime = $this->_datetime->timestamp();
            $accessTokenExpired = ($accessTokenExpiry < $currentTime);
        }

        return $accessTokenExpired;
    }

    /**
     * Get the saved authentication details from session
     *
     * @return mixed
     */
    private function _getAuthDetails()
    {
        return $this->_coreSession->getAuthDetails();
    }

    /**
     * Get the saved access token
     */
    private function _getAccessToken()
    {
        $authDetails = $this->_getAuthDetails();
        if (isset($authDetails['access_token'])) {
            return $authDetails['access_token'];
        }

        return false;
    }

    /**
     * Get the shipping price
     *
     * @param $currencyCode
     * @param $originDetails
     * @param $destinationDetails
     * @param $packageWeight
     * @param $packageVolume
     *
     * @return bool|float
     */
    public function getShippingPrice(
        $currencyCode,
        $originDetails,
        $destinationDetails,
        $packageWeight,
        $packageVolume
    ) {
        $requestBody = [
            'serviceLevel' => '',
            'origin'       => [
                'postalCode'  => $originDetails['postalCode'],
                'countryCode' => $originDetails['countryCode']
            ],
            'destination'  => [
                'postalCode'  => $destinationDetails['postalCode'],
                'countryCode' => $destinationDetails['countryCode']
            ],
            'currencyCode' => $currencyCode,
            'parcel'       => [
                'metrics' => [
                    [
                        'metricType'  => 'WEIGHT',
                        'metricValue' => $packageWeight,
                        'metricUnit'  => $this->getWeightUnit()
                    ],
                    [
                        'metricType'  => 'VOLUME',
                        'metricValue' => $packageVolume,
                        'metricUnit'  => $this->getVolumeUnit()
                    ]
                ]
            ]
        ];

        $shippingPrice = 0.00;

        try {
            $this->authenticate();

            if ($this->_getAccessToken()) {
                $responseBody = $this->_request(
                    $this->getShippingCostUrl(),
                    ['token: ' . $this->_getAccessToken()],
                    $requestBody
                );
                if (isset($responseBody[0]) && isset($responseBody[0]['cost']['value'])) {
                    $shippingPrice = $responseBody[0]['cost']['value'];
                } elseif (isset($responseBody['alerts'][0]['code'])
                    && isset($responseBody['alerts'][0]['message'])
                ) {
                    $msg = $responseBody['alerts'][0]['message'];
                    throw new \Exception($msg);
                }
            }
        } catch (\Exception $e) {
            $this->_errors[] = $e->getCode() . " - " . $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        return $shippingPrice;
    }

    /**
     * Get the final tax and duty for the entire shopping cart value and contents
     *
     * @param $shippingPrice
     * @param $sourceCountry
     * @param $destinationCountry
     * @param $currentCartTotal
     * @param $cartCurrencyCode
     * @param $products
     *
     * @return float
     */
    public function getTaxAndDuty(
        $shippingPrice,
        $sourceCountry,
        $destinationCountry,
        $currentCartTotal,
        $cartCurrencyCode,
        $products
    ) {
        $requestBody = [
            'sourceCountry'      => $sourceCountry,
            'shippingPrice'      => $shippingPrice,
            'destinationCountry' => $destinationCountry,
            'currentCartValue'   => $currentCartTotal,
            'cartCurrencyCode'   => $cartCurrencyCode,
            'products'           => $products
        ];

        $totalTaxAndDuty = 0.00;

        try {
            $this->authenticate();

            if ($this->_getAccessToken()) {
                $responseBody = $this->_request(
                    $this->getCartTaxAndDutyUrl(),
                    ['token: ' . $this->_getAccessToken()],
                    $requestBody
                );
                if (isset($responseBody['response'][0]['totalTax'])
                    && isset($responseBody['response'][0]['totalDuty'])
                ) {
                    $totalTax = $responseBody['response'][0]['totalTax'];
                    $totalDuty = $responseBody['response'][0]['totalDuty'];
                    $totalTaxAndDuty = $totalTax + $totalDuty;
                } elseif (isset($responseBody['alerts'][0]['code'])
                    && isset($responseBody['alerts'][0]['message'])
                ) {
                    $msg = $responseBody['alerts'][0]['message'];
                    if (isset($responseBody['commodityException'][0]['exceptionMessage'])) {
                        $msg .= ': ' . $responseBody['commodityException'][0]['exceptionMessage'];
                    }
                    throw new \Exception($msg);
                }
            }
        } catch (\Exception $e) {
            $this->_errors[] = $e->getCode() . " - " . $e->getMessage() . "\n" . $e->getTraceAsString();
        }

        return $totalTaxAndDuty;
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     *  Returns weight unit (LB)
     *
     * @return bool|mixed
     */
    public function getWeightUnit()
    {
        $weightUnit =
            $this->_scopeConfig->getValue(
                \Magento\Directory\Helper\Data::XML_PATH_WEIGHT_UNIT,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $this->_storeManager->getStore()->getId()
            );

        return $this->_unitMap($weightUnit);
    }

    /**
     * Returns dimension unit (IN3)
     *
     * @return bool|mixed
     */
    public function getVolumeUnit()
    {
        return $this->_unitMap('inch'); // Hardcode dimension unit for now
    }

    /**
     * Mapping for the units
     *
     * @param $val
     * @return bool|mixed
     */
    protected function _unitMap($val)
    {
        $units = [
            'lbs' => 'LB',
            'kgs' => 'KG',
            'inch' => 'IN3',
            'cm' => 'CM3'
        ];

        if (isset($units[$val])) {
            return $units[$val];
        }

        return false;
    }

    /**
     * Retrieve information from carrier configuration
     *
     * @param   string $field
     *
     * @return  false|string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _getConfigData($field)
    {
        if (empty($this->_code)) {
            return false;
        }
        $path = 'carriers/' . $this->_code . '/' . $field;

        return $this->_scopeConfig->getValue(
            $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->_storeManager->getStore()->getId()
        );
    }

    /**
     * Retrieve config flag for store by field
     *
     * @param string $field
     *
     * @return bool
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     * @api
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _getConfigFlag($field)
    {
        if (empty($this->_code)) {
            return false;
        }
        $path = 'carriers/' . $this->_code . '/' . $field;

        return $this->_scopeConfig->isSetFlag(
            $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->_storeManager->getStore()->getId()
        );
    }
}