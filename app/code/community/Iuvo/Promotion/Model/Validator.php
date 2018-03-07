<?php
class Iuvo_Promotion_Model_Validator extends Mage_SalesRule_Model_Validator
{
    public function process(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $item->setDiscountAmount(0);
        $item->setBaseDiscountAmount(0);
        $item->setDiscountPercent(0);

        $quote = $item->getQuote();
        if(!$quote->getId()) {
    		$quote = Mage::getModel('sales/quote')
    			->setId(null)
    			->setStoreId(1)
    			->setCustomerId('NULL')
    			->setCustomerTaxClassId(1);
    		$quote->save();
			Mage::getSingleton('checkout/session')->setQuoteId($quote->getId());
		}
		
        $address    = $this->_getAddress($item);

		//set all custom prices to null if custom prices are used in the config
		if(!Mage::getStoreConfig('sales/promotion/use_price')) {
			$quoteItems = $quote->getAllItems();
			foreach($quoteItems as $quoteItem) {
				if(Mage::getStoreConfig('sales/promotion/remove_item') && $quoteItem->getIuvoPromotionItem()) {
					$quoteItem->delete();
				} else {
					if($quoteItem->getQuoteId()) {
						$quoteItem->setCustomPrice(NULL);
						$quoteItem->setOriginalCustomPrice(NULL);
						$quoteItem->save();
					}
				}
			}
		}
		$quoteItems = $quote->getAllItems();
		foreach($quoteItems as $quoteItem) {
			if(Mage::getStoreConfig('sales/promotion/remove_item') && $quoteItem->getIuvoPromotionItem()) {
				$quoteItem->delete();
			}
		}
	

		
        //Clearing applied rule ids for quote and address
        if ($this->_isFirstTimeProcessRun !== true){
            $this->_isFirstTimeProcessRun = true;
            $quote->setAppliedRuleIds('');
            $address->setAppliedRuleIds('');
        }

        $itemPrice  = $item->getDiscountCalculationPrice();
        if ($itemPrice !== null) {
            $baseItemPrice = $item->getBaseDiscountCalculationPrice();
        } else {
            $itemPrice = $item->getCalculationPrice();
            $baseItemPrice = $item->getBaseCalculationPrice();
        }

        $appliedRuleIds = array();
        foreach ($this->_getRules() as $rule) {
            /* @var $rule Mage_SalesRule_Model_Rule */
            if (!$this->_canProcessRule($rule, $address)) {
                continue;
            }

			if($rule->getSimpleAction() != 'buy_itemx_get_itemy') {
            	if (!$rule->getActions()->validate($item)) {
                	continue;
            	}
            }

            $qty = $this->_getItemQty($item, $rule);
            $rulePercent = min(100, $rule->getDiscountAmount());

            $discountAmount = 0;
            $baseDiscountAmount = 0;

            switch ($rule->getSimpleAction()) {
                case Mage_SalesRule_Model_Rule::TO_PERCENT_ACTION:
                    $rulePercent = max(0, 100-$rule->getDiscountAmount());
                    //no break;
                case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
                    $step = $rule->getDiscountStep();
                    if ($step) {
                        $qty = floor($qty/$step)*$step;
                    }
                    $discountAmount    = ($qty*$itemPrice - $item->getDiscountAmount()) * $rulePercent/100;
                    $baseDiscountAmount= ($qty*$baseItemPrice - $item->getBaseDiscountAmount()) * $rulePercent/100;

                    if (!$rule->getDiscountQty() || $rule->getDiscountQty()>$qty) {
                        $discountPercent = min(100, $item->getDiscountPercent()+$rulePercent);
                        $item->setDiscountPercent($discountPercent);
                    }
                    break;
                case Mage_SalesRule_Model_Rule::TO_FIXED_ACTION:
                    $quoteAmount = $quote->getStore()->convertPrice($rule->getDiscountAmount());
                    $discountAmount    = $qty*($itemPrice-$quoteAmount);
                    $baseDiscountAmount= $qty*($baseItemPrice-$rule->getDiscountAmount());
                    break;

                case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
                    $step = $rule->getDiscountStep();
                    if ($step) {
                        $qty = floor($qty/$step)*$step;
                    }
                    $quoteAmount        = $quote->getStore()->convertPrice($rule->getDiscountAmount());
                    $discountAmount     = $qty*$quoteAmount;
                    $baseDiscountAmount = $qty*$rule->getDiscountAmount();
                    break;

                case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
                    if (empty($this->_rulesItemTotals[$rule->getId()])) {
                        Mage::throwException(Mage::helper('salesrule')->__('Item totals are not set for rule.'));
                    }

                    /**
                     * prevent applying whole cart discount for every shipping order, but only for first order
                     */
                    if ($quote->getIsMultiShipping()) {
                        $usedForAddressId = $this->getCartFixedRuleUsedForAddress($rule->getId());
                        if ($usedForAddressId && $usedForAddressId != $address->getId()) {
                            break;
                        } else {
                            $this->setCartFixedRuleUsedForAddress($rule->getId(), $address->getId());
                        }
                    }
                    $cartRules = $address->getCartFixedRules();
                    if (!isset($cartRules[$rule->getId()])) {
                        $cartRules[$rule->getId()] = $rule->getDiscountAmount();
                    }

                    if ($cartRules[$rule->getId()] > 0) {
                        if ($this->_rulesItemTotals[$rule->getId()]['items_count'] <= 1) {
                            $quoteAmount = $quote->getStore()->convertPrice($cartRules[$rule->getId()]);
                            $baseDiscountAmount = min($baseItemPrice * $qty, $cartRules[$rule->getId()]);
                        } else {
                            $discountRate = $baseItemPrice * $qty / $this->_rulesItemTotals[$rule->getId()]['base_items_price'];
                            $maximumItemDiscount = $rule->getDiscountAmount() * $discountRate;
                            $quoteAmount = $quote->getStore()->convertPrice($maximumItemDiscount);

                            $baseDiscountAmount = min($baseItemPrice * $qty, $maximumItemDiscount);
                            $this->_rulesItemTotals[$rule->getId()]['items_count']--;
                        }

                        $discountAmount = min($itemPrice * $qty, $quoteAmount);
                        $discountAmount = $quote->getStore()->roundPrice($discountAmount);
                        $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);
                        $cartRules[$rule->getId()] -= $baseDiscountAmount;
                    }
                    $address->setCartFixedRules($cartRules);

                    break;

                case Mage_SalesRule_Model_Rule::BUY_X_GET_Y_ACTION:
                    $x = $rule->getDiscountStep();
                    $y = $rule->getDiscountAmount();
                    if (!$x || $y>=$x) {
                        break;
                    }
                    $buyAndDiscountQty = $x + $y;

                    $fullRuleQtyPeriod = floor($qty / $buyAndDiscountQty);
                    $freeQty  = $qty - $fullRuleQtyPeriod * $buyAndDiscountQty;

                    $discountQty = $fullRuleQtyPeriod * $y;
                    if ($freeQty > $x) {
                         $discountQty += $freeQty - $x;
                    }

                    $discountAmount    = $discountQty * $itemPrice;
                    $baseDiscountAmount= $discountQty * $baseItemPrice;
                    break;

                case Iuvo_Promotion_Model_Rule::BUY_ITEMX_GET_ITEMY_ACTION:
                	$conditionsArr = unserialize($rule->getActionsSerialized());
                	foreach($conditionsArr['conditions'] as $condition) {
                		$product = Mage::getModel('catalog/product')->getCollection()
							->addAttributeToFilter('sku', $condition['value'])
							->addAttributeToSelect('*')
							->getFirstItem();

						$stockItem = Mage::getModel('cataloginventory/stock_item');
						$stockItem->assignProduct($product);
						if(!$stockItem->getUseConfigManageStock()) {
							$stockItem->setData('is_in_stock', 1);
							$stockItem->setData('stock_id', 1);
							$stockItem->setData('store_id', 1);
							$stockItem->setData('manage_stock', 0);
							$stockItem->setData('use_config_manage_stock', 0);
							$stockItem->setData('min_sale_qty', 0);
							$stockItem->setData('use_config_min_sale_qty', 0);
							$stockItem->setData('max_sale_qty', 1000);
							$stockItem->setData('use_config_max_sale_qty', 0);
							$stockItem->save();
						}
						

			            $newitem = $quote->addProduct($product);
			            
			            //set discount amount if discount is used
						if(Mage::getStoreConfig('sales/promotion/use_price')) {
							$discountPercent = min(100, $rulePercent);
							$discountPercent = min(100, $rulePercent);
							$discountItemAmount = ($discountPercent/100) * $product->getPrice();
							$newitem->setDiscountAmount($discountItemAmount * $qty);
						} else {
							//set custom price if used
				            if($rulePercent == 100) {
				            	$newPrice = 0;
				            } else {
				            	$discountPercent = min(100, $rulePercent);
								$discountItemAmount = ($discountPercent/100) * $product->getPrice();
								$newPrice = round($product->getPrice() - $discountItemAmount, 2);
								$newitem->setBaseRowTotal($qty * $newPrice);
								$newitem->setRowTotal($qty * $newPrice);
				            }
				            $newitem->setCustomPrice($newPrice);
			            	$newitem->setOriginalCustomPrice($newPrice);
				        }
						$newitem->setIuvoPromotionItem(1);
						$newitem->setQty($qty);
						$newitem->save();
                	}
                	break;
            }

            $result = new Varien_Object(array(
                'discount_amount'      => $discountAmount,
                'base_discount_amount' => $baseDiscountAmount,
            ));
            Mage::dispatchEvent('salesrule_validator_process', array(
                'rule'    => $rule,
                'item'    => $item,
                'address' => $address,
                'quote'   => $quote,
                'qty'     => $qty,
                'result'  => $result,
            ));

            $discountAmount = $result->getDiscountAmount();
            $baseDiscountAmount = $result->getBaseDiscountAmount();

            $percentKey = $item->getDiscountPercent();
            /**
             * Process "delta" rounding
             */
            if ($percentKey) {
                $delta      = isset($this->_roundingDeltas[$percentKey]) ? $this->_roundingDeltas[$percentKey] : 0;
                $baseDelta  = isset($this->_baseRoundingDeltas[$percentKey]) ? $this->_baseRoundingDeltas[$percentKey] : 0;
                $discountAmount+= $delta;
                $baseDiscountAmount+=$baseDelta;

                $this->_roundingDeltas[$percentKey]     = $discountAmount - $quote->getStore()->roundPrice($discountAmount);
                $this->_baseRoundingDeltas[$percentKey] = $baseDiscountAmount - $quote->getStore()->roundPrice($baseDiscountAmount);
                $discountAmount = $quote->getStore()->roundPrice($discountAmount);
                $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);
            } else {
                $discountAmount     = $quote->getStore()->roundPrice($discountAmount);
                $baseDiscountAmount = $quote->getStore()->roundPrice($baseDiscountAmount);
            }

            /**
             * We can't use row total here because row total not include tax
             * Discount can be applied on price included tax
             */
            $discountAmount     = min($item->getDiscountAmount()+$discountAmount, $itemPrice*$qty);
            $baseDiscountAmount = min($item->getBaseDiscountAmount()+$baseDiscountAmount, $baseItemPrice*$qty);

            $item->setDiscountAmount($discountAmount);
            $item->setBaseDiscountAmount($baseDiscountAmount);

            $appliedRuleIds[$rule->getRuleId()] = $rule->getRuleId();

            $this->_maintainAddressCouponCode($address, $rule);
            $this->_addDiscountDescription($address, $rule);

            if ($rule->getStopRulesProcessing()) {
                break;
            }
        }

        $item->setAppliedRuleIds(join(',',$appliedRuleIds));
        $address->setAppliedRuleIds($this->mergeIds($address->getAppliedRuleIds(), $appliedRuleIds));
        $quote->setAppliedRuleIds($this->mergeIds($quote->getAppliedRuleIds(), $appliedRuleIds));

        return $this;
    }

}