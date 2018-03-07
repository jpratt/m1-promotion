<?php
class Iuvo_Promotion_Block_Promo_Quote_Edit extends Mage_Adminhtml_Block_Promo_Quote_Edit
{
    public function __construct()
    {
		
        $this->_objectId = 'id';
        $this->_controller = 'promo_quote';

        parent::__construct();

        $this->_updateButton('save', 'label', Mage::helper('salesrule')->__('Save Rule'));
        $this->_updateButton('delete', 'label', Mage::helper('salesrule')->__('Delete Rule'));

        $rule = Mage::registry('current_promo_quote_rule');

        if (!$rule->isDeleteable()) {
            $this->_removeButton('delete');
        }

        if ($rule->isReadonly()) {
            $this->_removeButton('save');
            $this->_removeButton('reset');
        } else {
            $this->_addButton('save_and_continue', array(
                'label'     => Mage::helper('salesrule')->__('Save And Continue Edit'),
                'onclick'   => 'saveAndContinueEdit()',
                'class' => 'save'
            ), 10);
            $this->_formScripts[] = " function saveAndContinueEdit(){ editForm.submit($('edit_form').action + 'back/edit/') } ";
        }
        $this->_formScripts[] = "
        	Event.observe('rule_simple_action', 'change', function(event) {
				changeRules();
			});
			Event.observe(window, 'load', function(event) {
				changeRules();
			});
			
			function changeRules() {
				if($('rule_simple_action').value == 'buy_itemx_get_itemy') {
					$('rule_discount_step').value = 1;
					$('rule_discount_step').disable();
				} else {
					$('rule_discount_amount').enable();
					$('rule_discount_step').enable();
				}
			}
        ";

        #$this->setTemplate('promo/quote/edit.phtml');
    }
}
