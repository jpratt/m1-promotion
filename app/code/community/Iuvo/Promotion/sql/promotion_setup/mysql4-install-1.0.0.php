<?php

$installer = $this;

$installer->startSetup();

$installer->run("

ALTER TABLE {$this->getTable('sales_flat_quote_item')} ADD `iuvo_promotion_item` int(1) NULL DEFAULT NULL;

")->endSetup();