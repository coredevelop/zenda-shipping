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
     * @param \Magento\Quote\Model\Quote\Address\RateRequest  $request
     * @param string $maxWeight
     * @param string $maxVolume
     * @return array
     */
    public function composePackages($request, $maxWeight, $maxVolume)
    {
        $allItems = $request->getAllItems();

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