<?php
/**
 * @author Zenda
 * @copyright Copyright (c) 2019 Zenda (https://www.zenda.global/)
 * @package Zenda_Shipping
 */

namespace Zenda\Shipping\Model\Config\Source;

class Country extends \Magento\Directory\Model\Config\Source\Country
{
    /**
     * Countries
     *
     * @var \Magento\Directory\Model\ResourceModel\Country\Collection
     */
    protected $_countryCollection;

    /**
     * Zenda only ships to these specific countries
     *
     * @var array
     */
    protected $_specificCountries = [
        'AT',
        'BE',
        'BG',
        'HR',
        'CY',
        'CZ',
        'EE',
        'FR',
        'DE',
        'GR',
        'HU',
        'IE',
        'IT',
        'LV',
        'LT',
        'LU',
        'MT',
        'NL',
        'PL',
        'PT',
        'RO',
        'SK',
        'SI',
        'ES',
        'GB'
    ];

    /**
     * @var array
     */
    protected $_options;

    /**
     * Country constructor.
     *
     * @param \Magento\Directory\Model\ResourceModel\Country\Collection $countryCollection
     */
    public function __construct(
        \Magento\Directory\Model\ResourceModel\Country\Collection $countryCollection
    ) {
        parent::__construct($countryCollection);

        $this->_countryCollection = $countryCollection;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray($isMultiselect = false, $foregroundCountries = '')
    {
        if (!$this->_options) {
            $this->_options = $this->_countryCollection
                ->addCountryIdFilter($this->_specificCountries)
                ->toOptionArray(false);
        }

        $options = $this->_options;
        if (!$isMultiselect) {
            array_unshift($options, ['value' => '', 'label' => __('--Please Select--')]);
        }

        return $options;
    }
}