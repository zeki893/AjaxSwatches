<?php
/**
 * Class implementing the media fallback layer for swatches
 */
class Wigman_AjaxSwatches_Helper_Mediafallback extends Mage_ConfigurableSwatches_Helper_Mediafallback
{
    
       public function getConfigurableImagesFallbackArray(Mage_Catalog_Model_Product $product, array $imageTypes,
        $keepFrame = false
    ) {
        if (!$product->hasConfigurableImagesFallbackArray()) {
            $mapping = $product->getChildAttributeLabelMapping();

            $mediaGallery = $product->getMediaGallery();

            if (!isset($mediaGallery['images'])) {
                return array(); //nothing to do here
            }

            // ensure we only attempt to process valid image types we know about
            $imageTypes = array_intersect(array('image', 'small_image'), $imageTypes);

            $imagesByLabel = array();
            $imageHaystack = array_map(function ($value) {
                return Mage_ConfigurableSwatches_Helper_Data::normalizeKey($value['label']);
            }, $mediaGallery['images']);

            // load images from the configurable product for swapping
            foreach ($mapping as $map) {
                $imagePath = null;

                //search by store-specific label and then default label if nothing is found
                $imageKey = array_search($map['label'], $imageHaystack);
                if ($imageKey === false) {
                    $imageKey = array_search($map['default_label'], $imageHaystack);
                }

                //assign proper image file if found
                if ($imageKey !== false) {
                    $imagePath = $mediaGallery['images'][$imageKey]['file'];
                }

                $imagesByLabel[$map['label']] = array(
                    'configurable_product' => array(
                        Mage_ConfigurableSwatches_Helper_Productimg::MEDIA_IMAGE_TYPE_SMALL => null,
                        Mage_ConfigurableSwatches_Helper_Productimg::MEDIA_IMAGE_TYPE_BASE => null,
                    ),
                    'products' => $map['product_ids'],
                );

                if ($imagePath) {
                    $imagesByLabel[$map['label']]['configurable_product']
                        [Mage_ConfigurableSwatches_Helper_Productimg::MEDIA_IMAGE_TYPE_SMALL] =
                            $this->_resizeProductImage($product, 'small_image', $keepFrame, $imagePath);

                    $imagesByLabel[$map['label']]['configurable_product']
                        [Mage_ConfigurableSwatches_Helper_Productimg::MEDIA_IMAGE_TYPE_BASE] =
                            $this->_resizeProductImage($product, 'image', $keepFrame, $imagePath);
                }
            }

            $imagesByType = array(
                'image' => array(),
                'small_image' => array(),
            );

            // iterate image types to build image array, normally one type is passed in at a time, but could be two
            foreach ($imageTypes as $imageType) {
                // load image from the configurable product's children for swapping
                /* @var $childProduct Mage_Catalog_Model_Product */
                if ($product->hasChildrenProducts()) {
                    foreach ($product->getChildrenProducts() as $childProduct) {
                        // if ($image = $this->_resizeProductImage($childProduct, $imageType, $keepFrame)) {
						$imgsize = 0;
						if(@$_POST['pids']) $imgsize = 350;
                        if ($image = $this->_resizeProductImage($childProduct, $imageType, $keepFrame, null, false, $imgsize)) {
                            $imagesByType[$imageType][$childProduct->getId()] = $image;
                        }
                    }
                }

                // load image from configurable product for swapping fallback
                if ($image = $this->_resizeProductImage($product, $imageType, $keepFrame, null, true)) {
                    $imagesByType[$imageType][$product->getId()] = $image;
                }
            }

            $array = array(
                'option_labels' => $imagesByLabel,
                Mage_ConfigurableSwatches_Helper_Productimg::MEDIA_IMAGE_TYPE_SMALL => $imagesByType['small_image'],
                Mage_ConfigurableSwatches_Helper_Productimg::MEDIA_IMAGE_TYPE_BASE => $imagesByType['image'],
            );

            $product->setConfigurableImagesFallbackArray($array);
        }

        return $product->getConfigurableImagesFallbackArray();
    }	 

	 protected function _resizeProductImage($product, $type, $keepFrame, $image = null, $placeholder = false, $size2 = null)
		{
			
			$hasTypeData = $product->hasData($type) && $product->getData($type) != 'no_selection';
			if ($image == 'no_selection') {
				$image = null;
			}
			
			if ($hasTypeData || $placeholder || $image) {
				$helper = Mage::helper('catalog/image')
					->init($product, $type, $image)
					->keepFrame(($hasTypeData || $image) ? $keepFrame : false)  // don't keep frame if placeholder
				;

				$size = Mage::getStoreConfig(Mage_Catalog_Helper_Image::XML_NODE_PRODUCT_BASE_IMAGE_WIDTH);
				// print_r($size );
				if ($type == 'small_image') {
					$size = Mage::getStoreConfig(Mage_Catalog_Helper_Image::XML_NODE_PRODUCT_SMALL_IMAGE_WIDTH);
				}
				
				if (is_numeric($size) || $size2) {
					if($size2) {
						// $helper->constrainOnly(true)->resize($size2);
						$helper->keepFrame(true)->constrainOnly(true)->resize(350, 525);
					}
					if(!$size2 && is_numeric($size)){
						$helper->constrainOnly(true)->resize($size);
					}
					// $helper->constrainOnly(true)->resize($size);
				}
				return (string)$helper;
			}
			return false;
		}
	
    
    
