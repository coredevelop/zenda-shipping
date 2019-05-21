<?php
/**
 * @author Zenda
 * @copyright Copyright (c) 2019 Zenda (https://www.zenda.global/)
 * @package Zenda_Shipping
 */

namespace Zenda\Shipping\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    const XML_ZENDA_SHIPPING_TOOLTIP_LABEL = 'carriers/zenda/tooltip_label';
    const XML_ZENDA_SHIPPING_TOOLTIP_CONTENT = 'carriers/zenda/tooltip_content';

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->_scopeConfig = $scopeConfig;
    }

    public function getConfig()
    {
        $tooltipLabel = $this->_scopeConfig->getValue(
            self::XML_ZENDA_SHIPPING_TOOLTIP_LABEL
        );

        $tooltipContent = $this->_scopeConfig->getValue(
            self::XML_ZENDA_SHIPPING_TOOLTIP_CONTENT
        );

        return [
            'zenda_shipping_tooltip_label' => $tooltipLabel,
            'zenda_shipping_tooltip_content' => $tooltipContent,
        ];
    }
}