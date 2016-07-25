<?php

if (!defined('_PS_VERSION_'))
	exit;

class Yooshop extends Module
{
	public function __construct()
    {
        $this->name = 'yooshop';
        $this->tab =  'smart_shopping';
        $this->author = 'Solofo Andriamampihantona';
        $this->version = '1.0.1'; 
        $this->limited_countries = array('fr', 'us');

        parent::__construct();
    
        $this->displayName = $this->l('Yooshop export');
        $this->description = $this->l('Ce module permet de synchroniser les produits dans votre boutique prestashop vers fux market puis vers ebay ou vice versa');

        $id_default_country = Configuration::get('PS_COUNTRY_DEFAULT');
		$this->default_country = new Country($id_default_country);
    }

    //Install
    public function install()
	{
		return (parent::install() && $this->_initHooks() && $this->_initConfig());
	}

	//Hook
	private function _initHooks()
	{
		if (!$this->registerHook('newOrder') ||
				!$this->registerHook('footer') ||
				!$this->registerHook('postUpdateOrderStatus') ||
				!$this->registerHook('updateProduct') ||
				!$this->registerHook('backOfficeTop') ||
				!$this->registerHook('updateProductAttribute') ||
				!$this->registerHook('top'))
			return false;

		return true;
	}

	//initConfiguration
	private function _initConfig()
	{
		Db::getInstance()->Execute('
			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'custumer_yooshop_ip` (
			`id_custumer_yooshop_ip` int(10) unsigned NOT null AUTO_INCREMENT,
			`id_customer` int(10) unsigned NOT null,
			`ip` varchar(32) DEFAULT null,
			PRIMARY KEY (`id_custumer_yooshop_ip`),
			KEY `idx_id_customer` (`id_customer`)
			) ENGINE='._MYSQL_ENGINE_.'  DEFAULT CHARSET=utf8;');

		Db::getInstance()->Execute('
			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'last_id_prod` (
			`id` int(10) unsigned NOT null AUTO_INCREMENT,
			`id_product` int(10) unsigned NOT null,
			PRIMARY KEY (`id`)
			) ENGINE='._MYSQL_ENGINE_.'  DEFAULT CHARSET=utf8;');

		Db::getInstance()->Execute('INSERT INTO `'._DB_PREFIX_.'last_id_prod` VALUES (0,0))');

		$token = md5(rand());
		if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive())
		{
			foreach (Shop::getShops() as $shop)
			{
				if (!Configuration::updateValue('YOOSHOP_TOKEN', $token, false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_CANCELED', Configuration::get('PS_OS_CANCELED'), false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_SHIPPED', Configuration::get('PS_OS_SHIPPING'), false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_IMAGE', ImageType::getFormatedName('large'), false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_CARRIER', Configuration::get('PS_CARRIER_DEFAULT'), false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_TRACKING', 'checked', false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_BUYLINE', 'checked', false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_ORDERS', 'checked', false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_STATUS_SHIPPED', 'checked', false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_STATUS_CANCELED', '', false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_INDEX', 'http://'.$shop['domain'].$shop['uri'], false, null, $shop['id_shop']) ||
						!Configuration::updateValue('YOOSHOP_STOCKS', '', false, null, $shop['id_shop']))
				
						return false;
			}
		}
		else
		{
			if (!Configuration::updateValue('YOOSHOP_TOKEN', $token) ||
					!Configuration::updateValue('YOOSHOP_CANCELED', Configuration::get('PS_OS_CANCELED')) ||
					!Configuration::updateValue('YOOSHOP_SHIPPED', Configuration::get('PS_OS_SHIPPING')) ||
					!Configuration::updateValue('YOOSHOP_IMAGE', ImageType::getFormatedName('large')) ||
					!Configuration::updateValue('YOOSHOP_CARRIER', Configuration::get('PS_CARRIER_DEFAULT')) ||
					!Configuration::updateValue('YOOSHOP_TRACKING', 'checked') ||
					!Configuration::updateValue('YOOSHOP_BUYLINE', 'checked') ||
					!Configuration::updateValue('YOOSHOP_ORDERS', 'checked') ||
					!Configuration::updateValue('YOOSHOP_STATUS_SHIPPED', 'checked') ||
					!Configuration::updateValue('YOOSHOP_STATUS_CANCELED', '') ||
					!Configuration::updateValue('YOOSHOP_INDEX', 'http://'.$shop['domain'].$shop['uri']) ||
					!Configuration::updateValue('YOOSHOP_STOCKS'))

					return false;	
		}

		
		$this->_callWebService('addToken','');

