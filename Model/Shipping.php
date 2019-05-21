<?php
/**
 * @author Zenda
 * @copyright Copyright (c) 2019 Zenda (https://www.zenda.global/)
 * @package Zenda_Shipping
 */

namespace Zenda\Shipping\Model;

class Shipping
{
    /**
     * Default item dimension to use
     */
    const DEFAULT_ITEM_MIN_VOLUME = 1;

    /**
     * Default item max weight in pounds (28 kg)
     */
    const DEFAULT_ITEM_MAX_WEIGHT = 61.7294;

    /**
     * Default item max volume in cubic inch (1 M3)
     */
    const DEFAULT_ITEM_MAX_VOLUME = 61023.7;

    /**
     * Pound to kilogram conversion value
     */
    const POUNT_TO_KG = 0.4536;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $_productRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Zenda\Shipping\Model\Service
     */
    protected $_service;

    /**
     * Errors placeholder
     *
     * @var string[]
     */
    protected $_errors = [];

    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Service $service
    )
    {
        $this->_productRepository = $productRepository;
        $this->_storeManager = $storeManager;
        $this->_service = $service;
    }

    /**
     * Compose Packages
     *
     * @param \Magento\Shipping\Model\Carrier\AbstractCarrier $carrier
     * @param \Magento\Quote\Model\Quote\Address\RateRequest  $request
     * @return array
     */
    public function composePackages($carrier, $request)
    {
        $allItems = $request->getAllItems();
        $maxWeight = $this->_getMaxWeight($carrier);
        $maxVolume = $this->_getMaxVolume($carrier);

        $items = [];
        foreach ($allItems as $item) {
            /** @var $item \Magento\Quote\Model\Quote\Item */
            if ($item->getProductType() != \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE) {
                continue;
            }
            $itemWeight = $item->getWeight();
            $itemVolume = $this->_getItemVolume($item->getProduct());

            if (
                $itemWeight >= $maxWeight ||
                $itemVolume >= $maxVolume
            ) {
                $this->_errors[] = "Sorry, one of the items is overweight or oversized. Maximum package weight allowed is $maxWeight and volume is $maxVolume";
                return [];
            }

            for ($i = 0; $i < $item->getQty(); $i++) {
                $items[] = [
                    'weight' => $itemWeight,
                    'volume' => $itemVolume
                ];
            }
        }

        $parcels = [];
        $numberOfItems = count($items);
        for ($i = 0; $i < $numberOfItems; $i++) {
            $parcelWeight = $items[$i]['weight'];
            $parcelVolume = $items[$i]['volume'];
            for ($j = $i + 1; $j < $numberOfItems; $j++) {
                if (
                    ($parcelWeight + $items[$j]['weight'] > $maxWeight) ||
                    ($parcelVolume + $items[$j]['volume'] > $maxVolume)
                ) {
                    break;
                }
                $parcelWeight += $items[$j]['weight'];
                $parcelVolume += $items[$j]['volume'];
            }
            $i = $j - 1;
            $parcels[] = [
                'weight' => $parcelWeight,
                'volume' => $parcelVolume
            ];
        }

        return $parcels;
    }

    /**
     * Get the item dimension
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return float|int
     */
    protected function _getItemVolume(\Magento\Catalog\Model\Product $product)
    {
        // Default item dimension
        $itemVolume = self::DEFAULT_ITEM_MIN_VOLUME;

        // Item dimensions
        $itemLength = $product->getTsDimensionsLength();
        $itemWidth = $product->getTsDimensionsWidth();
        $itemHeight = $product->getTsDimensionsHeight();
        if ($itemLength && $itemWidth && $itemHeight) {
            $itemVolume = $itemLength * $itemWidth * $itemHeight;
        }

        return $itemVolume;
    }

    /**
     * Get carrier's max weight allowed
     *
     * @param \Magento\Shipping\Model\Carrier\AbstractCarrier $carrier
     * @return float|int
     */
    protected function _getMaxWeight($carrier)
    {
        $maxWeight = (double)$carrier->getConfigData('max_package_weight') ?: (double)self::DEFAULT_ITEM_MAX_WEIGHT;

        if ($this->_service->getWeightUnit() == 'KG') {
            $maxWeight = $maxWeight * self::POUNT_TO_KG;
        }

        return $maxWeight;
    }

    /**
     * Get carrier's max volume allowed
     *
     * @param \Magento\Shipping\Model\Carrier\AbstractCarrier $carrier
     * @return float
     */
    protected function _getMaxVolume($carrier)
    {
        return (double)$carrier->getConfigData('max_package_volume') ?: (double)self::DEFAULT_ITEM_MAX_VOLUME;
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * @param $id
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _getProduct($id)
    {
        return $this->_productRepository->getById($id, false, $this->_storeManager->getStore()->getId());
    }
}