    /**
     * Set child_attribute_label_mapping on products with attribute label -> product mapping
     * Depends on following product data:
     * - product must have children products attached
     *
     * @param array $parentProducts
     * @param $storeId
     * @return void
     *
     * Wigman added: hide colors when 'show_out_of_stock' is set to false in admin (cataloginventory/options/show_out_of_stock)
     *
     */
    public function attachConfigurableProductChildrenAttributeMapping(array $parentProducts, $storeId = 0)
    {
        $listSwatchAttr = Mage::helper('configurableswatches/productlist')->getSwatchAttribute();

        $parentProductIds = array();
        /* @var $parentProduct Mage_Catalog_Model_Product */
        foreach ($parentProducts as $parentProduct) {
            $parentProductIds[] = $parentProduct->getId();
        }

        $configAttributes = Mage::getResourceModel('configurableswatches/catalog_product_attribute_super_collection')
            ->addParentProductsFilter($parentProductIds)
            ->attachEavAttributes()
            ->setStoreId($storeId)
        ;

        $optionLabels = array();
        foreach ($configAttributes as $attribute) {
            $optionLabels += $attribute->getOptionLabels();
        }
        foreach ($parentProducts as $parentProduct) {
            $mapping = array();
            $listSwatchValues = array();

            /* @var $attribute Mage_Catalog_Model_Product_Type_Configurable_Attribute */
            foreach ($configAttributes as $attribute) {
                /* @var $childProduct Mage_Catalog_Model_Product */
                if (!is_array($parentProduct->getChildrenProducts())) {
                    continue;
                }
                foreach ($parentProduct->getChildrenProducts() as $childProduct) {

                    // product has no value for attribute, we can't process it
                    if (!$childProduct->hasData($attribute->getAttributeCode())) {
                        continue;
                    }

                    if (!Mage::getStoreConfig('cataloginventory/options/show_out_of_stock') && !$childProduct->isSalable()) {
                        continue;
                    }

                    $optionId = $childProduct->getData($attribute->getAttributeCode());

                    // if we don't have a default label, skip it
                    if (!isset($optionLabels[$optionId][0]['label'])) {
                        continue;
                    }

                    // normalize to all lower case before we start using them
                    $optionLabels = array_map(function ($value) {
                        return array_map(function($key){
                            $key['label'] = trim(strtolower($key['label']));
                            return $key;
                        }, $value);
                    }, $optionLabels);

                    // using default value as key unless store-specific label is present
                    $optionLabel = $optionLabels[$optionId][0]['label'];
                    $sortId = $optionLabels[$optionId][0]['sort_id'];
                    if (isset($optionLabels[$optionId][$storeId])) {
                        $optionLabel = $optionLabels[$optionId][$storeId]['label'];
                        $sortId = $optionLabels[$optionId][$storeId]['sort_id'];
                    }

                    // initialize arrays if not present
                    if (!isset($optionLabel) || !isset($mapping[$optionLabel])) {
                        $mapping[$optionLabel] = array(
                            'product_ids' => array(),
                        );
                    }

                    $mapping[$optionLabel]['product_ids'][] = $childProduct->getId();
                    $mapping[$optionLabel]['label'] = $optionLabel;
                    $mapping[$optionLabel]['default_label'] = $optionLabels[$optionId][0]['label'];
                    $mapping[$optionLabel]['labels'] = $optionLabels[$optionId];
                    $mapping[$optionLabel]['sort_id'] = $sortId;

                    if ($attribute->getAttributeId() == $listSwatchAttr->getAttributeId()
                        && !in_array($mapping[$optionLabel]['label'], $listSwatchValues)
                    ) {
                        $listSwatchValues[$optionId] = $mapping[$optionLabel]['label'];
                    }
                } // end looping child products
            } // end looping attributes

            foreach ($mapping as $key => $value) {
                $mapping[$key]['product_ids'] = array_unique($mapping[$key]['product_ids']);
            }

            uasort($listSwatchValues, function($a, $b) use ($mapping) {
                return $mapping[$a]['sort_id'] - $mapping[$b]['sort_id'];
            });

            $parentProduct->setChildAttributeLabelMapping($mapping)
                ->setListSwatchAttrValues($listSwatchValues);
        } // end looping parent products
    }
}