		return true;
	}

	//Uninstall
	public function uninstall()
	{
		if (!Configuration::deleteByName('YOOSHOP_TOKEN') ||
				!Configuration::deleteByName('YOOSHOP_CANCELED') ||
				!Configuration::deleteByName('YOOSHOP_SHIPPED') ||
				!Configuration::deleteByName('YOOSHOP_IMAGE') ||
				!Configuration::deleteByName('YOOSHOP_TRACKING') ||
				!Configuration::deleteByName('YOOSHOP_BUYLINE') ||
				!Configuration::deleteByName('YOOSHOP_ORDERS') ||
				!Configuration::deleteByName('YOOSHOP_STATUS_SHIPPED') ||
				!Configuration::deleteByName('YOOSHOP_STATUS_CANCELED') ||
				!Configuration::deleteByName('YOOSHOP_INDEX') ||
				!Configuration::deleteByName('YOOSHOP_STOCKS') ||
				!Configuration::deleteByName('YOOSHOP_SHIPPING_MATCHING') ||
				!parent::uninstall())
		
			
			return false;
		
		$this->_callWebService('deleteToken','');

		return true;
	}

	public function getContent()
	{
		$status_JSON = $this->_checkToken();
		$status = is_object($status_JSON) ? $status_JSON->Response->Status : '';
		$price = is_object($status_JSON) ? (float)$status_JSON->Response->Price : 0;

		switch ($status)
		{
			case 'New':
			default:
				$this->_html .= $this->_clientView();
				break;
		}

		if (!in_array('curl', get_loaded_extensions()))
			$this->_html .= '<br/><strong>'.$this->l('You have to install Curl extension to use this plugin. Please contact your IT team.').'</strong>';
		else
			Configuration::updateValue('YOOSHOPEXPORT_CONFIGURED', true); // YOOSHOPEXPORT_CONFIGURATION_OK

		return $this->_html;
	}



	/* Check wether the Token is known by Yooshop */
	private function _checkToken()
	{
		$this->_callWebService('token','');

		$request = $curl_response;
		$token = $request[0];

		return $token;
	}


	private function _clientView()
	{
		$this->_treatForm();

		$configuration = Configuration::getMultiple(array('YOOSHOP_TOKEN','YOOSHOP_TRACKING','YOOSHOP_BUYLINE',
					'YOOSHOP_ORDERS', 'YOOSHOP_STATUS_SHIPPED', 'YOOSHOP_STATUS_CANCELED',
					'YOOSHOP_STOCKS', 'YOOSHOP_INDEX','PS_LANG_DEFAULT', 'YOOSHOP_CARRIER', 'YOOSHOP_IMAGE',
					'YOOSHOP_SHIPPED', 'YOOSHOP_CANCELED', 'YOOSHOP_SHIPPING_MATCHING'));

		$html = $this->_getFeedContent();
		$html .= $this->_getParametersContent($configuration);
		//$html .= $this->_getAdvancedParametersContent($configuration);

		return $html;
	}

	private function _getParametersContent($configuration)
	{
		return '<form method="post" action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'">
					<fieldset>
						<legend>'.$this->l('Parameters').'</legend>
						<p><label>Token '.$this->l('Yooshop').' : </label><input type="text" name="YOOSHOP_TOKEN" value="'.Tools::safeOutput($configuration['YOOSHOP_TOKEN']).'" style="width:auto"/></p>
						<p><label>Buyline : </label><input type="checkbox" name="YOOSHOP_BUYLINE" '.Tools::safeOutput($configuration['YOOSHOP_BUYLINE']).'/> '.$this->l('sources of your orders will be tracked').'.</p>
						<p><label>'.$this->l('Order tracking').' : </label><input type="checkbox" name="YOOSHOP_TRACKING" '.Tools::safeOutput($configuration['YOOSHOP_TRACKING']).'/> '.$this->l('orders coming from shopbots will be tracked').'.</p>
						<p><label>'.$this->l('Order importation').' : </label><input type="checkbox" name="YOOSHOP_ORDERS" '.Tools::safeOutput($configuration['YOOSHOP_ORDERS']).'/> '.$this->l('orders coming from marketplaces will be imported').'.</p>
						<p><label>'.$this->l('Order shipment').' : </label><input type="checkbox" name="YOOSHOP_STATUS_SHIPPED" '.Tools::safeOutput($configuration['YOOSHOP_STATUS_SHIPPED']).'/> '.$this->l('orders shipped on your Prestashop will be shipped on marketplaces').'.</p>
						<p><label>'.$this->l('Order cancellation').' : </label><input type="checkbox" name="YOOSHOP_STATUS_CANCELED" '.Tools::safeOutput($configuration['YOOSHOP_STATUS_CANCELED']).'/> '.$this->l('orders shipped on your Prestashop will be canceled on marketplaces').'.</p>
						<p><label>'.$this->l('Sync stock and orders').' : </label><input type="checkbox" name="YOOSHOP_STOCKS" '.Tools::safeOutput($configuration['YOOSHOP_STOCKS']).'/> '.$this->l('every stock and price movement will be transfered to marketplaces').'.</p>
						<p><label>'.$this->l('Default carrier').' : </label>'.$this->_getCarriersSelect($configuration, $configuration['YOOSHOP_CARRIER']).'</p>
						<p><label>'.$this->l('Default image type').' : </label>'.$this->_getImageTypeSelect($configuration).'</p>
						<p><label>'.$this->l('Call marketplace for shipping when order state become').' : </label>'.$this->_getOrderStateShippedSelect($configuration).'</p>
						<p><label>'.$this->l('Call marketplace for cancellation when order state become').' : </label>'.$this->_getOrderStateCanceledSelect($configuration).'</p>
						<p style="margin-tops:20px"><input type="submit" value="'.$this->l('Update').'" name="rec_config" class="button"/></p>
					</fieldset>
				</form>';
	}

	private function _getAdvancedParametersContent($configuration)
	{
		if (!in_array('curl', get_loaded_extensions()))
			return;

		$sf_carriers_JSON = json_decode($this->_callWebService('transport',''), true);

		if (!isset($sf_carriers_JSON->Response->Carriers->Carrier[0]))
			return;

		$sf_carriers = array();

		foreach ($sf_carriers_JSON as $carrier)
			$sf_carriers[] = (string)$carrier['transport'];

		$html = '<h3>'.$this->l('Advanced Parameters').'</h3>
			<form method="post" action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'">
				<fieldset>
					<legend>'.$this->l('Carriers Matching').'</legend>
					<p>'.$this->l('Please see below carriers coming from your markeplaces managed on Yooshop. You can match them to your Prestashop carriers').'</p>';

		$actual_configuration = unserialize($configuration['YOOSHOP_SHIPPING_MATCHING']);

		foreach ($sf_carriers as $sf_carrier)
		{
			$actual_value = isset($actual_configuration[base64_encode(Tools::safeOutput($sf_carrier))]) ? $actual_configuration[base64_encode(Tools::safeOutput($sf_carrier))] : $configuration['YOOSHOP_CARRIER'];
			$html .= '<p><label>'.Tools::safeOutput($sf_carrier).' : </label>'.$this->_getCarriersSelect($configuration, $actual_value, 'MATCHING['.base64_encode(Tools::safeOutput($sf_carrier)).']').'</p>';
		}
  
		$html .= '<p style="margin-tops:20px"><input type="submit" value="'.$this->l('Update').'" name="rec_shipping_config" class="button"/></p>
				</fieldset>
			</form>';

		return $html;
	}

	private function _getCarriersSelect($configuration, $actual_value, $name = 'YOOSHOP_CARRIER')
	{
		$html = '<select name="'.Tools::safeOutput($name).'">';

		foreach (Carrier::getCarriers($configuration['PS_LANG_DEFAULT'], true, false, false, null, 5) as $carrier)
		{
			$selected = (int)$actual_value === (int)$carrier['id_reference'] ? 'selected = "selected"' : '';
			$html .= '<option value="'.(int)$carrier['id_reference'].'" '.$selected.'>'.Tools::safeOutput($carrier['name']).'</option>';
		}

		$html .= '</select>';

		return $html;
	}

	private function _getImageTypeSelect($configuration)
	{
		$html = '<select name="YOOSHOP_IMAGE">';

		foreach (ImageType::getImagesTypes() as $imagetype)
		{
			$selected = $configuration['YOOSHOP_IMAGE'] == $imagetype['name'] ? 'selected = "selected"' : '';
			$html .= '<option value="'.$imagetype['name'].'" '.$selected.'>'.Tools::safeOutput($imagetype['name']).'</option>';
		}

		$html .= '</select>';

		return $html;
	}

	private function _getOrderStateShippedSelect($configuration)
	{
		$html = '<select name="YOOSHOP_SHIPPED">';

		foreach (OrderState::getOrderStates($configuration['PS_LANG_DEFAULT']) as $orderState)
		{
			$selected = (int)$configuration['YOOSHOP_SHIPPED'] === (int)$orderState['id_order_state'] ? 'selected = "selected"' : '';
			$html .= '<option value="'.$orderState['id_order_state'].'" '.$selected.'>'.Tools::safeOutput($orderState['name']).'</option>';
		}

		$html .= '</select>';

		return $html;
	}

	private function _getOrderStateCanceledSelect($configuration)
	{
		$html = '<select name="YOOSHOP_CANCELED">';

		foreach (OrderState::getOrderStates($configuration['PS_LANG_DEFAULT']) as $orderState)
		{
			$selected = (int)$configuration['YOOSHOP_CANCELED'] === (int)$orderState['id_order_state'] ? 'selected = "selected"' : '';
			$html .= '<option value="'.$orderState['id_order_state'].'" '.$selected.'>'.Tools::safeOutput($orderState['name']).'</option>';
		}

		$html .= '</select>';

		return $html;
	}

	/* Fieldset for feed URI */
	private function _getFeedContent()
	{
		//uri feed
		if (version_compare(_PS_VERSION_, '1.5', '>') && Shop::isFeatureActive())
		{
			$shop = Context::getContext()->shop;
			$base_uri = 'http://'.$shop->domain.$shop->physical_uri.$shop->virtual_uri;
		}
		else
			$base_uri = 'http://'.Tools::getHttpHost().__PS_BASE_URI__;

		$uri = $base_uri.'modules/yooshop/flux.php?token='.Configuration::get('YOOSHOP_TOKEN');
		$logo = $this->default_country->iso_code == 'FR' ? 'fr' : 'us';

		return '
		<img style="margin:10px; width:250px" src="'.Tools::safeOutput($base_uri).'modules/yooshop/img/logo_'.$logo.'.jpg" />
		<fieldset>
			<legend>'.$this->l('Your feeds').'</legend>
			<p>
				<a href="'.Tools::safeOutput($uri).'" target="_blank">
					'.Tools::safeOutput($uri).'
				</a>
			</p>
		</fieldset>
		<br/>';
	}

	/* Form record */
	private function _treatForm()
	{
		$rec_config = Tools::getValue('rec_config');
		$rec_shipping_config = Tools::getValue('rec_shipping_config');

		if ((isset($rec_config) && $rec_config != null))
		{
			$configuration = Configuration::getMultiple(array('YOOSHOP_TRACKING', 'YOOSHOP_BUYLINE',
						'YOOSHOP_ORDERS', 'YOOSHOP_STATUS_SHIPPED', 'YOOSHOP_STATUS_CANCELED',
						'YOOSHOP_LOGIN', 'YOOSHOP_STOCKS', 'YOOSHOP_CARRIER', 'YOOSHOP_IMAGE',
						'YOOSHOP_CANCELED', 'YOOSHOP_SHIPPED'));

			foreach ($configuration as $key => $val)
			{
				$value = Tools::getValue($key, '');
				Configuration::updateValue($key, $value == 'on' ? 'checked' : $value);
			}
		}
		elseif (isset($rec_shipping_config) && $rec_shipping_config != null)
			Configuration::updateValue('YOOSHOP_SHIPPING_MATCHING', serialize(Tools::getValue('MATCHING')));
	}

	/* Clean JSON tags */
	private function clean($string)
	{
		return str_replace("\r\n", '', strip_tags($string));
	}

	/* Feed content */
	private function getSimpleProducts($id_lang, $limit_from, $limit_to)
	{
		if (version_compare(_PS_VERSION_, '1.5', '>'))
		{
			$context = Context::getContext();

			if (!in_array($context->controller->controller_type, array('front', 'modulefront')))
				$front = false;
			else
				$front = true;

			$sql = 'SELECT p.`id_product`, pl.`name`
				FROM `'._DB_PREFIX_.'product` p
				'.Shop::addSqlAssociation('product', 'p').'
				LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.`id_product` = pl.`id_product` '.Shop::addSqlRestrictionOnLang('pl').')
				WHERE pl.`id_lang` = '.(int)$id_lang.' AND product_shop.`active`= 1 AND product_shop.`available_for_order`= 1
				'.($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '').'
				ORDER BY pl.`name`';

			if ($limit_from !== false)
				$sql .= ' LIMIT '.(int)$limit_from.', '.(int)$limit_to;
		}
		else
		{
			$sql = 'SELECT p.`id_product`, pl.`name`
				FROM `'._DB_PREFIX_.'product` p
				LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.`id_product` = pl.`id_product`)
				WHERE pl.`id_lang` = '.(int)($id_lang).' AND p.`active`= 1 AND p.`available_for_order`= 1
				ORDER BY pl.`name`';
		}

		return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
	}

	private function countProducts()
	{
		if (version_compare(_PS_VERSION_, '1.5', '>'))
		{
			$context = Context::getContext();

			if (!in_array($context->controller->controller_type, array('front', 'modulefront')))
				$front = false;
			else
				$front = true;

			$sql_association = Shop::addSqlAssociation('product', 'p');
			$table = $sql_association ? 'product'.'_shop' : 'p';

			$sql = 'SELECT COUNT(p.`id_product`)
				FROM `'._DB_PREFIX_.'product` p
				'.$sql_association.'
				WHERE `id_product` > (SELECT id_product from '._DB_PREFIX_.'last_id_product limit 1) AND '.$table.'.`active`= 1 AND '.$table.'.`available_for_order`= 1
				'.($front ? ' AND '.$table.'.`visibility` IN ("both", "catalog")' : '');
		}
		else
		{
			$sql = 'SELECT COUNT(p.`id_product`)
				FROM `'._DB_PREFIX_.'product` p
				WHERE p.`active`= 1 AND p.`available_for_order`= 1 AND `id_product` > (SELECT id_product from '._DB_PREFIX_.'last_id_product limit 1)';
		}

		return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
	}

	public function generateFeed()
	{
		$j = 0;

		if (Tools::getValue('token') == '' || Tools::getValue('token') != Configuration::get('YOOSHOP_TOKEN'))
			die('[{"error" : "Invalid Token"}]');

		$configuration = Configuration::getMultiple(array('PS_TAX_ADDRESS_TYPE', 'PS_CARRIER_DEFAULT','PS_COUNTRY_DEFAULT',
					'PS_LANG_DEFAULT', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING', 'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT', 'YOOSHOP_IMAGE'));

		$no_breadcrumb = Tools::getValue('no_breadcrumb');

		$lang = Tools::getValue('lang');
		$configuration['PS_LANG_DEFAULT'] = !empty($lang) ? Language::getIdByIso($lang) : $configuration['PS_LANG_DEFAULT'];
		$carrier = Carrier::getCarrierByReference((int)Configuration::get('YOOSHOP_CARRIER'));
		
		//manage case PS_CARRIER_DEFAULT is deleted
		$carrier = is_object($carrier) ? $carrier : new Carrier((int)Configuration::get('YOOSHOP_CARRIER'));
		$products = $this->getSimpleProducts($configuration['PS_LANG_DEFAULT'], false, 0);
		$link = new Link();
		$array = $this->_getDistinctAriane();
		$id = '';
		

		echo "[";
		foreach ($array as $val) 
		{
			$k = 0;
			$j++;

		 	$id = $array[$j-1];
		 	echo "\n\t{\n";
		 	echo "\t\t";
		 	echo '"line" : "'.$j.'",';
		 	echo "\n";
			echo "\t\t".$this->_getFilAriane($id , $configuration).",\n";
			echo "\t\t";
			echo '"'.$this->_translateField('product').'"';
			echo " : \n";
			echo "\t\t\t [\n";

			$Prod = $this->_getIDProduct($id);
			foreach ($Prod as $val)
			{
				$k++;
				echo "\n\t\t\t\t {\n";

				$product = new Product((int)($val['id_product']), true, $configuration['PS_LANG_DEFAULT']);

				echo $this->_getBaseData($product, $configuration, $link, $carrier).",";
				echo $this->_getImages($product, $configuration, $link).",";
				echo $this->_getUrlCategories($product, $configuration, $link).",";
				echo $this->_getFeatures($product, $configuration).",";
				echo $this->_getCombinaisons($product, $configuration, $link, $carrier);

				echo "\n\t\t\t\t }";
				if ($k < count($Prod))
					echo ",";

			}

			echo "\n\t\t\t]";
			echo "\n\t}";

			if ($j < count($array))
				echo ", \n";
		} 
		
		echo "\n\n]";
		
	}

	public function initFeed()
	{
		$file = fopen(dirname(__FILE__).'/feed.json', 'w+');
		fwrite($file, '');
		fclose($file);

		$totalProducts = $this->countProducts();
		$this->writeFeed($totalProducts);
	}

	public function writeFeed($total, $current = 0)
	{
		if (Tools::getValue('token') == '' || Tools::getValue('token') != Configuration::get('YOOSHOP_TOKEN'))
			die('[{ "error" : "Invalid Token" }]');

		if (!is_file(dirname(__FILE__).'/feed.json'))
			die('[{ "error" : "File error" }]');

		$file = fopen(dirname(__FILE__).'/feed.json', 'a+');

		$configuration = Configuration::getMultiple(
						array(
							'PS_TAX_ADDRESS_TYPE', 'PS_CARRIER_DEFAULT', 'PS_COUNTRY_DEFAULT',
							'PS_LANG_DEFAULT', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING',
							'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT', 'YOOSHOP_IMAGE'
						)
		);

		$no_breadcrumb = Tools::getValue('no_breadcrumb');

		$lang = Tools::getValue('lang');
		$configuration['PS_LANG_DEFAULT'] = !empty($lang) ? Language::getIdByIso($lang) : $configuration['PS_LANG_DEFAULT'];
		$carrier = Carrier::getCarrierByReference((int)Configuration::get('YOOSHOP_CARRIER'));

		$passes = Tools::getValue('passes');
		$configuration['PASSES'] = !empty($passes) ? $passes : (int)($total / 20) + 1;

		//manage case PS_CARRIER_DEFAULT is deleted
		$carrier = is_object($carrier) ? $carrier : new Carrier((int)Configuration::get('YOOSHOP_CARRIER'));
		$products = $this->getSimpleProducts($configuration['PS_LANG_DEFAULT'], $current, $configuration['PASSES']);
		$link = new Link();

		$str = '';

		$array = $this->_getDistinctAriane();
		$id = '';
		

		$str .= "[";
		foreach ($array as $val) 
		{
			$k = 0;
			$j++;

		 	$id = $array[$j-1];
		 	$str .= "\n\t{\n";
			$str .= "\t\t".$this->_getFilAriane($id , $configuration).",\n";
			$str .= "\t\t".$this->_translateField('product')." : \n";
			$str .= "\t\t\t [\n";

			$Prod = $this->_getIDProduct($id);
			foreach ($Prod as $val)
			{
				$k++;
				$str .= "\n\t\t\t\t {\n";

				$product = new Product((int)($val['id_product']), true, $configuration['PS_LANG_DEFAULT']);

				$str .= $this->_getBaseData($product, $configuration, $link, $carrier).",";
				$str .= $this->_getImages($product, $configuration, $link).",";
				$str .= $this->_getUrlCategories($product, $configuration, $link).",";
				$str .= $this->_getFeatures($product, $configuration).",";
				$str .= $this->_getCombinaisons($product, $configuration, $link, $carrier);
				
				$str .= "\n\t\t\t\t }";
				if ($k < count($Prod))
					$str .= ",";
				
			}

			$str .= "\n\t\t\t]";
			$str .= "\n\t}";

			if ($j < count($val))
				$str .= ", \n";
		} 

		fwrite($file, $str);
		fclose($file);

		if ($current + $configuration['PASSES'] >= $total)
			$this->closeFeed();
		else
		{
			$next_uri = 'http://'.Tools::getHttpHost().__PS_BASE_URI__.'modules/yooshop/cron.php?token='.Configuration::get('YOOSHOP_TOKEN').'&current='.($current + $configuration['PASSES']).'&total='.$total.'&passes='.$configuration['PASSES'].(!empty($no_breadcrumb) ? '&no_breadcrumb=true' : '');
			header('Location:'.$next_uri);
		}
	}

	private function closeFeed()
	{
		$file = fopen(dirname(__FILE__).'/feed.json', 'a+');
		fwrite($file, "\n\n]");
	}

	/* Default data, in Product Class */
	private function _getBaseData($product, $configuration, $link, $carrier)
	{
		$ret = '';
		$j = 0;

		$titles = array(
			0 => 'id',
			1 => $this->_translateField('name'),
			2 => $this->_translateField('link'),
			4 => 'description',
			5 => $this->_translateField('short_description'),
			6 => $this->_translateField('price'),
			7 => $this->_translateField('old_price'),
			8 => $this->_translateField('shipping_cost'),
			9 => $this->_translateField('shipping_delay'),
			10 => $this->_translateField('brand'),
			11 => $this->_translateField('category'),
			13 => $this->_translateField('quantity'),
			14 => 'ean',
			15 => $this->_translateField('weight'),
			16 => $this->_translateField('ecotax'),
			17 => $this->_translateField('vat'),
			18 => $this->_translateField('mpn'),
			19 => $this->_translateField('supplier_reference'),
			20 => 'upc',
			21 => 'wholesale-price'
		);

		$data = array();
		$data[0] = $product->id;
		$data[1] = $product->name;
		$data[2] = $link->getProductLink($product);
		$data[4] = $product->description;
		$data[5] = $product->description_short;
		$data[6] = $product->getPrice(true, null, 2, null, false, true, 1);
		$data[7] = $product->getPrice(true, null, 2, null, false, false, 1);
		$data[8] = $this->_getShipping($product, $configuration, $carrier);
		$data[9] = $carrier->delay[$configuration['PS_LANG_DEFAULT']];
		$data[10] = $product->manufacturer_name;
		$data[11] = $this->_getCategories($product, $configuration);
		$data[13] = $product->quantity;
		$data[14] = $product->ean13;
		$data[15] = $product->weight;
		$data[16] = $product->ecotax;
		$data[17] = $product->tax_rate;
		$data[18] = $product->reference;
		$data[19] = $product->supplier_reference;
		$data[20] = $product->upc;
		$data[21] = $product->wholesale_price;

		foreach ($titles as $key => $balise)
		{
			$j++;
			$ret .= "\t\t\t\t\t";
			$ret .= '"'.$balise.'" : "'.$data[$key].'"';
			if ($j < count($titles))
				$ret .=",\n";
		}

		return $ret;
	}

	/* Shipping prices */
	private function _getShipping($product, $configuration, $carrier, $attribute_id = null, $attribute_weight = null)
	{
		$default_country = new Country($configuration['PS_COUNTRY_DEFAULT'], $configuration['PS_LANG_DEFAULT']);
		$id_zone = (int)$default_country->id_zone;
		$this->id_address_delivery = 0;
		$carrier_tax = Tax::getCarrierTaxRate((int)$carrier->id, (int)$this->{$configuration['PS_TAX_ADDRESS_TYPE']});

		$shipping = 0;

		$product_price = $product->getPrice(true, $attribute_id, 2, null, false, true, 1);
		$shipping_free_price = $configuration['PS_SHIPPING_FREE_PRICE'];
		$shipping_free_weight = isset($configuration['PS_SHIPPING_FREE_WEIGHT']) ? $configuration['PS_SHIPPING_FREE_WEIGHT'] : 0;

		if (!(((float)$shipping_free_price > 0) && ($product_price >= (float)$shipping_free_price)) &&
				!(((float)$shipping_free_weight > 0) && ($product->weight + $attribute_weight >= (float)$shipping_free_weight)))
		{
			if (isset($configuration['PS_SHIPPING_HANDLING']) && $carrier->shipping_handling)
				$shipping = (float)($configuration['PS_SHIPPING_HANDLING']);

			if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
				$shipping += $carrier->getDeliveryPriceByWeight($product->weight, $id_zone);
			else
				$shipping += $carrier->getDeliveryPriceByPrice($product_price, $id_zone);

			$shipping *= 1 + ($carrier_tax / 100);
			$shipping = (float)(Tools::ps_round((float)($shipping), 2));
		}

		return (float)$shipping + (float)$product->additional_shipping_cost;
	}

	/* Product category */
	private function _getCategories($product, $configuration)
	{
		return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
			SELECT cl.`name`
			FROM `'._DB_PREFIX_.'product` p
			'.Shop::addSqlAssociation('product', 'p').'
			LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (product_shop.`id_category_default` = cl.`id_category`)
			WHERE p.`id_product` = '.(int)$product->id.'
			AND cl.`id_lang` = '.(int)$configuration['PS_LANG_DEFAULT']);
	}

	/* Images URIs */
	private function getImages($id_product, $id_lang)
	{
		return Db::getInstance()->ExecuteS('
			SELECT i.`cover`, i.`id_image`, il.`legend`, i.`position`
			FROM `'._DB_PREFIX_.'image` i
			LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (i.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)($id_lang).')
			WHERE i.`id_product` = '.(int)($id_product).'
			ORDER BY i.cover DESC, i.`position` ASC ');
	}

	private function _getImages($product, $configuration, $link)
	{
		$i = 0;
		$images = $this->getImages($product->id, $configuration['PS_LANG_DEFAULT']);
		$ret = "\n\t\t\t\t\t";
		$ret .= '"Images" : ';
		$ret .= "\n\t\t\t\t\t\t[{";
		if ($images != false)
		{
			foreach ($images as $image)
			{ 
				$i++;
				$ret .= "\n\t\t\t\t\t\t\t";
				$ids = $product->id.'-'.$image['id_image'];
				$ret .= '"image" : "http://'.$link->getImageLink($product->link_rewrite, $ids, $configuration['YOOSHOP_IMAGE']).'"';
				if ($i < count($images))
					$ret .= ' , ';
				$ret = str_replace('http://http://', 'http://', $ret);
				
			}
		}
		$ret .= "\n\t\t\t\t\t\t}]";
		return $ret;
	}

	/* Categories URIs */
	private function _getUrlCategories($product, $configuration, $link)
	{
		$i = 0;
		$ret = "\n\t\t\t\t\t";
		$ret .= '"uri-categories" : ';
		$ret .= "\n\t\t\t\t\t\t{";

		foreach ($this->_getProductCategoriesFull($product->id, $configuration['PS_LANG_DEFAULT']) as $key => $categories)
		{
			$i++;
			$ret .= "\n\t\t\t\t\t\t\t";
			$ret .= '"uri" : "'.$link->getCategoryLink($key, null, $configuration['PS_LANG_DEFAULT']).'"';
			if ($i < count($this->_getProductCategoriesFull($product->id, $configuration['PS_LANG_DEFAULT'])))
				$ret .= ' , ';
		}

		$ret .= "\n\t\t\t\t\t\t}";
		return $ret;
	}

	/* All product categories */
	private function _getProductCategoriesFull($id_product, $id_lang)
	{
		$row = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
			SELECT cp.`id_category`, cl.`name`, cl.`link_rewrite` FROM `'._DB_PREFIX_.'category_product` cp
			LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (cp.`id_category` = cl.`id_category`)
			WHERE cp.`id_product` = '.(int)$id_product.'
			AND cl.`id_lang` = '.(int)$id_lang.'
			ORDER BY cp.`position` DESC');

		$ret = array();

		foreach ($row as $val)
			$ret[$val['id_category']] = $val;

		return $ret;
	}

	/* Features */
	private function _getFeatures($product, $configuration)
	{
		$i = 0;
		$ret = "\n\t\t\t\t\t";
		$ret .= '"caracteristiques" : ';
		$ret .= "\n\t\t\t\t\t\t[{";
		foreach ($product->getFrontFeatures($configuration['PS_LANG_DEFAULT']) as $feature)
		{
			$i++;
			$ret .= "\n\t\t\t\t\t\t\t";
			$feature['name'] = $this->_clean($feature['name']);

			if (!empty($feature['name']))
			{
				$ret .= '"'.$feature['name'].'" : "'.$feature['value'].'",';
			}
		}
		$ret .= "\n\t\t\t\t\t\t\t";
		$ret .= '"meta_title" : "'.$product->meta_title.'",';
		$ret .= "\n\t\t\t\t\t\t\t";
		$ret .= '"meta_description" : "'.$product->meta_description.'",';
		$ret .= "\n\t\t\t\t\t\t\t";
		$ret .= '"meta_keywords" : "'.$product->meta_keywords.'",';
		$ret .= "\n\t\t\t\t\t\t\t";

		$ret .= '"width" : "'.$product->width.'",';
		$ret .= "\n\t\t\t\t\t\t\t";
		$ret .= '"depth" : "'.$product->depth.'",';
		$ret .= "\n\t\t\t\t\t\t\t";
		$ret .= '"height" : "'.$product->height.'",';
		$ret .= "\n\t\t\t\t\t\t\t";

		$ret .= '"state" : "'.$product->condition.'",';
		$ret .= "\n\t\t\t\t\t\t\t";
		$ret .= '"available_for_order" : "'.$product->available_for_order.'"';
		
		$ret .= "\n\t\t\t\t\t\t}]";
		return $ret;
	}

	/* Product attributes */
	private function _getAttributeImageAssociations($id_product_attribute)
	{
		$combinationImages = array();
		$data = Db::getInstance()->ExecuteS('
			SELECT pai.`id_image`
			FROM `'._DB_PREFIX_.'product_attribute_image` pai
			LEFT JOIN `'._DB_PREFIX_.'image` i ON pai.id_image = i.id_image
			WHERE pai.`id_product_attribute` = '.(int)($id_product_attribute).'
			ORDER BY i.cover DESC, i.position ASC
		');

		foreach ($data as $row)
			$combinationImages[] = (int)($row['id_image']);

		return $combinationImages;
	}

	private function _getCombinaisons($product, $configuration, $link, $carrier)
	{
		$combinations = array();

		$i = 0;
		$ret = "\n\t\t\t\t\t";
		$ret .= '"declinaisons" : ';
		$ret .= "\n\t\t\t\t\t\t[";

		foreach ($product->getAttributeCombinaisons($configuration['PS_LANG_DEFAULT']) as $combinaison)
		{
			$combinations[$combinaison['id_product_attribute']]['attributes'][$combinaison['group_name']] = $combinaison['attribute_name'];
			$combinations[$combinaison['id_product_attribute']]['ean13'] = $combinaison['ean13'];
			$combinations[$combinaison['id_product_attribute']]['upc'] = $combinaison['upc'];
			$combinations[$combinaison['id_product_attribute']]['quantity'] = $combinaison['quantity'];
			$combinations[$combinaison['id_product_attribute']]['weight'] = $combinaison['weight'];
			$combinations[$combinaison['id_product_attribute']]['reference'] = $combinaison['reference'];
		}

		foreach ($combinations as $id => $combination)
		{
			$i++;
			$ret .= "\n\t\t\t\t\t\t\t{";
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"id" : "'.$id.'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"ean" : "'.$combination['ean13'].'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"upc" : "'.$combination['upc'].'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"'.$this->_translateField('quantity').'" : "'.$combination['quantity'].'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"'.$this->_translateField('weight').'" : "'.$combination['weight'].'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"'.$this->_translateField('price').'" : "'.$product->getPrice(true, $id, 2, null, false, true, 1).'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"'.$this->_translateField('old_price').'" : "'.$product->getPrice(true, $id, 2, null, false, false, 1).'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"'.$this->_translateField('shipping_cost').'" : "'.$this->_getShipping($product, $configuration, $carrier, $id, $combination['weight']).'",';
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"images" : ';
			$ret .= "\n\t\t\t\t\t\t\t\t\t{";

			$image_child = true;
			$c = 0;
			foreach ($this->_getAttributeImageAssociations($id) as $image)
			{
				$c++;
				if (empty($image))
				{
					$image_child = false;
					break;
				}
				$ret .= "\n\t\t\t\t\t\t\t\t\t\t";
				$ret .= '"image" : "http://'.$link->getImageLink($product->link_rewrite, $product->id.'-'.$image, $configuration['YOOSHOP_IMAGE']).'"';
				if ($c < count($this->_getAttributeImageAssociations($id)))
					$ret .= ",";
				$ret = str_replace('http://http://', 'http://', $ret);
			}

			$ret .= "\n\t\t\t\t\t\t\t\t\t},";
			$ret .= "\n\t\t\t\t\t\t\t\t";
			$ret .= '"attributs" : ';
			$ret .= "\n\t\t\t\t\t\t\t\t\t[";
			$ret .= "\n\t\t\t\t\t\t\t\t\t\t{";
			asort($combination['attributes']);
			foreach ($combination['attributes'] as $attributeName => $attributeValue)
			{

				$attributeName = $this->_clean($attributeName);
				if (!empty($attributeName))
				{
					$ret .= "\n\t\t\t\t\t\t\t\t\t\t\t";
					$ret .= '"'.$attributeName.'" : "'.$attributeValue.'",';
				}
			}
			$ret .= "\n\t\t\t\t\t\t\t\t\t\t\t";
			$ret .= '"'.$this->_translateField('mpn').'" : "'.$combination['reference'].'"';

			$ret .= "\n\t\t\t\t\t\t\t\t\t\t}";
			$ret .= "\n\t\t\t\t\t\t\t\t\t]";
			$ret .= "\n\t\t\t\t\t\t\t}";

			if ($i < count($combinations))
				$ret .= ",";
		}

		$ret .= "\n\t\t\t\t\t\t]";
		
		return $ret;
	}

	/* Category tree JSON */
	private function _getFilAriane($id , $configuration)
	{
		$category = '';
		$ret = '"'.$this->_translateField('category_breadcrumb').'" : ';

		foreach ($this->_getProductFilAriane($id , $configuration['PS_LANG_DEFAULT']) as $categories)
			$category .= $categories.' > ';

		$ret .= '"'.Tools::substr($category, 0, -3).'"';
		return $ret;
	}

	private function _getDistinctAriane()
	{
		$i = array();

		$Cat = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
			SELECT DISTINCT (`id_category_default`) as id_category FROM `'._DB_PREFIX_.'product` WHERE id_product > (SELECT id_product FROM '._DB_PREFIX_.'last_id_prod ORDER BY id_product DESC LIMIT 1)');

		foreach ($Cat as $cat) 
		{
			$i[] = $cat['id_category'];
		}

		return $i;
	}

	private function _getIDProduct($category)
	{
		$res = array(); 
		return Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
			SELECT id_product FROM '._DB_PREFIX_.'product WHERE id_category_default = '.$category);
	}

	/* Category tree */
	private function _getProductFilAriane($id , $id_lang)
	{
		$ret = array();
		$id_category = '';
		$id_parent = '';

		$row = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
		SELECT cl.`name`, p.`id_category_default` as id_category, c.`id_parent` FROM `'._DB_PREFIX_.'product` p
		LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (p.`id_category_default` = cl.`id_category`)
		LEFT JOIN `'._DB_PREFIX_.'category` c ON (p.`id_category_default` = c.`id_category`)
		WHERE p.`id_category_default` = '.(int)$id.'
		AND cl.`id_lang` = '.(int)$id_lang);

		foreach ($row as $val)
		{
			$ret[$val['id_category']] = $val['name'];
			$id_parent = $val['id_parent'];
			$id_category = $val['id_category'];
		}

		while ($id_parent != 0 && $id_category != $id_parent)
		{
			$row = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
				SELECT cl.`name`, c.`id_category`, c.`id_parent` FROM `'._DB_PREFIX_.'category_lang` cl
				LEFT JOIN `'._DB_PREFIX_.'category` c ON (c.`id_category` = '.(int)$id_parent.')
				WHERE cl.`id_category` = '.(int)$id_parent.'
				AND cl.`id_lang` = '.(int)$id_lang);

			foreach ($row as $val)
			{
				$ret[$val['id_category']] = $val['name'];
				$id_parent = $val['id_parent'];
				$id_category = $val['id_category'];
			}
		}		

		$ret = array_reverse($ret);
		return $ret;
	}

	public function hookbackOfficeTop($no_cron = true)
	{
		$First = "";
		$Last = "";
		$email = "";
		$address = "";
		$Products = "";

		if ((Tools::strtolower(Tools::getValue('controller')) == 'adminorders' &&
				Configuration::get('YOOSHOP_ORDERS') != '' &&
				in_array('curl', get_loaded_extensions())) ||
				$no_cron == false)
		{

			$ordersJSON = $this->_callWebService('getOrders','');
			$json = json_decode($ordersJSON);

			if (count($json) == 0)
			{
				return;
			}

			foreach ($json as $order)
			{
				
				try
				{
					foreach ($order->BillingAddress as $ke) 
					{
						$First = $ke->Firstname;
						$Last = $ke->Lastname;
						$email = $ke->Email;
						$address = array("Lastname" => $Last , "Firstname" => $First );
					}
					foreach ($order->Products as $value) 
					{
						
						$Products = array("SKU" => array ("sku" =>$value->SKU[0]->sku , "sku" => $value->SKU[1]->sku), "Quantity" => $value->Quantity);
						
					}

					$orderExists = Db::getInstance()->getRow('SELECT m.id_message  FROM '._DB_PREFIX_.'message m
						WHERE m.message LIKE "%Numéro de commande '.pSQL($order->Marketplace).' :'.pSQL($order->IdOrder).'%"');

					if (isset($orderExists['id_message']))
					{
						$this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace);

						continue;
					}

					$mail = (string)$email;
					$email = (empty($mail)) ? pSQL($order->IdOrder.'@'.$order->Marketplace.'.ys') : pSQL($mail);
					
					$id_customer = $this->_getCustomer($email, (string)$Last, (string)$First);
					
					//avoid update of old orders by the same merchant with different addresses
					$id_address_billing = $this->_getAddress($address, $id_customer, 'Billing-'.(string)$order->IdOrder);
					echo "ll";
					die();
					$id_address_shipping = $this->_getAddress($address, $id_customer, 'Shipping-'.(string)$order->IdOrder);
					
					$products_available = $this->_checkProducts($Products);

					$current_customer = new Customer((int)$id_customer);

					if ($products_available && $id_address_shipping && $id_address_billing && $id_customer)
					{
						$cart = $this->_getCart($id_customer, $id_address_billing, $id_address_shipping, $Products, (string)$order->Currency, (string)$order->ShippingMethod);

						if ($cart)
						{
							//compatibylity with socolissmo
							$this->context->cart = $cart;
							Db::getInstance()->autoExecute(_DB_PREFIX_.'customer', array('email' => 'donotreply@yooshop.com'), 'UPDATE', '`id_customer` = '.(int)$id_customer);

							$customerClear = new Customer();

							if (method_exists($customerClear, 'clearCache'))
								$customerClear->clearCache(true);

							$payment = $this->_validateOrder($cart, $order->Marketplace);
							$id_order = $payment->currentOrder;

							//we valid there
							$this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, $id_order);

							$reference_order = $payment->currentOrderReference;

							Db::getInstance()->autoExecute(_DB_PREFIX_.'customer', array('email' => pSQL($email)), 'UPDATE', '`id_customer` = '.(int)$id_customer);

							Db::getInstance()->autoExecute(_DB_PREFIX_.'message', array('id_order' => $id_order, 'message' => 'Numéro de commande '.pSQL($order->Marketplace).' :'.pSQL($order->IdOrder), 'date_add' => date('Y-m-d H:i:s')), 'INSERT');
							$this->_updatePrices($id_order, $order, $reference_order);
						}
					}

					$cartClear = new Cart();

					if (method_exists($cartClear, 'clearCache'))
						$cartClear->clearCache(true);

					$addressClear = new Address();

					if (method_exists($addressClear, 'clearCache'))
						$addressClear->clearCache(true);

					$customerClear = new Customer();

					if (method_exists($customerClear, 'clearCache'))
						$customerClear->clearCache(true);
				}
				catch (PrestaShopException $pe)
				{
					$this->_validOrders((string)$order->IdOrder, (string)$order->Marketplace, false, $pe->getMessage());
				}
			}
		}
	}

	public function hookNewOrder($params)
	{
		$ip = Db::getInstance()->getValue('SELECT `ip` FROM `'._DB_PREFIX_.'custumer_yooshop_ip` WHERE `id_customer` = '.(int)$params['order']->id_customer);
		if (empty($ip))
			$ip = $_SERVER['REMOTE_ADDR'];

		//if ((Configuration::get('YOOSHOP_TRACKING') != '' || Configuration::get('YOOSHOP_BUYLINE') != '') && Configuration::get('YOOSHOP_ID') != '' && !in_array($params['order']->payment, $this->_getMarketplaces()))
		//	Tools::file_get_contents('https://tag.shopping-flux.com/order/'.base64_encode(Configuration::get('YOOSHOP_ID').'|'.$params['order']->id.'|'.$params['order']->total_paid).'?ip='.$ip);

		if (Configuration::get('YOOSHOP_STOCKS') != '' && !in_array($params['order']->payment, $this->_getMarketplaces()))
		{
			foreach ($params['cart']->getProducts() as $product)
			{
				$id = (isset($product['id_product_attribute'])) ? (int)$product['id_product'].'_'.(int)$product['id_product_attribute'] : (int)$product['id_product'];
				$qty = (int)$product['stock_quantity'] - (int)$product['quantity'];

				$json = '[{';
				$json .= '"Product" : {';
				$json .= '"SKU" : "' .$id.'" , ';
				$json .= '"Quantity" : "'.$qty.'"';
				$json .= '}';
				$json .= '}]';

				$this->_callWebService('updateProducts', $json);
			}
		}
	}

	public function hookFooter()
	{
		if (Configuration::get('YOOSHOP_BUYLINE') != '' && Configuration::get('YOOSHOP_ID') != '')
			return '<script type="text/javascript">
						var sf2 = sf2 || [];
						sf2.push([\''.Configuration::get('YOOSHOP_ID').'\'],[escape(document.referrer)]);
						(function() {
						var sf_script = document.createElement(\'script\');
						sf_script.src = (\'https:\' == document.location.protocol ? \'https://\' : \'http://\') + \'tag.shopping-feed.com/buyline.js\';
						sf_script.setAttribute(\'async\', \'true\');
						document.documentElement.firstChild.appendChild(sf_script);
						})();
						</script>';
		return '';
	}

	public function hookPostUpdateOrderStatus($params)
	{
		if ((Configuration::get('YOOSHOP_STATUS_SHIPPED') != '' &&
				Configuration::get('YOOSHOP_SHIPPED') == '' &&
				$this->_getOrderStates(Configuration::get('PS_LANG_DEFAULT'), 'shipped') == $params['newOrdersStatus']->name) ||
				(Configuration::get('YOOSHOP_STATUS_SHIPPED') != '' &&
				(int)Configuration::get('YOOSHOP_SHIPPED') == $params['newOrdersStatus']->id))
		{
			$order = new Order((int)$params['id_order']);
			$shipping = $order->getShipping();

			if (in_array($order->payment, $this->_getMarketplaces()))
			{
				$message = $order->getFirstMessage();
				$id_order_marketplace = explode(':', $message);
				$id_order_marketplace[1] = trim($id_order_marketplace[1]) == 'True' ? '' : $id_order_marketplace[1];

				$json = '[{';
				$json .= '"Order" : {';
				$json .= '"IdOrder" : "'.$id_order_marketplace[1].'" , ';
				$json .= '"Marketplace" : "'.$order->payment.'" , ';
				$json .= '"MerchantIdOrder" : "'.(int)$params['id_order'].'",';
				$json .= '"Status" : "Shipped"';

				if (isset($shipping[0]))
				{
					$json .= '"TrackingNumber" : "'.$shipping[0]['tracking_number'].'",';
					$json .= '"CarrierName" : "'.$shipping[0]['state_name'].'"';
				}

				$json .= '}';
				$json .= '}]';

				$responseJSON = $this->_callWebService('updateOrders', $json);

				if (!$responseJSON->Response->Error)
					Db::getInstance()->autoExecute(_DB_PREFIX_.'message', array('id_order' => pSQL((int)$order->id), 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseJSON->Response->Orders->Order->StatusUpdated), 'date_add' => date('Y-m-d H:i:s')), 'INSERT');
				else
					Db::getInstance()->autoExecute(_DB_PREFIX_.'message', array('id_order' => pSQL((int)$order->id), 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseJSON->Response->Error->Message), 'date_add' => date('Y-m-d H:i:s')), 'INSERT');
			}
		}

		elseif ((Configuration::get('YOOSHOP_STATUS_CANCELED') != '' &&
				Configuration::get('YOOSHOP_CANCELED') == '' &&
				$this->_getOrderStates(Configuration::get('PS_LANG_DEFAULT'), 'order_canceled') == $params['newOrdersStatus']->name) ||
				(Configuration::get('YOOSHOP_STATUS_CANCELED') != '' &&
				(int)Configuration::get('YOOSHOP_CANCELED') == $params['newOrdersStatus']->id))
		{
			$order = new Order((int)$params['id_order']);
			$shipping = $order->getShipping();

			if (in_array($order->payment, $this->_getMarketplaces()))
			{
				$message = $order->getFirstMessage();
				$id_order_marketplace = explode(':', $message);
				$id_order_marketplace[1] = trim($id_order_marketplace[1]) == 'True' ? '' : $id_order_marketplace[1];

				$json = '[{';
				$json .= '"Order" : {';
				$json .= '"IdOrder" : "'.$id_order_marketplace[1].'",';
				$json .= '"Marketplace" : "'.$order->payment.'",';
				$json .= '"MerchantIdOrder" : "'.(int)$params['id_order'].'" ,';
				$json .= '<Status>Canceled</Status>';
				$json .= '}';
				$json .= '}]';

				$responseJSON = $this->_callWebService('updateOrders', $json);

				if (!$responseJSON->Response->Error)
					Db::getInstance()->autoExecute(_DB_PREFIX_.'message', array('id_order' => (int)$order->id, 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseJSON->Response->Orders->Order->StatusUpdated), 'date_add' => date('Y-m-d H:i:s')), 'INSERT');
				else
					Db::getInstance()->autoExecute(_DB_PREFIX_.'message', array('id_order' => $order->id, 'message' => 'Statut mis à jour sur '.pSQL((string)$order->payment).' : '.pSQL((string)$responseJSON->Response->Error->Message), 'date_add' => date('Y-m-d H:i:s')), 'INSERT');
			}

		}
	}

	public function hookupdateProductAttribute($params)
	{
		if (Configuration::get('YOOSHOP_STOCKS') != '')
		{
			$data = Db::getInstance()->getRow('SELECT `id_product`,`quantity` FROM `'._DB_PREFIX_.'product_attribute` WHERE `id_product_attribute` = '.(int)$params['id_product_attribute']);

			$json = '[';
			$json .= '{';
			$json .= '"id" : '.(int)$data['id_product'].'_'.(int)$params['id_product_attribute'].' , ';
			$json .= '"Quantity" : '.(int)$data['quantity'].' , ';
			$json .= '"Price" : '.Product::getPriceStatic((int)$data['id_product'], true, (int)$params['id_product_attribute'], 2, null, false, true, 1).' , ';
			$json .= '"OldPrice" : '.Product::getPriceStatic((int)$data['id_product'], true, (int)$params['id_product_attribute'], 2, null, false, false, 1).' , ';
			$json .= '}';
			$json .= ']';

			$this->_callWebService('updateProducts', $json);
		}
	}

	public function hookupdateProduct($params)
	{
		if (isset($params['product']) && is_object($params['product']) && Configuration::get('YOOSHOP_STOCKS') != '')
		{
			$json = '[';
			$json .= '{';
			$json .= '"id" : '.(int)$params['product']->id.' , ';
			$json .= '"Quantity" : '.(int)$params['product']->quantity.' , ';
			$json .= '"Price" : '.$params['product']->getPrice(true, null, 2, null, false, true, 1).' , ';
			$json .= '"OldPrice " : '.$params['product']->getPrice(true, null, 2, null, false, false, 1).'';
			$json .= '}';
			$json .= ']';

			$this->_callWebService('updateProducts', $json);
		}
	}

	public function hookTop()
	{
		global $cookie;

		if ((int)Db::getInstance()->getValue('SELECT `id_custumer_yooshop_ip` FROM `'._DB_PREFIX_.'custumer_yooshop_ip` WHERE `id_customer` = '.(int)$cookie->id_customer) > 0)
		{
			$updateIp = array('ip' => pSQL($_SERVER['REMOTE_ADDR']));
			Db::getInstance()->autoExecute(_DB_PREFIX_.'custumer_yooshop_ip', $updateIp, 'UPDATE', '`id_customer` = '.(int)$cookie->id_customer);
		}
		else
		{
			$insertIp = array('id_customer' => (int)$cookie->id_customer, 'ip' => pSQL($_SERVER['REMOTE_ADDR']));
			Db::getInstance()->autoExecute(_DB_PREFIX_.'custumer_yooshop_ip', $insertIp, 'INSERT');
		}
	}

	/* Clean JSON strings */
	private function _clean($string)
	{
		return preg_replace('/[^A-Za-z]/', '', $string);
	}

	/* Call Shopping Flux Webservices */
	private function _callWebService($call, $json)
	{
		$token = Configuration::get('YOOSHOP_TOKEN');
		if (empty($token))
			return false;

		$url = 'http://localhost/M2/api/service/'.$call.'?data='.$token.'&url=http://'.Tools::getHttpHost().__PS_BASE_URI__.'modules/yooshop/';

		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);

		$curl_response = curl_exec($curl);

		curl_close($curl);

		return $curl_response;
	}

	private function _getOrderStates($id_lang, $type)
	{
		return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
			SELECT osl.name
			FROM `'._DB_PREFIX_.'order_state` os
			LEFT JOIN `'._DB_PREFIX_.'order_state_lang` osl
			ON (os.`id_order_state` = osl.`id_order_state`
			AND osl.`id_lang` = '.(int)$id_lang.')
			WHERE `template` = "'.pSQL($type).'"');
	}

	private function _getAddress($addressNode, $id_customer, $type)
	{
		//alias is limited
		$type = Tools::substr($type, 0, 32);

		$id_address = (int)Db::getInstance()->getValue('SELECT `id_address`
			FROM `'._DB_PREFIX_.'address` WHERE `id_customer` = '.(int)$id_customer.' AND `alias` = \''.pSQL($type).'\'');

		if ($id_address)
			$address = new Address((int)$id_address);
		else
			$address = new Address();

		$customer = new Customer((int)$id_customer);

		$street1 = '';
		$street2 = '';
		$line2 = false;
		/*$streets = explode(' ', (string)$addressNode['Street']);

		foreach ($streets as $street)
		{
			if (Tools::strlen($street1) + Tools::strlen($street) + 1 < 32 && !$line2)
				$street1 .= $street.' ';
			else
			{
				$line2 = true;
				$street2 .= $street.' ';
			}
		}*/

		$lastname = (string)$addressNode['LastName'];
		$firstname = (string)$addressNode['FirstName'];

		$lastname = preg_replace('/\-?\d+/', '', $lastname);
		$firstname = preg_replace('/\-?\d+/', '', $firstname);

		$address->id_customer = (int)$id_customer;
		//$address->id_country = (int)Country::getByIso(trim($addressNode['Country']));
		$address->alias = pSQL($type);
		$address->lastname = (!empty($lastname)) ? pSQL($lastname) : $customer->lastname;
		$address->firstname = (!empty($firstname)) ? pSQL($firstname) : $customer->firstname;
		$address->address1 = pSQL($street1);
		$address->address2 = pSQL($street2);
		//$address->company = pSQL($addressNode->Company);
		//$address->other = Tools::substr(pSQL($addressNode->Other), 0, 300);
		//$address->postcode = pSQL($addressNode->PostalCode);
		//$address->city = pSQL($addressNode->Town);
		//$address->phone = Tools::substr(pSQL($addressNode->Phone), 0, 16);
		//$address->phone_mobile = Tools::substr(pSQL($addressNode->PhoneMobile), 0, 16);

		if ($id_address)
			$address->update();
		else
			$address->add();

		return $address->id;
	}

	private function _getCustomer($email, $lastname, $firstname)
	{
		$id_customer = (int)Db::getInstance()->getValue('SELECT `id_customer`
			FROM `'._DB_PREFIX_.'customer` WHERE `email` = \''.pSQL($email).'\'');

		if ($id_customer)
			return $id_customer;

		$lastname = preg_replace('/\-?\d+/', '', $lastname);
		$firstname = preg_replace('/\-?\d+/', '', $firstname);

		$customer = new Customer();
		$customer->lastname = (!empty($lastname)) ? pSQL($lastname) : '-';
		$customer->firstname = (!empty($firstname)) ? pSQL($firstname) : '-';
		$customer->passwd = md5(pSQL(_COOKIE_KEY_.rand()));
		$customer->id_default_group = 1;
		$customer->email = pSQL($email);
		$customer->add();

		return $customer->id;
	}

	private function _updatePrices($id_order, $order, $reference_order)
	{
		$tax_rate = 0;

		foreach ($order->Products->Product as $product)
		{
			$skus = explode('_', $product->SKU);

			$row = Db::getInstance()->getRow('SELECT t.rate, od.id_order_detail  FROM '._DB_PREFIX_.'tax t
				LEFT JOIN '._DB_PREFIX_.'order_detail_tax odt ON t.id_tax = odt.id_tax
				LEFT JOIN '._DB_PREFIX_.'order_detail od ON odt.id_order_detail = od.id_order_detail
				WHERE od.id_order = '.(int)$id_order.' AND product_id = '.(int)$skus[0].' AND product_attribute_id = '.(int)$skus[1]);

			$tax_rate = $row['rate'];
			$id_order_detail = $row['id_order_detail'];

			$updateOrderDetail = array(
				'product_price' => (float)((float)$product->Price / (1 + ($tax_rate / 100))),
				'reduction_percent' => 0,
				'reduction_amount' => 0,
				'ecotax' => 0,
				'total_price_tax_incl' => (float)((float)$product->Price * $product->Quantity),
				'total_price_tax_excl' => (float)(((float)$product->Price / (1 + ($tax_rate / 100))) * $product->Quantity),
				'unit_price_tax_incl' => (float)$product->Price,
				'unit_price_tax_excl' => (float)((float)$product->Price / (1 + ($tax_rate / 100))),
			);

			Db::getInstance()->autoExecute(_DB_PREFIX_.'order_detail', $updateOrderDetail, 'UPDATE', '`id_order` = '.(int)$id_order.' AND `product_id` = '.(int)$skus[0].' AND `product_attribute_id` = '.(int)$skus[1]);

			$updateOrderDetailTax = array(
				'unit_amount' => (float)((float)$product->Price - ((float)$product->Price / (1 + ($tax_rate / 100)))),
				'total_amount' => (float)(((float)$product->Price - ((float)$product->Price / (1 + ($tax_rate / 100)))) * $product->Quantity),
			);

			Db::getInstance()->autoExecute(_DB_PREFIX_.'order_detail_tax', $updateOrderDetailTax, 'UPDATE', '`id_order_detail` = '.(int)$id_order_detail);
		}

		$actual_configuration = unserialize(Configuration::get('YOOSHOP_SHIPPING_MATCHING'));
		
		$carrier_to_load = isset($actual_configuration[base64_encode(Tools::safeOutput((string)$order->ShippingMethod))]) ?
				(int)$actual_configuration[base64_encode(Tools::safeOutput((string)$order->ShippingMethod))] :
				(int)Configuration::get('YOOSHOP_CARRIER');
		
		$carrier = Carrier::getCarrierByReference($carrier_to_load);

		//manage case PS_CARRIER_DEFAULT is deleted
		$carrier = is_object($carrier) ? $carrier : new Carrier($carrier_to_load);

		$updateOrder = array(
			'total_paid' => (float)($order->TotalAmount),
			'total_paid_tax_incl' => (float)($order->TotalAmount),
			'total_paid_tax_excl' => (float)((float)$order->TotalAmount / (1 + ($tax_rate / 100))),
			'total_paid_real' => (float)($order->TotalAmount),
			'total_products' => (float)(Db::getInstance()->getValue('SELECT SUM(`product_price`)*`product_quantity` FROM `'._DB_PREFIX_.'order_detail` WHERE `id_order` = '.(int)$id_order)),
			'total_products_wt' => (float)($order->TotalProducts),
			'total_shipping' => (float)($order->TotalShipping),
			'total_shipping_tax_incl' => (float)($order->TotalShipping),
			'total_shipping_tax_excl' => (float)((float)$order->TotalShipping / (1 + ($tax_rate / 100))),
			'id_carrier' => $carrier->id
		);

		Db::getInstance()->autoExecute(_DB_PREFIX_.'orders', $updateOrder, 'UPDATE', '`id_order` = '.(int)$id_order);

		$updateOrderInvoice = array(
			'total_paid_tax_incl' => (float)($order->TotalAmount),
			'total_paid_tax_excl' => (float)((float)$order->TotalAmount / (1 + ($tax_rate / 100))),
			'total_products' => (float)(Db::getInstance()->getValue('SELECT SUM(`product_price`)*`product_quantity` FROM `'._DB_PREFIX_.'order_detail` WHERE `id_order` = '.(int)$id_order)),
			'total_products_wt' => (float)($order->TotalProducts),
			'total_shipping_tax_incl' => (float)($order->TotalShipping),
			'total_shipping_tax_excl' => (float)((float)$order->TotalShipping / (1 + ($tax_rate / 100))),
		);

		Db::getInstance()->autoExecute(_DB_PREFIX_.'order_invoice', $updateOrderInvoice, 'UPDATE', '`id_order` = '.(int)$id_order);

		$updateOrderTracking = array(
			'shipping_cost_tax_incl' => (float)($order->TotalShipping),
			'shipping_cost_tax_excl' => (float)((float)$order->TotalShipping / (1 + ($tax_rate / 100))),
			'id_carrier' => $carrier->id
		);

		Db::getInstance()->autoExecute(_DB_PREFIX_.'order_carrier', $updateOrderTracking, 'UPDATE', '`id_order` = '.(int)$id_order);
		$updatePayment = array('amount' => (float)$order->TotalAmount);
		Db::getInstance()->autoExecute(_DB_PREFIX_.'order_payment', $updatePayment, 'UPDATE', '`order_reference` = "'.$reference_order.'"');
	}

	private function _validateOrder($cart, $marketplace)
	{
		$payment = new sfpayment();
		$payment->name = 'Yooshop_payment';
		$payment->active = true;

		//we need to flush the cart because of cache problems
		$cart->getPackageList(true);
		$cart->getDeliveryOptionList(null, true);
		$cart->getDeliveryOption(null, false, false);

		$payment->validateOrder((int)$cart->id, 2, (float)Tools::ps_round(Tools::convertPrice($cart->getOrderTotal(), new Currency($cart->id_currency)), 2), Tools::strtolower($marketplace), null, array(), $cart->id_currency, false, $cart->secure_key);
		return $payment;
	}

	/*
	 * Fake cart creation
	 */

	private function _getCart($id_customer, $id_address_billing, $id_address_shipping, $productsNode, $currency, $shipping_method)
	{
		$cart = new Cart();
		$cart->id_customer = $id_customer;
		$cart->id_address_invoice = $id_address_billing;
		$cart->id_address_delivery = $id_address_shipping;
		$cart->id_currency = Currency::getIdByIsoCode((string)$currency == '' ? 'EUR' : (string)$currency);
		$cart->id_lang = Configuration::get('PS_LANG_DEFAULT');
		$cart->recyclable = 0;
		$cart->secure_key = md5(uniqid(rand(), true));

		$actual_configuration = unserialize(Configuration::get('YOOSHOP_SHIPPING_MATCHING'));

		$carrier_to_load = isset($actual_configuration[base64_encode(Tools::safeOutput($shipping_method))]) ?
			(int)$actual_configuration[base64_encode(Tools::safeOutput($shipping_method))] :
			(int)Configuration::get('YOOSHOP_CARRIER');

		$carrier = Carrier::getCarrierByReference($carrier_to_load);

		//manage case PS_CARRIER_DEFAULT is deleted
		$carrier = is_object($carrier) ? $carrier : new Carrier($carrier_to_load);

		$cart->id_carrier = $carrier->id;
		$cart->add();

		foreach ($productsNode->Product as $product)
		{
			$skus = explode('_', $product->SKU);
			$p = new Product((int)($skus[0]), false, Configuration::get('PS_LANG_DEFAULT'), Context::getContext()->shop->id);

			if (!Validate::isLoadedObject($p))
				return false;

			$added = $cart->updateQty((int)($product->Quantity), (int)($skus[0]), ((isset($skus[1])) ? $skus[1] : null));

			if ($added < 0 || $added === false)
				return false;
		}

		$cart->update();
		return $cart;
	}

	private function _checkProducts($productsNode)
	{
		$available = true;

		foreach ($productsNode->Product as $product)
		{
			die($product->Quantity);
			if (strpos($product->SKU, '_') !== false)
			{
				$skus = explode('_', $product->SKU);
				$quantity = StockAvailable::getQuantityAvailableByProduct((int)$skus[0], (int)$skus[1]);

				if ($quantity - $product->Quantity < 0)
					StockAvailable::updateQuantity((int)$skus[0], (int)$skus[1], (int)$product->Quantity);
			}
			else
			{
				$quantity = StockAvailable::getQuantityAvailableByProduct((int)$product->SKU);

				if ($quantity - $product->Quantity < 0)
					StockAvailable::updateQuantity((int)$product->SKU, 0, (int)$product->Quantity);
			}
		}

		return $available;
	}

	private function _validOrders($id_order, $marketplace, $id_order_merchant = false, $error = false)
	{
		
		$json = '[{';
		$json .= '"Order" : " {';
		$json .= '"IdOrder" : "'.$id_order.'",';
		$json .= '"Marketplace" : "'.$marketplace.'"';

		if ($id_order_merchant)
			$json .= ',"MerchantIdOrder" : "'.$id_order_merchant.'"';

		if ($error)
			$json .= ',"ErrorOrder" : "'.$error.'"';

		$json .= '}';
		$json .= '}]';

		$this->_callWebService('ValidOrders', $json);
	}

	private function _setShoppingFeedId()
	{
		$login = Configuration::get('YOOSHOP_LOGIN');
		$id = Configuration::get('YOOSHOP_ID');

		if (empty($login) || !empty($id))
			return;

		$json = '[{';
		$json .= '"Login" : "'.$login.'"';
		$json .= '}]';

		$getClientId = $this->_callWebService('GetClientId', $json);

		if (!is_object($getClientId))
			return;

		Configuration::updateValue('YOOSHOP_ID', (string)$getClientId->Response->ID);
	}

	/* Liste Marketplaces SF */
	private function _getMarketplaces()
	{
		return array(
			'amazon',
			'boulanger',
			'brandalley',
			'cdiscount',
			'commentseruiner',
			'darty',
			'decofinder',
			'docteurdiscount',
			'ebay',
			'elevenmain',
			'etsy',
			'fnac',
			'fnaces',
			'galerieslafayette',
			'glamour',
			'gosport',
			'gstk',
			'holosfind',
			'jardimarket',
			'jardinermalin',
			'laredoute',
			'lecomptoirsante',
			'menlook',
			'mistergooddeal',
			'monechelle',
			'moneden',
			'natureetdecouvertes',
			'pixmania',
			'pixmaniait',
			'placedumariage',
			'priceminister',
			'rakuten',
			'rakutenes',
			'rdc',
			'ricardo',
			'rueducommerce',
			'sears',
			'spartoo',
			'tap',
			'villatech',
			'wizacha',
			'yodetiendas',
		);
	}

	private function _translateField($field)
	{
		$translations = array(
			'FR' => array(
				'product' => 'produit',
				'supplier_link' => 'url-fournisseur',
				'manufacturer_link' => 'url-fabricant',
				'on_sale' => 'solde',
				'name' => 'nom',
				'link' => 'url',
				'short_description' => 'description_courte',
				'price' => 'prix',
				'old_price' => 'prix-barre',
				'shipping_cost' => 'frais-de-port',
				'shipping_delay' => 'delai-livraison',
				'brand' => 'marque',
				'category' => 'rayon',
				'quantity' => 'quantite',
				'weight' => 'poids',
				'ecotax' => 'ecotaxe',
				'vat' => 'tva',
				'mpn' => 'ref-constructeur',
				'supplier_reference' => 'ref-fournisseur',
				'category_breadcrumb' => 'Categorie',
			)
		);

		$iso_code = $this->default_country->iso_code;

		if (isset($translations[$iso_code][$field]))
			return $translations[$iso_code][$field];

		return $field;

	}

	public function lastProduct($id)
	{
		if (Tools::getValue('token') == '' || Tools::getValue('token') != Configuration::get('YOOSHOP_TOKEN'))
			die('[{"error" : "Invalid Token"}]');

		$last = array ('id_product' => $id);
		Db::getInstance()->autoExecute(_DB_PREFIX_.'last_id_prod', $last, 'INSERT');
	}

}

class Yooshop_payment extends PaymentModule
{

}