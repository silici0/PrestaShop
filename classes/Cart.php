<?php
/*
* 2007-2011 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2011 PrestaShop SA
*  @version  Release: $Revision: 7506 $
*  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

class CartCore extends ObjectModel
{
	public $id;

	public $id_group_shop;

	public $id_shop;

	/** @var integer Customer delivery address ID */
	public $id_address_delivery;

	/** @var integer Customer invoicing address ID */
	public $id_address_invoice;

	/** @var integer Customer currency ID */
	public $id_currency;

	/** @var integer Customer ID */
	public $id_customer;

	/** @var integer Guest ID */
	public $id_guest;

	/** @var integer Language ID */
	public $id_lang;

	/** @var integer Carrier ID */
	public $id_carrier;

	/** @var boolean True if the customer wants a recycled package */
	public $recyclable = 1;

	/** @var boolean True if the customer wants a gift wrapping */
	public $gift = 0;

	/** @var string Gift message if specified */
	public $gift_message;

	/** @var string Object creation date */
	public $date_add;

	/** @var string secure_key */
	public $secure_key;

	/** @var string Object last modification date */
	public $date_upd;

	public $checkedTos = false;
	public $pictures;
	public $textFields;
	
	public $delivery_option;
	
	/** @var boolean Allow to seperate order in multiple package in order to recieve as soon as possible the available products */
	public $allow_seperated_package = false;

	protected static $_nbProducts = array();
	protected static $_isVirtualCart = array();

	protected $fieldsRequired = array('id_currency', 'id_lang');
	protected $fieldsValidate = array('id_address_delivery' => 'isUnsignedId', 'id_address_invoice' => 'isUnsignedId',
		'id_currency' => 'isUnsignedId', 'id_customer' => 'isUnsignedId', 'id_guest' => 'isUnsignedId', 'id_lang' => 'isUnsignedId',
		'id_carrier' => 'isUnsignedId', 'recyclable' => 'isBool', 'gift' => 'isBool', 'gift_message' => 'isMessage',
		'allow_seperated_package' => 'isBool');

	protected $_products = null;
	protected static $_totalWeight = array();
	protected $_taxCalculationMethod = PS_TAX_EXC;
	protected static $_carriers = null;
	protected static $_taxes_rate = null;
	protected static $_attributesLists = array();
	protected $table = 'cart';
	protected $identifier = 'id_cart';

	protected $webserviceParameters = array(
		'fields' => array(
		'id_address_delivery' => array('xlink_resource' => 'addresses'),
		'id_address_invoice' => array('xlink_resource' => 'addresses'),
		'id_currency' => array('xlink_resource' => 'currencies'),
		'id_customer' => array('xlink_resource' => 'customers'),
		'id_guest' => array('xlink_resource' => 'guests'),
		'id_lang' => array('xlink_resource' => 'languages'),
		'id_carrier' => array('xlink_resource' => 'carriers'),
		),
		'associations' => array(
			'cart_rows' => array('resource' => 'cart_row', 'virtual_entity' => true, 'fields' => array(
				'id_product' => array('required' => true, 'xlink_resource' => 'products'),
				'id_product_attribute' => array('required' => true, 'xlink_resource' => 'combinations'),
				'quantity' => array('required' => true),
				)
			),
		),
	);

	const ONLY_PRODUCTS = 1;
	const ONLY_DISCOUNTS = 2;
	const BOTH = 3;
	const BOTH_WITHOUT_SHIPPING = 4;
	const ONLY_SHIPPING = 5;
	const ONLY_WRAPPING = 6;
	const ONLY_PRODUCTS_WITHOUT_SHIPPING = 7;

	public function getFields()
	{
		$this->validateFields();

		$fields['id_group_shop'] = (int)$this->id_group_shop;
		$fields['id_shop'] = (int)$this->id_shop;

		$fields['id_address_delivery'] = (int)($this->id_address_delivery);
		$fields['id_address_invoice'] = (int)($this->id_address_invoice);
		$fields['id_currency'] = (int)($this->id_currency);
		$fields['id_customer'] = (int)($this->id_customer);
		$fields['id_guest'] = (int)($this->id_guest);
		$fields['id_lang'] = (int)($this->id_lang);
		$fields['id_carrier'] = (int)($this->id_carrier);
		$fields['recyclable'] = (int)($this->recyclable);
		$fields['gift'] = (int)($this->gift);
		$fields['secure_key'] = pSQL($this->secure_key);
		$fields['gift_message'] = pSQL($this->gift_message);
		$fields['date_add'] = pSQL($this->date_add);
		$fields['date_upd'] = pSQL($this->date_upd);
		$fields['allow_seperated_package'] = (boolean)$this->allow_seperated_package;
		$fields['delivery_option'] = $this->delivery_option;

		return $fields;
	}

	public function __construct($id = NULL, $id_lang = NULL)
	{
		parent::__construct($id, $id_lang);
		if ($this->id_customer)
		{
			$customer = new Customer((int)($this->id_customer));
			$this->_taxCalculationMethod = Group::getPriceDisplayMethod((int)($customer->id_default_group));
			if ((!$this->secure_key OR $this->secure_key == '-1') AND $customer->secure_key)
			{
				$this->secure_key = $customer->secure_key;
				$this->save();
			}
		}
		else
			$this->_taxCalculationMethod = Group::getDefaultPriceDisplayMethod();
	}

	public function add($autodate = true, $nullValues = false)
	{
		if (!$this->id_lang)
			$this->id_lang = Configuration::get('PS_LANG_DEFAULT');
		$return = parent::add($autodate);
		Hook::exec('cart');
		return $return;
	}

	public function update($nullValues = false)
	{
		if (isset(self::$_nbProducts[$this->id]))
			unset(self::$_nbProducts[$this->id]);
		if (isset(self::$_totalWeight[$this->id]))
			unset(self::$_totalWeight[$this->id]);
		$this->_products = NULL;
		$return = parent::update();
		Hook::exec('cart');
		return $return;
	}

	public function delete()
	{
		if ($this->OrderExists()) //NOT delete a cart which is associated with an order
			return false;

		$uploadedFiles = Db::getInstance()->executeS('
		SELECT cd.`value`
		FROM `'._DB_PREFIX_.'customized_data` cd
		INNER JOIN `'._DB_PREFIX_.'customization` c ON (cd.`id_customization`= c.`id_customization`)
		WHERE cd.`type`= 0 AND c.`id_cart`='.(int)$this->id);

		foreach ($uploadedFiles as $mustUnlink)
		{
			unlink(_PS_UPLOAD_DIR_.$mustUnlink['value'].'_small');
			unlink(_PS_UPLOAD_DIR_.$mustUnlink['value']);
		}

		Db::getInstance()->execute('
		DELETE FROM `'._DB_PREFIX_.'customized_data`
		WHERE `id_customization` IN (
			SELECT `id_customization`
			FROM `'._DB_PREFIX_.'customization`
			WHERE `id_cart`='.(int)$this->id.'
		)');

		Db::getInstance()->execute('
		DELETE FROM `'._DB_PREFIX_.'customization`
		WHERE `id_cart` = '.(int)$this->id);

		if (!Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'cart_cart_rule` WHERE `id_cart` = '.(int)($this->id))
		 OR !Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'cart_product` WHERE `id_cart` = '.(int)($this->id)))
			return false;

		return parent::delete();
	}

	public static function getTaxesAverageUsed($id_cart)
	{
		$cart = new Cart((int)($id_cart));
		if (!Validate::isLoadedObject($cart))
			die(Tools::displayError());

		if (!Configuration::get('PS_TAX'))
			return 0;

		$products = $cart->getProducts();
		$totalProducts_moy = 0;
		$ratioTax = 0;

		if (!sizeof($products))
			return 0;

		foreach ($products AS $product)
		{
			$totalProducts_moy += $product['total_wt'];
			$ratioTax += $product['total_wt'] * Tax::getProductTaxRate((int)$product['id_product'], (int)$cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
		}

		if ($totalProducts_moy > 0)
			return $ratioTax / $totalProducts_moy;

		return 0;
	}

	/**
	 * @deprecated 1.5.0.1
	 */
	public function getDiscounts($lite = false, $refresh = false)
	{
		Tools::displayAsDeprecated();
		return $this->getCartRules();
	}

	public function getCartRules()
	{
		// TODO : add cache

		// If the cart has not been saved, then there can't be any cart rule applied
		if (!CartRule::isFeatureActive() || !$this->id)
			return array();

		$total_products_ti = $this->getOrderTotal(true, Cart::ONLY_PRODUCTS);
		$total_products_te = $this->getOrderTotal(false, Cart::ONLY_PRODUCTS);
		$shipping_ti = $this->getOrderShippingCost();
		$shipping_te = $this->getOrderShippingCost(NULL, false);

		$result = Db::getInstance()->executeS('
		SELECT *
		FROM `'._DB_PREFIX_.'cart_cart_rule` cd
		LEFT JOIN `'._DB_PREFIX_.'cart_rule` cr ON cd.`id_cart_rule` = cr.`id_cart_rule`
		LEFT JOIN `'._DB_PREFIX_.'cart_rule_lang` crl ON (cd.`id_cart_rule` = cr.`id_cart_rule` AND crl.id_lang = '.(int)$this->id_lang.')
		WHERE `id_cart` = '.(int)$this->id);

		foreach ($result as &$row)
		{
			$cartRule = new CartRule($row['id_cart_rule'], (int)$this->id_lang);
			$row['value_real'] = $cartRule->getContextualValue(true);
			$row['value_tax_exc'] = $cartRule->getContextualValue(false);

			// Retro compatibility < 1.5.0.2
			$row['id_discount'] = $row['id_cart_rule'];
			$row['description'] = $row['name'];
		}

		return $result;
	}

	// Todo: see uses and change name
	public function getDiscountsCustomer($id_cart_rule)
	{
		if (!CartRule::isFeatureActive())
			return 0;

		return Db::getInstance()->getValue('
			SELECT COUNT(*)
			FROM `'._DB_PREFIX_.'cart_cart_rule`
			WHERE `id_cart_rule` = '.(int)$id_cart_rule.' AND `id_cart` = '.(int)$this->id);
	}

	public function getLastProduct()
	{
		$sql = '
			SELECT `id_product`, `id_product_attribute`, id_shop
			FROM `'._DB_PREFIX_.'cart_product`
			WHERE `id_cart` = '.(int)($this->id).'
			ORDER BY `date_add` DESC';
		$result = Db::getInstance()->getRow($sql);
		if ($result AND isset($result['id_product']) AND $result['id_product'])
			foreach ($this->getProducts() as $product)
			if ($result['id_product'] == $product['id_product'] && (!$result['id_product_attribute'] || $result['id_product_attribute'] == $product['id_product_attribute']))
				return $product;
		return false;
	}

	/**
	 * Return cart products
	 *
	 * @result array Products
	 */
	public function getProducts($refresh = false, $id_product = false, $id_country = null)
	{
		if (!$this->id)
			return array();
		// Product cache must be strictly compared to NULL, or else an empty cart will add dozens of queries
		if ($this->_products !== NULL AND !$refresh)
		{
			// Return product row with specified ID if it exists
			if (is_int($id_product))
			{
				foreach ($this->_products as $product)
					if ($product['id_product'] == $id_product)
						return array($product);
				return array();
			}
			return $this->_products;
		}
		if (!$id_country)
			$id_country = Context::getContext()->country->id;

		// Build query
		$sql = new DbQuery();

		// Build SELECT
		$sql->select('cp.`id_product_attribute`, cp.`id_product`, cp.`quantity` AS cart_quantity, cp.id_shop, pl.`name`, p.`is_virtual`,
						pl.`description_short`, pl.`available_now`, pl.`available_later`, p.`id_product`, p.`id_category_default`, p.`id_supplier`, p.`id_manufacturer`,
						p.`on_sale`, p.`ecotax`, p.`additional_shipping_cost`, p.`available_for_order`, p.`price`, p.`weight`, p.`width`, p.`height`, p.`depth`, sa.`out_of_stock`,
						p.`active`, p.`date_add`, p.`date_upd`, t.`id_tax`, tl.`name` AS tax, t.`rate`, stock.quantity, pl.`link_rewrite`, cl.`link_rewrite` AS category,
						CONCAT(cp.`id_product`, cp.`id_product_attribute`, cp.`id_address_delivery`) AS unique_id, cp.id_address_delivery');

		// Build FROM
		$sql->from('cart_product cp');

		// Build JOIN
		$sql->leftJoin('product p ON p.`id_product` = cp.`id_product`');
		$sql->leftJoin('product_lang pl ON p.`id_product` = pl.`id_product` AND pl.`id_lang` = '.(int)$this->id_lang.Context::getContext()->shop->addSqlRestrictionOnLang('pl'));
		$sql->leftJoin('tax_rule tr ON p.`id_tax_rules_group` = tr.`id_tax_rules_group`
										AND tr.`id_country` = '.(int)$id_country.'
										AND tr.`id_state` = 0
										AND tr.`zipcode_from` = 0');
		$sql->leftJoin('tax t ON t.`id_tax` = tr.`id_tax`');
		$sql->leftJoin('stock_available sa ON sa.`id_product` = p.`id_product` AND sa.id_product_attribute = 0');
		$sql->leftJoin('tax_lang tl ON t.`id_tax` = tl.`id_tax` AND tl.`id_lang` = '.(int)$this->id_lang);
		$sql->leftJoin('category_lang cl ON p.`id_category_default` = cl.`id_category` AND cl.`id_lang` = '.(int)$this->id_lang.Context::getContext()->shop->addSqlRestrictionOnLang('cl'));

		// @todo test if everything is ok, then refactorise call of this method
		Product::sqlStock('cp', 'cp', false, null, $sql);

		// Build WHERE clauses
		$sql->where('cp.`id_cart` = '.(int)$this->id);
		if ($id_product)
			$sql->where('cp.`id_product` = '.(int)$id_product);
		$sql->where('p.`id_product` IS NOT NULL');

		// Build GROUP BY
		$sql->groupBy('unique_id');

		// Build ORDER BY
		$sql->orderBy('p.id_product, cp.id_product_attribute, cp.date_add ASC');

		if (Customization::isFeatureActive())
		{
			$sql->select('cu.`id_customization`, cu.`quantity` AS customization_quantity');
			$sql->leftJoin('customization cu ON p.`id_product` = cu.`id_product`');
		}

		if (Combination::isFeatureActive())
		{
			$sql->select('pa.`price` AS price_attribute, pa.`ecotax` AS ecotax_attr,
							IF (IFNULL(pa.`reference`, \'\') = \'\', p.`reference`, pa.`reference`) AS reference,
							IF (IFNULL(pa.`supplier_reference`, \'\') = \'\', p.`supplier_reference`, pa.`supplier_reference`) AS supplier_reference,
							(p.`weight`+ pa.`weight`) weight_attribute,
							IF (IFNULL(pa.`ean13`, \'\') = \'\', p.`ean13`, pa.`ean13`) AS ean13, IF (IFNULL(pa.`upc`, \'\') = \'\', p.`upc`, pa.`upc`) AS upc,
							pai.`id_image` as pai_id_image, il.`legend` as pai_legend, IFNULL(pa.`minimal_quantity`, p.`minimal_quantity`) as minimal_quantity, pa.`ecotax` AS ecotax_attr');

			$sql->leftJoin('product_attribute pa ON pa.`id_product_attribute` = cp.`id_product_attribute`');
			$sql->leftJoin('product_attribute_image pai ON pai.`id_product_attribute` = pa.`id_product_attribute`');
			$sql->leftJoin('image_lang il ON il.id_image = pai.id_image AND il.id_lang = '.(int)$this->id_lang);
		}
		else
			$sql->select('p.`reference` AS reference, p.`supplier_reference` AS supplier_reference, p.`ean13`, p.`upc` AS upc, p.`minimal_quantity` AS minimal_quantity');


		$result = Db::getInstance()->executeS($sql);

		// Reset the cache before the following return, or else an empty cart will add dozens of queries
		$productsIds = array();
		$paIds = array();
		foreach ($result as $row)
		{
			$productsIds[] = $row['id_product'];
			$paIds[] = $row['id_product_attribute'];
		}
		// Thus you can avoid one query per product, because there will be only one query for all the products of the cart
		Product::cacheProductsFeatures($productsIds);
		self::cacheSomeAttributesLists($paIds, $this->id_lang);

		$this->_products = array();
		if (empty($result))
			return array();
		foreach ($result AS $row)
		{
			if (isset($row['ecotax_attr']) && $row['ecotax_attr'] > 0)
				$row['ecotax'] = (float)($row['ecotax_attr']);
			$row['stock_quantity'] = (int)($row['quantity']);
			// for compatibility with 1.2 themes
			$row['quantity'] = (int)($row['cart_quantity']);
			if (isset($row['id_product_attribute']) && (int)$row['id_product_attribute'] && isset($row['weight_attribute']))
				$row['weight'] = $row['weight_attribute'];
			if ($this->_taxCalculationMethod == PS_TAX_EXC)
			{
				$row['price'] = Product::getPriceStatic((int)$row['id_product'], false, isset($row['id_product_attribute']) ? (int)($row['id_product_attribute']) : NULL, 2, NULL, false, true, (int)($row['cart_quantity']), false, ((int)($this->id_customer) ? (int)($this->id_customer) : NULL), (int)($this->id), ((int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) ? (int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) : NULL), $specificPriceOutput); // Here taxes are computed only once the quantity has been applied to the product price
				$row['price_wt'] = Product::getPriceStatic((int)$row['id_product'], true, isset($row['id_product_attribute']) ? (int)($row['id_product_attribute']) : NULL, 2, NULL, false, true, (int)($row['cart_quantity']), false, ((int)($this->id_customer) ? (int)($this->id_customer) : NULL), (int)($this->id), ((int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) ? (int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) : NULL));
				$tax_rate = Tax::getProductTaxRate((int)$row['id_product'], (int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

				$row['total_wt'] = Tools::ps_round($row['price'] * (float)$row['cart_quantity'] * (1 + (float)($tax_rate) / 100), 2);
				$row['total'] = $row['price'] * (int)($row['cart_quantity']);
			}
			else
			{
				$row['price'] = Product::getPriceStatic((int)$row['id_product'], false, (int)$row['id_product_attribute'], 6, NULL, false, true, $row['cart_quantity'], false, ((int)($this->id_customer) ? (int)($this->id_customer) : NULL), (int)($this->id), ((int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) ? (int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) : NULL), $specificPriceOutput);
				$row['price_wt'] = Product::getPriceStatic((int)$row['id_product'], true, (int)$row['id_product_attribute'], 2, NULL, false, true, $row['cart_quantity'], false, ((int)($this->id_customer) ? (int)($this->id_customer) : NULL), (int)($this->id), ((int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) ? (int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) : NULL));

				// In case when you use QuantityDiscount, getPriceStatic() can be return more of 2 decimals
				$row['price_wt'] = Tools::ps_round($row['price_wt'], 2);
				$row['total_wt'] = $row['price_wt'] * (int)($row['cart_quantity']);
				$row['total'] = Tools::ps_round($row['price'] * (int)($row['cart_quantity']), 2);
			}

			if (!isset($row['pai_id_image']) OR $row['pai_id_image'] == 0)
			{
				$row2 = Db::getInstance()->getRow('
				SELECT i.`id_image`, il.`legend`
				FROM `'._DB_PREFIX_.'image` i
				LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (i.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$this->id_lang.')
					WHERE i.`id_product` = '.(int)$row['id_product'].' AND i.`cover` = 1');
				if (!$row2)
					$row2 = array('id_image' => false, 'legend' => false);
					else
				$row = array_merge($row, $row2);
			}
			else
			{
				$row['id_image'] = $row['pai_id_image'];
				$row['legend'] = $row['pai_legend'];
			}

			$row['reduction_applies'] = ($specificPriceOutput AND (float)$specificPriceOutput['reduction']);
			$row['quantity_discount_applies'] = ($specificPriceOutput AND $row['cart_quantity'] >= (int)$specificPriceOutput['from_quantity']);
			$row['id_image'] = Product::defineProductImage($row,$this->id_lang);
			$row['allow_oosp'] = Product::isAvailableWhenOutOfStock($row['out_of_stock']);
			$row['features'] = Product::getFeaturesStatic((int)$row['id_product']);
			if (array_key_exists($row['id_product_attribute'].'-'.$this->id_lang, self::$_attributesLists))
				$row = array_merge($row, self::$_attributesLists[$row['id_product_attribute'].'-'.$this->id_lang]);

			$this->_products[] = $row;
		}

		return $this->_products;
	}

	public static function cacheSomeAttributesLists($ipaList, $id_lang)
	{
		if (!Combination::isFeatureActive())
			return;
		$paImplode = array();
		foreach ($ipaList as $id_product_attribute)
			if ((int)$id_product_attribute AND !array_key_exists($id_product_attribute.'-'.$id_lang, self::$_attributesLists))
			{
				$paImplode[] = (int)$id_product_attribute;
				self::$_attributesLists[(int)$id_product_attribute.'-'.$id_lang] = array('attributes' => '', 'attributes_small' => '');
			}
		if (!count($paImplode))
			return;

		$result = Db::getInstance()->executeS('
		SELECT pac.`id_product_attribute`, agl.`public_name` AS public_group_name, al.`name` AS attribute_name
		FROM `'._DB_PREFIX_.'product_attribute_combination` pac
		LEFT JOIN `'._DB_PREFIX_.'attribute` a ON a.`id_attribute` = pac.`id_attribute`
		LEFT JOIN `'._DB_PREFIX_.'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
		LEFT JOIN `'._DB_PREFIX_.'attribute_lang` al ON (a.`id_attribute` = al.`id_attribute` AND al.`id_lang` = '.(int)$id_lang.')
		LEFT JOIN `'._DB_PREFIX_.'attribute_group_lang` agl ON (ag.`id_attribute_group` = agl.`id_attribute_group` AND agl.`id_lang` = '.(int)$id_lang.')
		WHERE pac.`id_product_attribute` IN ('.implode($paImplode, ',').')
		ORDER BY agl.`public_name` ASC');

		foreach ($result as $row)
		{
			self::$_attributesLists[$row['id_product_attribute'].'-'.$id_lang]['attributes'] .= $row['public_group_name'].' : '.$row['attribute_name'].', ';
			self::$_attributesLists[$row['id_product_attribute'].'-'.$id_lang]['attributes_small'] .= $row['attribute_name'].', ';
		}

		foreach ($paImplode as $id_product_attribute)
		{
			self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes'] = rtrim(self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes'], ', ');
			self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes_small'] = rtrim(self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes_small'], ', ');
		}
	}

	/**
	 * Return cart products quantity
	 *
	 * @result integer Products quantity
	 */
	public	function nbProducts()
	{
		if (!$this->id)
			return 0;
		return self::getNbProducts($this->id);
	}

	public static function getNbProducts($id)
	{
		// Must be strictly compared to NULL, or else an empty cart will bypass the cache and add dozens of queries
		if (isset(self::$_nbProducts[$id]) && self::$_nbProducts[$id] !== NULL)
			return self::$_nbProducts[$id];
		self::$_nbProducts[$id] = (int)(Db::getInstance()->getValue('
			SELECT SUM(`quantity`)
			FROM `'._DB_PREFIX_.'cart_product`
			WHERE `id_cart` = '.(int)($id)));
		return self::$_nbProducts[$id];
	}

	/**
	 * @deprecated 1.5.0.1
	 */
	public function addDiscount($id_cart_rule)
	{
		Tools::displayAsDeprecated();
		return $this->addCartRule($id_cart_rule);
	}

	public function addCartRule($id_cart_rule)
	{
		return Db::getInstance()->AutoExecute(_DB_PREFIX_.'cart_cart_rule', array('id_cart_rule' => (int)$id_cart_rule, 'id_cart' => (int)$this->id), 'INSERT');
	}

	public function containsProduct($id_product, $id_product_attribute = 0, $id_customization = false, $id_address_delivery = 0)
	{
		return Db::getInstance()->getRow('
		SELECT cp.`quantity`
		FROM `'._DB_PREFIX_.'cart_product` cp
		'.($id_customization ? 'LEFT JOIN `'._DB_PREFIX_.'customization` c ON (c.`id_product` = cp.`id_product` AND c.`id_product_attribute` = cp.`id_product_attribute`)' : '').'
		WHERE cp.`id_product` = '.(int)$id_product.' AND cp.`id_product_attribute` = '.(int)$id_product_attribute.' AND cp.`id_cart` = '.(int)$this->id.
		($id_customization ? ' AND c.`id_customization` = '.(int)$id_customization : '').' AND cp.`id_address_delivery` = '.(int)$id_address_delivery);
	}

	/**
	 * Update product quantity
	 *
	 * @param integer $quantity Quantity to add (or substract)
	 * @param integer $id_product Product ID
	 * @param integer $id_product_attribute Attribute ID if needed
	 * @param string $operator Indicate if quantity must be increased or decreased
	 */
	public function updateQty($quantity, $id_product, $id_product_attribute = null, $id_customization = false, $id_address_delivery = 0, $operator = 'up', Shop $shop = null)
	{
		if (!$shop)
			$shop = Context::getContext()->shop;
		$quantity = (int)$quantity;
		$id_product = (int)$id_product;
		$id_product_attribute = (int)$id_product_attribute;
		$product = new Product($id_product, false, Configuration::get('PS_LANG_DEFAULT'), $shop->getID());

		/* If we have a product combination, the minimal quantity is set with the one of this combination */
		if (!empty($id_product_attribute))
			$minimalQuantity = (int)Attribute::getAttributeMinimalQty($id_product_attribute);
		else
			$minimalQuantity = (int)$product->minimal_quantity;

		if (!Validate::isLoadedObject($product))
			die(Tools::displayError());
		if (isset(self::$_nbProducts[$this->id]))
			unset(self::$_nbProducts[$this->id]);
		if (isset(self::$_totalWeight[$this->id]))
			unset(self::$_totalWeight[$this->id]);
		if ((int)$quantity <= 0)
			return $this->deleteProduct($id_product, $id_product_attribute, (int)$id_customization);
		elseif (!$product->available_for_order OR Configuration::get('PS_CATALOG_MODE'))
			return false;
		else
		{
			/* Check if the product is already in the cart */
			$result = $this->containsProduct($id_product, $id_product_attribute, (int)$id_customization, (int)$id_address_delivery);

			/* Update quantity if product already exist */
			if ($result)
			{
				if ($operator == 'up')
				{
					$sql = 'SELECT stock.out_of_stock, stock.quantity
							FROM '._DB_PREFIX_.'product p
							'.Product::sqlStock('p', $id_product_attribute, true, $shop).'
							WHERE p.id_product = '.$id_product;
					$result2 = Db::getInstance()->getRow($sql);
					$productQty = (int)$result2['quantity'];
					$newQty = (int)$result['quantity'] + (int)$quantity;
					$qty = '+ '.(int)$quantity;

					if (!Product::isAvailableWhenOutOfStock((int)$result2['out_of_stock']))
						if ($newQty > $productQty)
							return false;
				}
				elseif ($operator == 'down')
				{
					$qty = '- '.(int)$quantity;
					$newQty = (int)$result['quantity'] - (int)$quantity;
					if ($newQty < $minimalQuantity AND $minimalQuantity > 1)
						return -1;
				}
				else
					return false;

				/* Delete product from cart */
				if ($newQty <= 0)
					return $this->deleteProduct((int)$id_product, (int)$id_product_attribute, (int)$id_customization);
				elseif ($newQty < $minimalQuantity)
					return -1;
				else
					Db::getInstance()->execute('
					UPDATE `'._DB_PREFIX_.'cart_product`
					SET `quantity` = `quantity` '.$qty.', `date_add` = NOW()
					WHERE `id_product` = '.(int)$id_product.
					(!empty($id_product_attribute) ? ' AND `id_product_attribute` = '.(int)$id_product_attribute : '').'
					AND `id_cart` = '.(int)$this->id.' AND `id_address_delivery` = '.(int)$id_address_delivery.'
					LIMIT 1');
			}

			/* Add product to the cart */
			else
			{
				$sql = 'SELECT stock.out_of_stock, stock.quantity
						FROM '._DB_PREFIX_.'product p
						'.Product::sqlStock('p', $id_product_attribute, true, $shop).'
						WHERE p.id_product = '.$id_product;
				$result2 = Db::getInstance()->getRow($sql);
				if (!Product::isAvailableWhenOutOfStock((int)$result2['out_of_stock']))
					if ((int)$quantity > $result2['quantity'])
						return false;

				if ((int)$quantity < $minimalQuantity)
					return -1;

				$resultAdd = Db::getInstance()->AutoExecute(_DB_PREFIX_.'cart_product', array(
					'id_product' => 			(int)$id_product,
					'id_product_attribute' => 	(int)$id_product_attribute,
					'id_cart' => 				(int)$this->id,
					'id_address_delivery' => 	(int)$id_address_delivery,
					'id_shop' => 				$shop->getID(true),
					'quantity' => 				(int)$quantity,
					'date_add' => 				date('Y-m-d H:i:s')
				), 'INSERT');
				if (!$resultAdd)
					return false;
			}
		}
		// refresh cache of self::_products
		$this->_products = $this->getProducts(true);
		$this->update(true);
		$context = Context::getContext()->cloneContext();
		$context->cart = $this;
		CartRule::autoAddToCart($context);
		
		if ($product->customizable)
			return $this->_updateCustomizationQuantity((int)$quantity, (int)$id_customization, (int)$id_product, (int)$id_product_attribute, $operator);
		else
			return true;
	}

	/*
	** Customization management
	*/
	protected function _updateCustomizationQuantity($quantity, $id_customization, $id_product, $id_product_attribute, $operator = 'up')
	{
		// Link customization to product combination when it is first added to cart
		if (empty($id_customization))
		{
			$customization = $this->getProductCustomization($id_product, null, true);
			foreach ($customization as $field)
			{
				if ($field['quantity'] == 0)
				{
					Db::getInstance()->execute('
					UPDATE `'._DB_PREFIX_.'customization`
					SET `quantity` = '.(int)($quantity).',
						`id_product_attribute` = '.(int)$id_product_attribute.',
						`in_cart` = 1
					WHERE `id_customization` = '.(int)$field['id_customization']);
				}
			}
		}

		/* Deletion */
		if (!empty($id_customization) AND (int)($quantity) < 1)
			return $this->_deleteCustomization((int)$id_customization, (int)$id_product, (int)$id_product_attribute);
		/* Quantity update */
		if (!empty($id_customization))
		{
			$result = Db::getInstance()->getRow('SELECT `quantity` FROM `'._DB_PREFIX_.'customization` WHERE `id_customization` = '.(int)$id_customization);
			if ($result AND Db::getInstance()->NumRows())
			{
				if ($operator == 'down' AND (int)($result['quantity']) - (int)($quantity) < 1)
					return Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'customization` WHERE `id_customization` = '.(int)$id_customization);
				return Db::getInstance()->execute('
					UPDATE `'._DB_PREFIX_.'customization`
					SET `quantity` = `quantity` '.($operator == 'up' ? '+ ' : '- ').(int)($quantity).'
					WHERE `id_customization` = '.(int)($id_customization));
			}
		}
		// refresh cache of self::_products
		$this->_products = $this->getProducts(true);
		$this->update(true);
		return true;
	}

	/**
	 * Add customization item to database
	 *
	 * @param int $id_product
	 * @param int $id_product_attribute
	 * @param int $index
	 * @param int $type
	 * @param string $field
	 * @param int $quantity
	 * @return boolean success
	 */
	public function _addCustomization($id_product, $id_product_attribute, $index, $type, $field, $quantity)
	{
		$exising_customization = Db::getInstance()->executeS('
			SELECT cu.`id_customization`, cd.`index`, cd.`value`, cd.`type` FROM `'._DB_PREFIX_.'customization` cu
			LEFT JOIN `'._DB_PREFIX_.'customized_data` cd
			ON cu.`id_customization` = cd.`id_customization`
			WHERE cu.id_cart = '.(int)$this->id.'
			AND cu.id_product = '.(int)$id_product.'
			AND in_cart = 0');

		if ($exising_customization)
		{
			// If the customization field is alreay filled, delete it
			foreach($exising_customization as $customization)
			{
				if ($customization['type'] == $type && $customization['index'] == $index)
				{
					Db::getInstance()->execute('
						DELETE FROM `'._DB_PREFIX_.'customized_data`
						WHERE id_customization = '.(int)$customization['id_customization'].'
						AND type = '.(int)$customization['type'].'
						AND `index` = '.(int)$customization['index']);
					if ($type == Product::CUSTOMIZE_FILE)
					{
						@unlink(_PS_UPLOAD_DIR_.$customization['value']);
						@unlink(_PS_UPLOAD_DIR_.$customization['value'].'_small');
					}
					break;
				}
			}
			$id_customization = $exising_customization[0]['id_customization'];
		}
		else
		{
			Db::getInstance()->execute('INSERT INTO `'._DB_PREFIX_.'customization` (`id_cart`, `id_product`, `id_product_attribute`, `quantity`) VALUES ('.(int)($this->id).', '.(int)($id_product).', '.(int)($id_product_attribute).', '.(int)($quantity).')');
			$id_customization = Db::getInstance()->Insert_ID();
		}

		$query = 'INSERT INTO `'._DB_PREFIX_.'customized_data` (`id_customization`, `type`, `index`, `value`) VALUES ('.(int)$id_customization.', '.(int)$type.', '.(int)$index.', \''.pSql($field).'\')';

		if (!Db::getInstance()->execute($query))
			return false;
		return true;
	}

	/**
	 * Check if order has already been placed
	 *
	 * @return boolean result
	 */
	public function orderExists()
	{
		return (bool)Db::getInstance()->getValue('SELECT count(*) FROM `'._DB_PREFIX_.'orders` WHERE `id_cart` = '.(int)$this->id);
	}

	/**
	 * @deprecated 1.5.0.1
	 */
	public function deleteDiscount($id_cart_rule)
	{
		Tools::displayAsDeprecated();
		return $this->removeCartRule($id_cart_rule);
	}

	public function removeCartRule($id_cart_rule)
	{
		return Db::getInstance()->Execute('DELETE FROM `'._DB_PREFIX_.'cart_cart_rule` WHERE `id_cart_rule` = '.(int)$id_cart_rule.' AND `id_cart` = '.(int)$this->id.' LIMIT 1');
	}

	/**
	 * Delete a product from the cart
	 *
	 * @param integer $id_product Product ID
	 * @param integer $id_product_attribute Attribute ID if needed
	 * @param integer $id_customization Customization id
	 * @return boolean result
	 */
	public	function deleteProduct($id_product, $id_product_attribute = NULL, $id_customization = NULL, $id_address_delivery = 0)
	{
		if (isset(self::$_nbProducts[$this->id]))
			unset(self::$_nbProducts[$this->id]);
		if (isset(self::$_totalWeight[$this->id]))
			unset(self::$_totalWeight[$this->id]);
		if ((int)$id_customization)
		{
			$productTotalQuantity = (int)Db::getInstance()->getValue('SELECT `quantity`
				FROM `'._DB_PREFIX_.'cart_product`
				WHERE `id_product` = '.(int)$id_product.' AND `id_product_attribute` = '.(int)$id_product_attribute);
			$customizationQuantity = (int)Db::getInstance()->getValue('SELECT `quantity`
				FROM `'._DB_PREFIX_.'customization`
				WHERE `id_cart` = '.(int)$this->id.'
					AND `id_product` = '.(int)$id_product.'
					AND `id_product_attribute` = '.(int)$id_product_attribute.'
					AND `id_address_delivery` = '.(int)$id_address_delivery);
			if (!$this->_deleteCustomization((int)$id_customization, (int)$id_product, (int)$id_product_attribute))
				return false;
			// refresh cache of self::_products
			$this->_products = $this->getProducts(true);
			return ($customizationQuantity == $productTotalQuantity && $this->deleteProduct((int)$id_product, $id_product_attribute, null));
		}

		/* Get customization quantity */
		if (($result = Db::getInstance()->getRow('
			SELECT SUM(`quantity`) AS \'quantity\'
			FROM `'._DB_PREFIX_.'customization`
			WHERE `id_cart` = '.(int)$this->id.'
			AND `id_product` = '.(int)$id_product.'
			AND `id_product_attribute` = '.(int)$id_product_attribute)
		) === false)
			return false;

		/* If the product still possesses customization it does not have to be deleted */
		if (Db::getInstance()->NumRows() AND (int)($result['quantity']))
			return Db::getInstance()->execute('
				UPDATE `'._DB_PREFIX_.'cart_product`
				SET `quantity` = '.(int)($result['quantity']).'
				WHERE `id_cart` = '.(int)($this->id).'
				AND `id_product` = '.(int)($id_product).
				($id_product_attribute != NULL ? ' AND `id_product_attribute` = '.(int)($id_product_attribute) : ''));

		/* Product deletion */
		if (Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'cart_product` WHERE `id_product` = '.
		(int)($id_product).(!is_null($id_product_attribute) ? ' AND `id_product_attribute` = '.(int)($id_product_attribute) : '').
		' AND `id_cart` = '.(int)($this->id).' AND `id_address_delivery` = '.(int)$id_address_delivery))
		{
			// refresh cache of self::_products
			$this->_products = $this->getProducts(true);
			/* Update cart */
			return $this->update(true);
		}
		return false;
	}

	/**
	 * Delete a customization from the cart. If customization is a Picture,
	 * then the image is also deleted
	 *
	 * @param integer $id_customization
	 * @return boolean result
	 */
	protected	function _deleteCustomization($id_customization, $id_product, $id_product_attribute, $id_address_delivery = 0)
	{
		$result = true;
		$customization = Db::getInstance()->getRow('SELECT *
			FROM `'._DB_PREFIX_.'customization`
			WHERE `id_customization` = '.(int)($id_customization));

		if ($customization)
		{
			$custData = Db::getInstance()->getRow('SELECT *
				FROM `'._DB_PREFIX_.'customized_data`
				WHERE `id_customization` = '.(int)($id_customization));

			// Delete customization picture if necessary
			if (isset($custData['type']) and $custData['type'] == 0)
				$result &= (@unlink(_PS_UPLOAD_DIR_.$custData['value']) && @unlink(_PS_UPLOAD_DIR_.$custData['value'].'_small'));

			$result &= Db::getInstance()->execute('DELETE
				FROM `'._DB_PREFIX_.'customized_data`
				WHERE `id_customization` = '.(int)($id_customization));

			if($result)
				$result &= Db::getInstance()->execute('UPDATE `'._DB_PREFIX_.'cart_product`
					SET `quantity` = `quantity` - '.(int)($customization['quantity']).'
					WHERE `id_cart` = '.(int)($this->id).'
					AND `id_product` = '.(int)($id_product).((int)($id_product_attribute) ? '
					AND `id_product_attribute` = '.(int)($id_product_attribute) : '').'
					AND `id_address_delivery` = '.(int)$id_address_delivery);

			if (!$result)
				return false;

			return Db::getInstance()->execute('DELETE
				FROM `'._DB_PREFIX_.'customization`
				WHERE `id_customization` = '.(int)($id_customization));
		}

		return true;
	}

	public static function getTotalCart($id_cart, $use_tax_display = false)
	{
		$cart = new Cart($id_cart);
		if (!Validate::isLoadedObject($cart))
			die(Tools::displayError());
		$with_taxes = $use_tax_display ? $cart->_taxCalculationMethod != PS_TAX_EXC : true;
		return Tools::displayPrice($cart->getOrderTotal($with_taxes), Currency::getCurrencyInstance((int)($cart->id_currency)), false);
	}


	public static function getOrderTotalUsingTaxCalculationMethod($id_cart)
	{
		return Cart::getTotalCart($id_cart, true);
	}

	/**
	* This function returns the total cart amount
	*
	* Possible values for $type:
	* Cart::ONLY_PRODUCTS
	* Cart::ONLY_DISCOUNTS
	* Cart::BOTH
	* Cart::BOTH_WITHOUT_SHIPPING
	* Cart::ONLY_SHIPPING
	* Cart::ONLY_WRAPPING
	* Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING
	*
	* @param boolean $withTaxes With or without taxes
	* @param integer $type Total type
	* @return float Order total
	*/
	public function getOrderTotal($withTaxes = true, $type = Cart::BOTH, $products = null, $id_carrier = null)
	{
		if (!$this->id)
			return 0;
		$type = (int)$type;
		if (!in_array($type, array(Cart::ONLY_PRODUCTS, Cart::ONLY_DISCOUNTS, Cart::BOTH, Cart::BOTH_WITHOUT_SHIPPING, Cart::ONLY_SHIPPING, Cart::ONLY_WRAPPING, Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING)))
			die(Tools::displayError());

		// if cart rules are not used
		if ($type == Cart::ONLY_DISCOUNTS && !CartRule::isFeatureActive())
			return 0;
		// no shipping cost if is a cart with only virtuals products
		$virtual = $this->isVirtualCart();
		if ($virtual AND $type ==  Cart::ONLY_SHIPPING)
			return 0;
		if ($virtual AND $type == Cart::BOTH)
			$type = Cart::BOTH_WITHOUT_SHIPPING;
		
		if ($type != Cart::BOTH_WITHOUT_SHIPPING AND $type != Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING)
		{
			if (is_null($products) && is_null($id_carrier))
				$shipping_fees = $this->getTotalShippingCost(null, (boolean)$withTaxes);
			else
				$shipping_fees = $this->getPackageShippingCost($id_carrier, (int)($withTaxes), null, $products);
		}
		else
			$shipping_fees = 0;
			
		if ($type == Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING)
			$type = Cart::ONLY_PRODUCTS;

		if (is_null($products))
			$products = $this->getProducts();
		
		$order_total = 0;
		if (Tax::excludeTaxeOption())
			$withTaxes = false;
		foreach ($products AS $product)
		{
			if ($this->_taxCalculationMethod == PS_TAX_EXC)
			{
				// Here taxes are computed only once the quantity has been applied to the product price
				$price = Product::getPriceStatic((int)$product['id_product'], false, (int)$product['id_product_attribute'], 2, NULL, false, true, $product['cart_quantity'], false, (int)$this->id_customer ? (int)$this->id_customer : NULL, (int)$this->id, ($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

				$total_ecotax = $product['ecotax'] * (int)$product['cart_quantity'];
				$total_price = $price * (int)$product['cart_quantity'];

				if ($withTaxes)
				{
					$total_price = ($total_price - $total_ecotax) * (1 + (float)(Tax::getProductTaxRate((int)$product['id_product'], (int)$this->{Configuration::get('PS_TAX_ADDRESS_TYPE')})) / 100);
					$total_ecotax = $total_ecotax * (1 + Tax::getProductEcotaxRate((int)$this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) / 100);
					$total_price = Tools::ps_round($total_price + $total_ecotax, 2);
				}
			}
			else
			{
				$price = Product::getPriceStatic((int)($product['id_product']), true, (int)($product['id_product_attribute']), 2, NULL, false, true, $product['cart_quantity'], false, ((int)($this->id_customer) ? (int)($this->id_customer) : NULL), (int)($this->id), ((int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) ? (int)($this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}) : NULL));
				$total_price = Tools::ps_round($price, 2) * (int)($product['cart_quantity']);
				if (!$withTaxes)
					$total_price = Tools::ps_round($total_price / (1 + ((float)(Tax::getProductTaxRate((int)$product['id_product'], (int)$this->{Configuration::get('PS_TAX_ADDRESS_TYPE')})) / 100)), 2);
			}
			$order_total += $total_price;
		}
		$order_total_products = $order_total;
		// Todo: consider optimizations
		if ($type == Cart::ONLY_DISCOUNTS)
			$order_total = 0;
		// Wrapping Fees
		$wrapping_fees = 0;
		if ($this->gift)
		{
			$wrapping_fees = (float)Configuration::get('PS_GIFT_WRAPPING_PRICE');
			if ($withTaxes)
			{
				$wrapping_fees_tax = new Tax(Configuration::get('PS_GIFT_WRAPPING_TAX'));
				$wrapping_fees *= 1 + ((float)$wrapping_fees_tax->rate / 100);
			}
			$wrapping_fees = Tools::convertPrice(Tools::ps_round($wrapping_fees, 2), Currency::getCurrencyInstance((int)($this->id_currency)));
		}

		$order_total_discount = 0;
		if ($type != Cart::ONLY_PRODUCTS && CartRule::isFeatureActive())
		{
			$result = $this->getCartRules();
			foreach (ObjectModel::hydrateCollection('CartRule', $result, Configuration::get('PS_LANG_DEFAULT')) AS $cartRule)
				$order_total_discount += Tools::ps_round($cartRule->getContextualValue($withTaxes));
			$order_total_discount = min(Tools::ps_round($order_total_discount), $wrapping_fees + $order_total_products + $shipping_fees);
			$order_total -= $order_total_discount;
		}

		if ($type == Cart::ONLY_SHIPPING)
			return $shipping_fees;
		if ($type == Cart::ONLY_WRAPPING)
			return $wrapping_fees;
		if ($type == Cart::BOTH)
			$order_total += $shipping_fees + $wrapping_fees;
		if ($order_total < 0 AND $type != Cart::ONLY_DISCOUNTS)
			return 0;
		if ($type == Cart::ONLY_DISCOUNTS)
			return $order_total_discount;
		return Tools::ps_round((float)$order_total, 2);
	}
	
	/**
	 * Get products grouped by package and by addresses to be sent individualy (one package = one shipping cost).
	 * 
	 * @return array array(
	 *                   0 => array( // First address
	 *                       0 => array(  // First package
	 *                           'product_list' => array(...),
	 *                           'carrier_list' => array(...),
	 *                           'id_warehouse' => array(...),
	 *                       ),
	 *                   ),
	 *               );
	 * @todo Add avaibility check
	 */
	public function getPackageList()
	{
		$product_list = $this->getProducts();
		// Step 1 : Get product informations (warehouse_list and carrier_list), count warehouse
		// Determine the best warehouse to determine the packages
		// For that we count the number of time we can use a warehouse for a specific delivery address
		$warehouse_count_by_address = array();
		$warehouse_carrier_list = array();
		foreach ($product_list as &$product)
		{
			if (!isset($warehouse_count_by_address[$product['id_address_delivery']]))
				$warehouse_count_by_address[$product['id_address_delivery']] = array();
			
			$product['warehouse_list'] = array();
			
			$warehouse_list = Warehouse::getProductWarehouseList($product['id_product'], $product['id_product_attribute']);
			// Does the product is in stock ?
			// If yes, get only warehouse where the product is in stock
			$warehouse_in_stock = array();
			$manager = StockManagerFactory::getManager();
			
			foreach ($warehouse_list as $key => $warehouse)
			{
				if ($manager->getProductRealQuantities(
					$product['id_product'],
					$product['id_product_attribute'],
					array($warehouse['id_warehouse']),
					true) > 0
				)
					$warehouse_in_stock[] = $warehouse;
			}
			if (!empty($warehouse_in_stock))
			{
				$warehouse = $warehouse_in_stock;
				$product['in_stock'] = true;
			}
			else
				$product['in_stock'] = false;
			
			foreach ($warehouse_list as $warehouse)
			{
				if (!isset($warehouse_carrier_list[$warehouse['id_warehouse']]))
				{
					$warehouse_object = new Warehouse($warehouse['id_warehouse']);
					$warehouse_carrier_list[$warehouse['id_warehouse']] = $warehouse_object->getCarriers();
				}
				
				$product['warehouse_list'][] = $warehouse['id_warehouse'];
				if (!isset($warehouse_count_by_address[$product['id_address_delivery']][$warehouse['id_warehouse']]))
					$warehouse_count_by_address[$product['id_address_delivery']][$warehouse['id_warehouse']] = 0;
				$warehouse_count_by_address[$product['id_address_delivery']][$warehouse['id_warehouse']]++;
			}
		}
		
		// If product from the cart are not in any warehouse, return false
		//foreach ($warehouse_count_by_address as $warehouse_count)
		//	if (empty($warehouse_count))
		//		return false;
		
		arsort($warehouse_count_by_address);
		
		// Step 2 : Group product by warehouse
		$grouped_by_warehouse = array();
		foreach ($product_list as &$product)
		{
			if (!isset($grouped_by_warehouse[$product['id_address_delivery']]))
				$grouped_by_warehouse[$product['id_address_delivery']] = array(
					'in_stock' => array(),
					'out_of_stock' => array(),
				);
			
			// Determine the warehouse to use for this product in order to reduce the number of package
			$id_warehouse = 0;
			foreach ($warehouse_count_by_address[$product['id_address_delivery']] as $id_warehouse)
				if (in_array($id_warehouse, $product['warehouse_list']))
					break;
			
			if (!isset($grouped_by_warehouse[$product['id_address_delivery']]['in_stock'][$id_warehouse]))
			{
				$grouped_by_warehouse[$product['id_address_delivery']]['in_stock'][$id_warehouse] = array();
				$grouped_by_warehouse[$product['id_address_delivery']]['out_of_stock'][$id_warehouse] = array();
			}
			
			if (!$this->allow_seperated_package)
				$key = 'in_stock';
			else
				$key = ($product['in_stock']) ? 'in_stock' : 'out_of_stock';
			
			$product['carrier_list'] = Carrier::getAvailableCarrierList($product, $id_warehouse);
			
			if (empty($product['carrier_list']))
				$product['carrier_list'] = array(0);
				
			$grouped_by_warehouse[$product['id_address_delivery']][$key][$id_warehouse][] = $product;
		}
		
		// Step 3 : grouped product from grouped_by_warehouse by available carriers
		$grouped_by_carriers = array();
		foreach ($grouped_by_warehouse as $id_address_delivery => $products_in_stock_list)
		{
			if (!isset($grouped_by_carriers[$id_address_delivery]))
				$grouped_by_carriers[$id_address_delivery] = array(
					'in_stock' => array(),
					'out_of_stock' => array(),
				);
			
			foreach ($products_in_stock_list as $key => $warehouse_list)
			{
				if (!isset($grouped_by_carriers[$id_address_delivery][$key]))
					$grouped_by_carriers[$id_address_delivery][$key] = array();
				foreach ($warehouse_list as $id_warehouse => $product_list)
				{
					if (!isset($grouped_by_carriers[$id_address_delivery][$key][$id_warehouse]))
						$grouped_by_carriers[$id_address_delivery][$key][$id_warehouse] = array();
					
					foreach ($product_list as $product)
					{
						$package_carriers_key = implode(',', $product['carrier_list']);
						
						if (!isset($grouped_by_carriers[$id_address_delivery][$key][$id_warehouse][$package_carriers_key]))
							$grouped_by_carriers[$id_address_delivery][$key][$id_warehouse][$package_carriers_key] = array(
								'product_list' => array(),
								'carrier_list' => $product['carrier_list']
							);
						
						$grouped_by_carriers[$id_address_delivery][$key][$id_warehouse][$package_carriers_key]['product_list'][] = $product;
					}
				}
			}
		}
		
		$package_list = array();
		// Step 4 : merge product from grouped_by_carriers into $package to minimize the number of package
		foreach ($grouped_by_carriers as $id_address_delivery => $products_in_stock_list)
		{
			if (!isset($package_list[$id_address_delivery]))
				$package_list[$id_address_delivery] = array(
					'in_stock' => array(),
					'out_of_stock' => array(),
				);
			
			
			foreach ($products_in_stock_list as $key => $warehouse_list)
			{
				if (!isset($package_list[$id_address_delivery][$key]))
					$package_list[$id_address_delivery][$key] = array();
				// Count occurance of each carriers to minimize the number of packages
				$carrier_count = array();
				foreach ($warehouse_list as $id_warehouse => $products_grouped_by_carriers)
				{
					foreach ($products_grouped_by_carriers as $data)
					{
						foreach ($data['carrier_list'] as $id_carrier)
						if (!isset($carrier_count[$id_carrier]))
							$carrier_count[$id_carrier] = 0;
						$carrier_count[$id_carrier]++;
					}
				}
				arsort($carrier_count);
				
				foreach ($warehouse_list as $id_warehouse => $products_grouped_by_carriers)
				{
					if (!isset($package_list[$id_address_delivery][$key][$id_warehouse]))
						$package_list[$id_address_delivery][$key][$id_warehouse] = array();
					foreach ($products_grouped_by_carriers as $data)
					{
						foreach ($carrier_count as $id_carrier => $rate)
						{
							if (in_array($id_carrier, $data['carrier_list']))
							{
								if (!isset($package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]))
									$package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier] = array(
										'carrier_list' => $data['carrier_list'],
										'product_list' => array(),
									);
								$package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['carrier_list'] =
									array_intersect($package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['carrier_list'], $data['carrier_list']);
								$package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['product_list'] =
									array_merge($package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['product_list'], $data['product_list']);
								break;
							}
						}
					}
				}
			}
		}
		
		// Step 5 : Reduce deep of $package_list
		$final_package_list = array();
		foreach ($package_list as $id_address_delivery => $products_in_stock_list)
		{
			if (!isset($final_package_list[$id_address_delivery]))
				$final_package_list[$id_address_delivery] = array();
			
			foreach ($products_in_stock_list as $key => $warehouse_list)
				foreach ($warehouse_list as $id_warehouse => $products_grouped_by_carriers)
					foreach ($products_grouped_by_carriers as $data)
						$final_package_list[$id_address_delivery][] = array(
							'product_list' => $data['product_list'],
							'carrier_list' => $data['carrier_list'],
							'id_warehouse' => $id_warehouse,
						);
		}
		return $final_package_list;
	}
	
	/**
	 * Get all deliveries options available for the current cart
	 * @param Country $default_country
	 * 
	 * @return array array(
	 *                   0 => array( // First address
	 *                       0 => array(  // First delivery option available for this address
	 *                           carrier_list => array(
	 *                               12 => array( // First carrier for this option
	 *                                   'instance' => Carrier Object,
	 *                                   'logo' => <url to the carriers logo>,
	 *                                   'price_with_tax' => 12.4,
	 *                                   'price_without_tax' => 12.4,
	 *                                   'package_list' => array(
	 *                                       1,
	 *                                       3,
	 *                                   ),
	 *                               ),
	 *                           ),
	 *                           is_best_grade => true, // Does this option have the biggest grade (quick shipping) for this shipping address
	 *                           is_best_price => true, // Does this option have the lower price for this shipping address
	 *                           price_with_tax => 12.5,
	 *                           price_without_tax => 12.5,
	 *                       ),
	 *                   ),
	 *               );
	 */
	public function getDeliveryOptionList(Country $default_country = null)
	{
		$delivery_option_list = array();
		$carriers_price = array();
		$carrier_collection = array();
		
		$package_list = $this->getPackageList();
		foreach ($package_list as $id_address => $packages)
		{
			$delivery_option_list[$id_address] = array();
			$carriers_price[$id_address] = array();
			
			$common_carriers = array();
			$best_price_carriers = array();
			$best_grade_carriers = array();
			foreach ($packages as $id_package => $package)
			{
				// No carriers available
				if (count($package['carrier_list']) == 1 && $package['carrier_list'][0] == 0)
					return array();
				
				$carriers_price[$id_address][$id_package] = array();
				$carriers_instance = array();
				
				if (empty($common_carriers))
					$common_carriers = $package['carrier_list'];
				else
					array_intersect($common_carriers, $package['carrier_list']);
				$best_price = null;
				$best_price_carrier = null;
				$best_grade = null;
				$best_grade_carrier = null;
				foreach ($package['carrier_list'] as $id_carrier)
				{
					if (!isset($carriers_instance[$id_carrier]))
						$carriers_instance[$id_carrier] = new Carrier($id_carrier);
					$price_with_tax = $this->getPackageShippingCost($id_carrier, true, $default_country, $package['product_list']);
					$price_without_tax = $this->getPackageShippingCost($id_carrier, false, $default_country, $package['product_list']);
					if (is_null($best_price) || $price_with_tax < $best_price)
					{
						$best_price = $price_with_tax;
						$best_price_carrier = $id_carrier;
					}
					$carriers_price[$id_address][$id_package][$id_carrier] = array(
						'without_tax' => $price_without_tax,
						'with_tax' => $price_with_tax);
					
					$grade = $carriers_instance[$id_carrier]->grade;
					if (is_null($best_grade) || $grade > $best_grade)
					{
						$best_grade = $grade;
						$best_grade_carrier = $id_carrier;
					}
					
				}
				$best_price_carriers[$id_package] = $best_price_carrier;
				$best_grade_carriers[$id_package] = $best_grade_carrier;
			}
			
			$best_price_carrier = array();
			$key = '';
			foreach ($best_price_carriers as $id_package => $id_carrier)
			{
				$key .= $id_carrier.',';
				if (!isset($best_price_carrier[$id_carrier]))
					$best_price_carrier[$id_carrier] = array(
						'price_with_tax' => 0,
						'price_without_tax' => 0,
						'package_list' => array()
					);
				$best_price_carrier[$id_carrier]['price_with_tax'] += $carriers_price[$id_address][$id_package][$id_carrier]['with_tax'];
				$best_price_carrier[$id_carrier]['price_without_tax'] += $carriers_price[$id_address][$id_package][$id_carrier]['without_tax'];
				$best_price_carrier[$id_carrier]['package_list'][] = $id_package;
				$best_price_carrier[$id_carrier]['instance'] = $carriers_instance[$id_carrier];
			}
			$delivery_option_list[$id_address][$key] = array(
				'carrier_list' => $best_price_carrier,
				'is_best_price' => true,
				'is_best_grade' => false,
				'unique_carrier' => false
			);
			
			$best_grade_carrier = array();
			$key = '';
			foreach ($best_grade_carriers as $id_package => $id_carrier)
			{
				$key .= $id_carrier.',';
				if (!isset($best_grade_carrier[$id_carrier]))
					$best_grade_carrier[$id_carrier] = array(
						'price_with_tax' => 0,
						'price_without_tax' => 0,
						'package_list' => array()
					);
				$best_grade_carrier[$id_carrier]['price_with_tax'] += $carriers_price[$id_address][$id_package][$id_carrier]['with_tax'];
				$best_grade_carrier[$id_carrier]['price_without_tax'] += $carriers_price[$id_address][$id_package][$id_carrier]['without_tax'];
				$best_grade_carrier[$id_carrier]['package_list'][] = $id_package;
				$best_grade_carrier[$id_carrier]['instance'] = $carriers_instance[$id_carrier];
			}
			if (!isset($delivery_option_list[$id_address][$key]))
				$delivery_option_list[$id_address][$key] = array(
					'carrier_list' => $best_grade_carrier,
					'is_best_price' => false,
					'unique_carrier' => false
				);
			$delivery_option_list[$id_address][$key]['is_best_grade'] = true;
			
			foreach ($common_carriers as $id_carrier)
			{
				$price = 0;
				$key = '';
				$package_list = array();
				$total_price_with_tax = 0;
				$total_price_without_tax = 0;
				$price_with_tax = 0;
				$price_without_tax = 0;
				foreach ($packages as $id_package => $package)
				{
					$key .= $id_carrier.',';
					$price_with_tax += $carriers_price[$id_address][$id_package][$id_carrier]['with_tax'];
					$price_without_tax += $carriers_price[$id_address][$id_package][$id_carrier]['without_tax'];
					$package_list[] = $id_package;
				}
				if (!isset($delivery_option_list[$id_address][$key]))
					$delivery_option_list[$id_address][$key] = array(
						'is_best_price' => false,
						'is_best_grade' => false,
					'unique_carrier' => false,
						'carrier_list' => array(
							$id_carrier => array(
								'price_with_tax' => $price_with_tax,
								'price_without_tax' => $price_without_tax,
								'instance' => $carriers_instance[$id_carrier],
								'package_list' => $package_list
							)
						)
					);
				else
					$delivery_option_list[$id_address][$key]['unique_carrier'] = true;
			}
			foreach ($delivery_option_list as $id_address => $delivery_option)
				foreach ($delivery_option as $key => $value)
				{
					$total_price_with_tax = 0;
					$total_price_without_tax = 0;
					foreach ($value['carrier_list'] as $id_carrier => $data)
					{
						$total_price_with_tax += $data['price_with_tax'];
						$total_price_without_tax += $data['price_without_tax'];
						
						if (!isset($carrier_collection[$id_carrier]))
							$carrier_collection[$id_carrier] = new Carrier($id_carrier);
						$delivery_option_list[$id_address][$key]['carrier_list'][$id_carrier]['instance'] = $carrier_collection[$id_carrier];
						
						if (file_exists(_PS_SHIP_IMG_DIR_.$id_carrier.'.jpg'))
							$delivery_option_list[$id_address][$key]['carrier_list'][$id_carrier]['logo'] = _THEME_SHIP_DIR_.$id_carrier.'.jpg';
						else
							$delivery_option_list[$id_address][$key]['carrier_list'][$id_carrier]['logo'] = false;
					}
					$delivery_option_list[$id_address][$key]['total_price_with_tax'] = $total_price_with_tax;
					$delivery_option_list[$id_address][$key]['total_price_without_tax'] = $total_price_without_tax;
				}
		}
		return $delivery_option_list;
	}
	
	/**
	 * Get all delivery addresses object for the current cart
	 */
	public function getAddressCollection()
	{
		$collection = array();
		foreach (Db::getInstance()->executeS('SELECT DISTINCT `id_address_delivery`
			FROM `'._DB_PREFIX_.'cart_product`
			WHERE id_cart = '.(int)$this->id)
			as $row
		)
			$collection[$row['id_address_delivery']] = new Address($row['id_address_delivery']);
		return $collection;
	}
	
	/**
	* Return the delivery option seleted, or if no delivery option was selected, the cheapest option for each address
	* @return array delivery option
	*/
	public function getDeliveryOption($default_country = null)
	{
		// The delivery option was selected
		if (isset($this->delivery_option) && $this->delivery_option != '')
			return unserialize($this->delivery_option);
		// The delivery option is not selected, get the better for all option
		$delivery_option = array();
		foreach ($this->getDeliveryOptionList($default_country) as $id_address => $options)
			foreach ($options as $key => $option)
				if ($option['is_best_price'])
				{
					$delivery_option[$id_address] = $key;
					break;
				}
		return $delivery_option;
	}

	/**
	* Return shipping total for the cart
	*
	* @param array $delivery_option Array of the delivery option for each address
	* @param booleal $useTax
	* @param Country $default_country
	* @return float Shipping total
	*/
	public function getTotalShippingCost($delivery_option = null, $useTax = true, Country $default_country = null)
	{
		if (is_null($delivery_option))
			$delivery_option = $this->getDeliveryOption($default_country);
		
		$total_shipping = 0;
		$delivery_option_list = $this->getDeliveryOptionList();
		foreach ($delivery_option as $id_address => $key)
		{
				if ($useTax)
					$total_shipping += $delivery_option_list[$id_address][$key]['total_price_with_tax'];
				else
					$total_shipping += $delivery_option_list[$id_address][$key]['total_price_without_tax'];
		}
		return $total_shipping;
	}

	/**
	 * Return shipping total
	 * This function is dépreciate, use getTotalShippingCost or getPackageShippingCost
	 *
	 * @param integer $id_carrier Carrier ID (default : current carrier)
	 * @param booleal $useTax
	 * @param Country $default_country
	 * @param Array $product_list
	 * @param array $product_list List of product concerned by the shipping. If null, all the product of the cart are used to calculate the shipping cost
	 * @deprecated since 1.5.0
	 * 
	 * @return float Shipping total
	 */
	public function getOrderShippingCost($id_carrier = null, $useTax = true, Country $default_country = null, $product_list = null)
	{
		return $this->getPackageShippingCost($id_carrier, $useTax, $default_country, $product_list);
	}
	
	/**
	 * Return package shipping cost
	 *
	 * @param integer $id_carrier Carrier ID (default : current carrier)
	 * @param booleal $useTax
	 * @param Country $default_country
	 * @param Array $product_list
	 * @param array $product_list List of product concerned by the shipping. If null, all the product of the cart are used to calculate the shipping cost
	 * 
	 * @return float Shipping total
	 */
	public function getPackageShippingCost($id_carrier = null, $useTax = true, Country $default_country = null, $product_list = null)
	{
		if ($this->isVirtualCart())
			return 0;

		if (!$default_country)
			$default_country = Context::getContext()->country;

		$complete_product_list = $this->getProducts();
		if (is_null($product_list))
			$products = $complete_product_list;
		else
			$products = $product_list;
		
		// Checking discounts in cart
		if (Discount::isFeatureActive())
			$discounts = $this->getDiscounts(true);
		else
			$discounts = null;
		if ($discounts)
			foreach ($discounts AS $id_discount)
				if ($id_discount['id_discount_type'] == Discount::FREE_SHIPPING)
				{
					if ($id_discount['minimal'] > 0)
					{
						$total_cart = 0;

						$categories = Discount::getCategories((int)($id_discount['id_discount']));
						if (sizeof($categories))
							foreach($complete_product_list AS $product)
								if (Product::idIsOnCategoryId((int)($product['id_product']), $categories))
									$total_cart += $product['total_wt'];

						if ($total_cart >= $id_discount['minimal'])
							return 0;
					}
					else
						return 0;
				}

		// Order total in default currency without fees
		$order_total = $this->getOrderTotal(true, Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING, $product_list);

		// Start with shipping cost at 0
		$shipping_cost = 0;

		// If no product added, return 0
		if ($order_total <= 0 && ((!(int)(self::getNbProducts($this->id) && is_null($product_list))) || (count($product_list) && !is_null($product_list))))
			return $shipping_cost;

		// Get id zone
		if (isset($this->id_address_delivery)
			AND $this->id_address_delivery
			AND Customer::customerHasAddress($this->id_customer, $this->id_address_delivery))
			$id_zone = Address::getZoneById((int)($this->id_address_delivery));
		else
		{
			if (!Validate::isLoadedObject($default_country))
				$default_country = new Country(Configuration::get('PS_COUNTRY_DEFAULT'), Configuration::get('PS_LANG_DEFAULT'));
			$id_zone = (int)$default_country->id_zone;
		}

		// If no carrier, select default one
		if (!$id_carrier)
			$id_carrier = $this->id_carrier;

		if ($id_carrier && !$this->isCarrierInRange($id_carrier, $id_zone))
			$id_carrier = '';

		if (empty($id_carrier) && $this->isCarrierInRange(Configuration::get('PS_CARRIER_DEFAULT'), $id_zone))
			$id_carrier = (int)(Configuration::get('PS_CARRIER_DEFAULT'));

		if (empty($id_carrier))
		{
			if ((int)($this->id_customer))
			{
				$customer = new Customer((int)($this->id_customer));
				$result = Carrier::getCarriers((int)(Configuration::get('PS_LANG_DEFAULT')), true, false, (int)($id_zone), $customer->getGroups());
				unset($customer);
			}
			else
				$result = Carrier::getCarriers((int)(Configuration::get('PS_LANG_DEFAULT')), true, false, (int)($id_zone));

			foreach ($result AS $k => $row)
			{
				if ($row['id_carrier'] == Configuration::get('PS_CARRIER_DEFAULT'))
					continue;

				if (!isset(self::$_carriers[$row['id_carrier']]))
					self::$_carriers[$row['id_carrier']] = new Carrier((int)($row['id_carrier']));

				$carrier = self::$_carriers[$row['id_carrier']];

				// Get only carriers that are compliant with shipping method
				if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && $carrier->getMaxDeliveryPriceByWeight($id_zone) === false)
				|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE && $carrier->getMaxDeliveryPriceByPrice($id_zone) === false))
				{
					unset($result[$k]);
					continue ;
				}

				// If out-of-range behavior carrier is set on "Desactivate carrier"
				if ($row['range_behavior'])
				{
					// Get only carriers that have a range compatible with cart
					if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && (!Carrier::checkDeliveryPriceByWeight($row['id_carrier'], $this->getTotalWeight(), $id_zone)))
					|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE && (!Carrier::checkDeliveryPriceByPrice($row['id_carrier'], $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $id_zone, (int)($this->id_currency)))))
					{
						unset($result[$k]);
						continue ;
					}
				}

				if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
					$shipping = $carrier->getDeliveryPriceByWeight($this->getTotalWeight($product_list), $id_zone);
				else
					$shipping = $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)($this->id_currency));

				if (!isset($minShippingPrice))
					$minShippingPrice = $shipping;

				if ($shipping <= $minShippingPrice)
					{
						$id_carrier = (int)($row['id_carrier']);
					$minShippingPrice = $shipping;
				}
			}
		}

		if (empty($id_carrier))
			$id_carrier = Configuration::get('PS_CARRIER_DEFAULT');

		if (!isset(self::$_carriers[$id_carrier]))
			self::$_carriers[$id_carrier] = new Carrier($id_carrier, Configuration::get('PS_LANG_DEFAULT'));
		$carrier = self::$_carriers[$id_carrier];
		if (!Validate::isLoadedObject($carrier))
			die(Tools::displayError('Fatal error: "no default carrier"'));
		if (!$carrier->active)
			return $shipping_cost;

		// Free fees if free carrier
		if ($carrier->is_free == 1)
			return 0;

		// Select carrier tax
		if ($useTax AND !Tax::excludeTaxeOption())
			 $carrierTax = $carrier->getTaxesRate(new Address((int)$this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));

		$configuration = Configuration::getMultiple(array('PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_HANDLING', 'PS_SHIPPING_METHOD', 'PS_SHIPPING_FREE_WEIGHT'));
		// Free fees
		$free_fees_price = 0;
		if (isset($configuration['PS_SHIPPING_FREE_PRICE']))
			$free_fees_price = Tools::convertPrice((float)($configuration['PS_SHIPPING_FREE_PRICE']), Currency::getCurrencyInstance((int)($this->id_currency)));
		// $orderTotalwithDiscounts = $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING);
		// if ($orderTotalwithDiscounts >= (float)($free_fees_price) AND (float)($free_fees_price) > 0)
			// return $shipping_cost;
		if (isset($configuration['PS_SHIPPING_FREE_WEIGHT']) && $this->getTotalWeight() >= (float)($configuration['PS_SHIPPING_FREE_WEIGHT']) && (float)($configuration['PS_SHIPPING_FREE_WEIGHT']) > 0)
			return $shipping_cost;

		// Get shipping cost using correct method
		if ($carrier->range_behavior)
		{
			// Get id zone
			if (
				isset($this->id_address_delivery)
				AND $this->id_address_delivery
				AND Customer::customerHasAddress($this->id_customer, $this->id_address_delivery)
			)
				$id_zone = Address::getZoneById((int)($this->id_address_delivery));
			else
				$id_zone = (int)$default_country->id_zone;
			if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && (!Carrier::checkDeliveryPriceByWeight($carrier->id, $this->getTotalWeight(), $id_zone)))
				|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE
				&& (!Carrier::checkDeliveryPriceByPrice($carrier->id, $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $id_zone, (int)($this->id_currency))))
			)
				$shipping_cost += 0;
			else
			{
				if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
					$shipping_cost += $carrier->getDeliveryPriceByWeight($this->getTotalWeight($product_list), $id_zone);
				else // by price
					$shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)($this->id_currency));
			}
		}
		else
		{
			if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
				$shipping_cost += $carrier->getDeliveryPriceByWeight($this->getTotalWeight($product_list), $id_zone);
			else
				$shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)($this->id_currency));

		}
		// Adding handling charges
		if (isset($configuration['PS_SHIPPING_HANDLING']) AND $carrier->shipping_handling)
			$shipping_cost += (float)($configuration['PS_SHIPPING_HANDLING']);

		// TODO : $products does not exists
		// Additional Shipping Cost per product
		// foreach($products AS $product)
			// $shipping_cost += $product['additional_shipping_cost'] * $product['cart_quantity'];

		$shipping_cost = Tools::convertPrice($shipping_cost, Currency::getCurrencyInstance((int)($this->id_currency)));

		//get external shipping cost from module
		if ($carrier->shipping_external)
		{
			$moduleName = $carrier->external_module_name;
			$module = Module::getInstanceByName($moduleName);

			if (Validate::isLoadedObject($module))
			{
				if (array_key_exists('id_carrier', $module))
					$module->id_carrier = $carrier->id;
				if ($carrier->need_range)
					$shipping_cost = $module->getPackageShippingCost($this, $shipping_cost);
				else
					$shipping_cost = $module->getOrderShippingCostExternal($this);

				// Check if carrier is available
				if ($shipping_cost === false)
					return false;
			}
			else
				return false;
		}

		// Apply tax
		if (isset($carrierTax))
			$shipping_cost *= 1 + ($carrierTax / 100);

		return (float)(Tools::ps_round((float)($shipping_cost), 2));
	}

	/**
	* Return cart weight
	* @return float Cart weight
	*/
	public function getTotalWeight($products = null)
	{
		if(!is_null($products))
		{
			$total_weight = 0;
			foreach($products as $product)
			{
				if (is_null($product['weight_attribute']))
					$total_weight += $product['weight'];
				else
					$total_weight += $product['weight_attribute'];
			}
			return $total_weight;
		}
		
		if (!isset(self::$_totalWeight[$this->id]))
		{
			if (Combination::isFeatureActive())
				$weight_product_with_attribute = Db::getInstance()->getValue('
				SELECT SUM((p.`weight` + pa.`weight`) * cp.`quantity`) as nb
				FROM `'._DB_PREFIX_.'cart_product` cp
				LEFT JOIN `'._DB_PREFIX_.'product` p ON (cp.`id_product` = p.`id_product`)
				LEFT JOIN `'._DB_PREFIX_.'product_attribute` pa ON (cp.`id_product_attribute` = pa.`id_product_attribute`)
				WHERE (cp.`id_product_attribute` IS NOT NULL AND cp.`id_product_attribute` != 0)
				AND cp.`id_cart` = '.(int)($this->id));
			else
				$weight_product_with_attribute = 0;

			$weight_product_without_attribute = Db::getInstance()->getValue('
			SELECT SUM(p.`weight` * cp.`quantity`) as nb
			FROM `'._DB_PREFIX_.'cart_product` cp
			LEFT JOIN `'._DB_PREFIX_.'product` p ON (cp.`id_product` = p.`id_product`)
			WHERE (cp.`id_product_attribute` IS NULL OR cp.`id_product_attribute` = 0)
			AND cp.`id_cart` = '.(int)($this->id));

			self::$_totalWeight[$this->id] = round((float)$weight_product_with_attribute + (float)$weight_product_without_attribute, 3);
		}
		return self::$_totalWeight[$this->id];
	}

	/**
	 * @deprecated 1.5.0.1
	 */
	public function checkDiscountValidity($obj, $discounts, $order_total, $products, $checkCartDiscount = false)
	{
		Tools::displayAsDeprecated();
		$context = Context::getContext()->cloneContext();
		$context->cart = $this;
		return $obj->checkValidity($context);
	}

	/**
	* Return useful informations for cart
	*
	* @return array Cart details
	*/
	public function getSummaryDetails($id_lang = null)
	{
		if (!$id_lang)
			$id_lang = Context::getContext()->language->id;

		$delivery = new Address((int)($this->id_address_delivery));
		$invoice = new Address((int)($this->id_address_invoice));

		// New layout system with personalization fields
		$formattedAddresses['invoice'] = AddressFormat::getFormattedLayoutData($invoice);
		$formattedAddresses['delivery'] = AddressFormat::getFormattedLayoutData($delivery);

		$total_tax = $this->getOrderTotal() - $this->getOrderTotal(false);

		if ($total_tax < 0)
			$total_tax = 0;

		return array(
			'delivery' => $delivery,
			'delivery_state' => State::getNameById($delivery->id_state),
			'invoice' => $invoice,
			'invoice_state' => State::getNameById($invoice->id_state),
			'formattedAddresses' => $formattedAddresses,
			'carrier' => new Carrier($this->id_carrier, $id_lang),
			'products' => $this->getProducts(false),
			'discounts' => $this->getCartRules(),
			'is_virtual_cart' => (int)$this->isVirtualCart(),
			'total_discounts' => $this->getOrderTotal(true, Cart::ONLY_DISCOUNTS),
			'total_discounts_tax_exc' => $this->getOrderTotal(false, Cart::ONLY_DISCOUNTS),
			'total_wrapping' => $this->getOrderTotal(true, Cart::ONLY_WRAPPING),
			'total_wrapping_tax_exc' => $this->getOrderTotal(false, Cart::ONLY_WRAPPING),
			'total_shipping' => $this->getOrderShippingCost(),
			'total_shipping_tax_exc' => $this->getOrderShippingCost(NULL, false),
			'total_products_wt' => $this->getOrderTotal(true, Cart::ONLY_PRODUCTS),
			'total_products' => $this->getOrderTotal(false, Cart::ONLY_PRODUCTS),
			'total_price' => $this->getOrderTotal(),
			'total_tax' => $total_tax,
			'total_price_without_tax' => $this->getOrderTotal(false));
	}

	public function checkQuantities()
	{
		if (Configuration::get('PS_CATALOG_MODE'))
			return false;
		foreach ($this->getProducts() AS $product)
			if (!$product['active'] OR (!$product['allow_oosp'] AND $product['stock_quantity'] < $product['cart_quantity']) OR !$product['available_for_order'])
				return false;
		return true;
	}

	public static function lastNoneOrderedCart($id_customer)
	{
		$sql = 'SELECT c.`id_cart`
				FROM '._DB_PREFIX_.'cart c
				LEFT JOIN '._DB_PREFIX_.'orders o ON (c.`id_cart` = o.`id_cart`)
				WHERE c.`id_customer` = '.(int)($id_customer).'
					AND o.`id_cart` IS NULL
					'.Context::getContext()->shop->addSqlRestriction(Shop::SHARE_ORDER, 'c').'
				ORDER BY c.`date_upd` DESC';
	 	if (!$id_cart = Db::getInstance()->getValue($sql))
	 		return false;
	 	return $id_cart;
	}

	/**
	* Check if cart contains only virtual products
	* @return boolean true if is a virtual cart or false
	*
	*/
	public function isVirtualCart($strict = false)
	{
		if (!ProductDownload::isFeatureActive())
			return false;

		if (!isset(self::$_isVirtualCart[$this->id]))
		{
			$products = $this->getProducts();
			if (!sizeof($products))
				return false;

			$is_virtual = 1;
			foreach ($products AS $product)
			{
				if (empty($product['is_virtual']))
					$is_virtual = 0;
			}
			self::$_isVirtualCart[$this->id] = (int) $is_virtual;
		}

		return self::$_isVirtualCart[$this->id];
	}

	public static function getCartByOrderId($id_order)
	{
		if ($id_cart = self::getCartIdByOrderId($id_order))
			return new Cart((int)($id_cart));

		return false;
	}

	public static function getCartIdByOrderId($id_order)
	{
		$result = Db::getInstance()->getRow('SELECT `id_cart` FROM '._DB_PREFIX_.'orders WHERE `id_order` = '.(int)$id_order);
		if (!$result OR empty($result) OR !key_exists('id_cart', $result))
			return false;
		return $result['id_cart'];
	}

	/*
	* Add customer's text
	*
	* @return bool Always true
	*/
	public function addTextFieldToProduct($id_product, $index, $type, $textValue)
	{
		$textValue = str_replace(array("\n", "\r"), '', nl2br($textValue));
		$textValue = str_replace('\\', '\\\\', $textValue);
		$textValue = str_replace('\'', '\\\'', $textValue);
		return $this->_addCustomization($id_product, 0, $index, $type, $textValue, 0);
	}

	/*
	* Add customer's pictures
	*
	* @return bool Always true
	*/
	public function addPictureToProduct($id_product, $index, $type, $file)
	{
		return $this->_addCustomization($id_product, 0, $index, $type, $file, 0);
	}

	/*
	* Remove a customer's customization
	*
	* @return bool
	*/
	public function deleteCustomizationToProduct($id_product, $index)
	{
		$result = true;

		$custData = Db::getInstance()->getRow('
		SELECT cu.`id_customization`, cd.`index`, cd.`value`, cd.`type` FROM `'._DB_PREFIX_.'customization` cu
		LEFT JOIN `'._DB_PREFIX_.'customized_data` cd
		ON cu.`id_customization` = cd.`id_customization`
		WHERE cu.`id_cart` = '.(int)$this->id.'
		AND cu.`id_product` = '.(int)$id_product.'
		AND `index` = '.(int)$index.'
		AND `in_cart` = 0'
		);

		// Delete customization picture if necessary
		if ($custData['type'] == 0)
			$result &= (@unlink(_PS_UPLOAD_DIR_.$custData['value']) && @unlink(_PS_UPLOAD_DIR_.$custData['value'].'_small'));

		$result &= Db::getInstance()->execute('DELETE
			FROM `'._DB_PREFIX_.'customized_data`
			WHERE `id_customization` = '.(int)$custData['id_customization'].'
			AND `index` = '.(int)$index
		);
		return $result;
	}

	/**
	 * Return custom pictures in this cart for a specified product
	 *
	 * @param int $id_product
	 * @param int $type only return customization of this type
	 * @param bool $not_in_cart only return customizations that are not in cart already
	 * @return array result rows
	 */
	public function getProductCustomization($id_product, $type = null, $not_in_cart = false)
	{
		if (!Customization::isFeatureActive())
			return array();
		$result = Db::getInstance()->executeS('
			SELECT cu.id_customization, cd.index, cd.value, cd.type, cu.in_cart, cu.quantity
			FROM `'._DB_PREFIX_.'customization` cu
			LEFT JOIN `'._DB_PREFIX_.'customized_data` cd ON (cu.`id_customization` = cd.`id_customization`)
			WHERE cu.id_cart = '.(int)$this->id.'
			AND cu.id_product = '.(int)$id_product.
			($type === Product::CUSTOMIZE_FILE ? ' AND type = '.(int)Product::CUSTOMIZE_FILE : '').
			($type === Product::CUSTOMIZE_TEXTFIELD ? ' AND type = '.(int)Product::CUSTOMIZE_TEXTFIELD : '').
			($not_in_cart ? ' AND in_cart = 0' : '')
		);
		return $result;
	}

	public static function getCustomerCarts($id_customer)
	{
		$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT *
			FROM '._DB_PREFIX_.'cart c
			WHERE c.`id_customer` = '.(int)($id_customer).'
			ORDER BY c.`date_add` DESC');
		return $result;
	}

	public static function replaceZeroByShopName($echo, $tr)
	{
		return ($echo == '0' ? Configuration::get('PS_SHOP_NAME') : $echo);
	}

	public function duplicate()
	{
		if (!Validate::isLoadedObject($this))
			return false;
		$cart = new Cart($this->id);
		$cart->id = NULL;
		$cart->id_shop = $this->id_shop;
		$cart->id_group_shop = $this->id_group_shop;

		$cart->add();

		if (!Validate::isLoadedObject($cart))
			return false;
		$success = true;
		$products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT * FROM `'._DB_PREFIX_.'cart_product` WHERE `id_cart` = '.(int)$this->id);

		foreach ($products AS $product)
			$success &= $cart->updateQty($product['quantity'], (int)$product['id_product'], (int)$product['id_product_attribute'], NULL, (int)$product['id_address_delivery'], 'up', new Shop($cart->id_shop));

		// Customized products
		$customs = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
		SELECT *
		FROM '._DB_PREFIX_.'customization c
		LEFT JOIN '._DB_PREFIX_.'customized_data cd ON cd.id_customization = c.id_customization
		WHERE c.id_cart = '.(int)$this->id);

		// Get datas from customization table
		$customsById = array();
		foreach ($customs AS $custom)
		{
			if(!isset($customsById[$custom['id_customization']]))
				$customsById[$custom['id_customization']] = array('id_product_attribute' => $custom['id_product_attribute'],
				'id_product' => $custom['id_product'], 'quantity' => $custom['quantity']);
		}

		// Insert new customizations
		$custom_ids = array();
		foreach($customsById as $customizationId => $val)
		{
			Db::getInstance()->execute('
				INSERT INTO `'._DB_PREFIX_.'customization` (id_cart, id_product_attribute, id_product, quantity)
			VALUES('.(int)$cart->id.', '.(int)$val['id_product_attribute'].', '.(int)$val['id_product'].', '.(int)$val['quantity'].')');
			$custom_ids[$customizationId] = Db::getInstance(_PS_USE_SQL_SLAVE_)->Insert_ID();
		}

		// Insert customized_data
		if (sizeof($customs))
		{
			$first = true;
			$sql_custom_data = 'INSERT INTO '._DB_PREFIX_.'customized_data (`id_customization`, `type`, `index`, `value`) VALUES ';
			foreach ($customs AS $custom)
			{
				if(!$first)
					$sql_custom_data .= ',';
				else
					$first = false;
				$sql_custom_data .= '('.(int)$custom_ids[$custom['id_customization']].', '.(int)$custom['type'].', '.(int)$custom['index'].', \''.pSQL($custom['value']).'\')';
			}
			Db::getInstance()->execute($sql_custom_data);
		}

		return array('cart' => $cart, 'success' => $success);
	}

	public function getWsCartRows()
	{
		$query = 'SELECT id_product, id_product_attribute, quantity
		FROM `'._DB_PREFIX_.'cart_product`
		WHERE id_cart = '.(int)$this->id;
		$result = Db::getInstance()->executeS($query);
		return $result;
	}

	public function setWsCartRows($values)
	{
		if ($this->deleteAssociations())
		{
			$query = 'INSERT INTO `'._DB_PREFIX_.'cart_product`(`id_cart`, `id_product`, `id_product_attribute`, `quantity`, `date_add`) VALUES ';
			foreach ($values as $value)
				$query .= '('.(int)$this->id.', '.(int)$value['id_product'].', '.(isset($value['id_product_attribute']) ? (int)$value['id_product_attribute'] : 'NULL').', '.(int)$value['quantity'].', NOW()),';
			Db::getInstance()->execute(rtrim($query, ','));
		}
		return true;
	}
	
	public function setProductAddressDelivery($id_product, $id_product_attribute, $old_id_address_delivery, $new_id_address_delivery)
	{
		// Check address is linked with the customer
		if (!Customer::customerHasAddress(Context::getContext()->customer->id, $new_id_address_delivery))
			return false;
		
		if ($new_id_address_delivery == $old_id_address_delivery)
			return false;
		
		// Checking if the product with the old address delivery exists
		$sql = new DbQuery();
		$sql->select('count(*)');
		$sql->from('cart_product as cp');
		$sql->where('id_product = '.(int)$id_product);
		$sql->where('id_product_attribute = '.(int)$id_product_attribute);
		$sql->where('id_address_delivery = '.(int)$old_id_address_delivery);
		$sql->where('id_cart = '.(int)$this->id);
		$result = Db::getInstance()->getValue($sql);
		if ($result == 0)
			return false;
		
		// Checking if there is no others similar products with this new address delivery
		$sql = new DbQuery();
		$sql->select('sum(quantity) as qty');
		$sql->from('cart_product as cp');
		$sql->where('id_product = '.(int)$id_product);
		$sql->where('id_product_attribute = '.(int)$id_product_attribute);
		$sql->where('id_address_delivery = '.(int)$new_id_address_delivery);
		$sql->where('id_cart = '.(int)$this->id);
		$result = Db::getInstance()->getValue($sql);
		
		// Removing similar products with this new address delivery
		$sql = 'DELETE FROM '._DB_PREFIX_.'cart_product
			WHERE id_product = '.(int)$id_product.'
			AND id_product_attribute = '.(int)$id_product_attribute.'
			AND id_address_delivery = '.(int)$new_id_address_delivery.'
			AND id_cart = '.(int)$this->id.'
			LIMIT 1';
		Db::getInstance()->execute($sql);
		
		// Changing the address
		$sql = 'UPDATE '._DB_PREFIX_.'cart_product
			SET `id_address_delivery` = '.(int)$new_id_address_delivery.',
			`quantity` = `quantity` + '.(int)$result['sum'].'
			WHERE id_product = '.(int)$id_product.'
			AND id_product_attribute = '.(int)$id_product_attribute.'
			AND id_address_delivery = '.(int)$old_id_address_delivery.'
			AND id_cart = '.(int)$this->id.'
			LIMIT 1';
		Db::getInstance()->execute($sql);
		
		// Changing the address of the customizations
		$sql = 'UPDATE '._DB_PREFIX_.'customization
			SET `id_address_delivery` = '.(int)$new_id_address_delivery.'
			WHERE id_product = '.(int)$id_product.'
			AND id_product_attribute = '.(int)$id_product_attribute.'
			AND id_address_delivery = '.(int)$old_id_address_delivery.'
			AND id_cart = '.(int)$this->id;
		Db::getInstance()->execute($sql);
		
		return true;
	}
	
	public function duplicateProduct($id_product, $id_product_attribute, $id_address_delivery, $new_id_address_delivery, $quantity = 1, $keep_quantity = false)
	{
		// Check address is linked with the customer
		if (!Customer::customerHasAddress(Context::getContext()->customer->id, $new_id_address_delivery))
			return false;
		
		// Checking the product do not exist with the new address
		$sql = new DbQuery();
		$sql->select('count(*)');
		$sql->from('cart_product as c');
		$sql->where('id_product = '.(int)$id_product);
		$sql->where('id_product_attribute = '.(int)$id_product_attribute);
		$sql->where('id_address_delivery = '.(int)$new_id_address_delivery);
		$sql->where('id_cart = '.(int)$this->id);
		$result = Db::getInstance()->getValue($sql);
		if ($result > 0)
			return false;
		
		// Duplicating cart_product line
		$sql = 'INSERT INTO '._DB_PREFIX_.'cart_product values(
			'.(int)$this->id.',
			'.(int)$id_product.',
			'.(int)$this->id_shop.',
			'.(int)$id_product_attribute.',
			'.(int)$quantity.',
			NOW(),
			'.(int)$new_id_address_delivery.')';
		Db::getInstance()->execute($sql);
		
		if (!$keep_quantity)
		{
			$sql = 'UPDATE '._DB_PREFIX_.'cart_product
				SET `quantity` = `quantity` - '.(int)$quantity.'
				WHERE id_cart = '.(int)$this->id.'
				AND id_product = '.(int)$id_product.'
				AND id_shop = '.(int)$this->id_shop.'
				AND id_product_attribute = '.(int)$id_product_attribute.'
				AND id_address_delivery = '.(int)$id_address_delivery;
			Db::getInstance()->execute($sql);
		}
		
		// Checking if there is customizations
		$sql = new DbQuery();
		$sql->select('*');
		$sql->from('customization as c');
		$sql->where('id_product = '.(int)$id_product);
		$sql->where('id_product_attribute = '.(int)$id_product_attribute);
		$sql->where('id_address_delivery = '.(int)$id_address_delivery);
		$sql->where('id_cart = '.(int)$this->id);
		$results = Db::getInstance()->executeS($sql);

		foreach ($results as $customization)
		{
			
			// Duplicate customization
			$sql = 'INSERT INTO '._DB_PREFIX_.'customization(`id_product_attribute`, `id_address_delivery`, `id_cart`, `id_product`, `quantity`, `in_cart`)
				VALUES (
					'.$customization['id_product_attribute'].',
					'.$new_id_address_delivery.',
					'.$customization['id_cart'].',
					'.$customization['id_product'].',
					'.$quantity.',
					'.$customization['in_cart'].')';
			Db::getInstance()->execute($sql);
			
			$sql = 'INSERT INTO '._DB_PREFIX_.'customized_data(`id_customization`, `type`, `index`, `value`)
				(SELECT '.(int)Db::getInstance()->Insert_ID().' `id_customization`, `type`, `index`, `value` FROM customized_data WHERE id_customization = '.$customization['id_customization'].')';
			Db::getInstance()->execute($sql);
		}
		
		$customization_count = count($results);
		if ($customization_count > 0)
		{
			$sql = 'UPDATE '._DB_PREFIX_.'cart_product
				SET `quantity` = `quantity` = '.(int)($customization_count * $quantity).'
				WHERE id_cart = '.(int)$this->id.'
				AND id_product = '.(int)$id_product.'
				AND id_shop = '.(int)$this->id_shop.'
				AND id_product_attribute = '.(int)$id_product_attribute.'
				AND id_address_delivery = '.(int)$new_id_address_delivery;
			Db::getInstance()->execute($sql);
		}
		return true;
	}
	
	/**
	 * Update products cart address delivery with the address delivery of the cart
	 */
	public function setNoMultishipping()
	{
		// Upgrading quantities
		$sql = 'SELECT sum(`quantity`) as quantity, id_product, id_product_attribute, count(*) as count
				FROM `'._DB_PREFIX_.'cart_product`
				WHERE `id_cart` = '.(int)$this->id.'
					AND `id_shop` = '.(int)$this->id_shop.'
				GROUP BY id_product, id_product_attribute
				HAVING count > 1';
		foreach (Db::getInstance()->executeS($sql) as $product)
		{
			$sql = 'UPDATE `'._DB_PREFIX_.'cart_product`
				SET `quantity` = '.$product['quantity'].'
				WHERE  `id_cart` = '.(int)$this->id.'
					AND `id_shop` = '.(int)$this->id_shop.'
					AND id_product = '.$product['id_product'].'
					AND id_product_attribute = '.$product['id_product_attribute'];
				Db::getInstance()->execute($sql);
		}
		
		// Merging multiple lines
		$sql = 'DELETE cp1
			FROM `'._DB_PREFIX_.'cart_product` cp1
				INNER JOIN `'._DB_PREFIX_.'cart_product` cp2
				ON (
					(cp1.id_cart = cp2.id_cart)
					AND (cp1.id_product = cp2.id_product)
					AND (cp1.id_product_attribute = cp2.id_product_attribute)
					AND (cp1.id_address_delivery <> cp2.id_address_delivery)
					AND (cp1.date_add > cp2.date_add)
				)';
				Db::getInstance()->execute($sql);
		
		
		// upgradng address delivery
		$sql = 'UPDATE `'._DB_PREFIX_.'cart_product`
			SET `id_address_delivery` =
			(
				SELECT `id_address_delivery`
				FROM `'._DB_PREFIX_.'cart`
				WHERE `id_cart` = '.(int)$this->id.'
					AND `id_shop` = '.(int)$this->id_shop.'
			)
			WHERE `id_cart` = '.(int)$this->id.' AND `id_shop` = '.(int)$this->id_shop;
		Db::getInstance()->execute($sql);
		$sql = 'UPDATE `'._DB_PREFIX_.'customization`
			SET `id_address_delivery` =
			(
				SELECT `id_address_delivery`
				FROM `'._DB_PREFIX_.'cart`
				WHERE `id_cart` = '.(int)$this->id.'
					AND `id_shop` = '.(int)$this->id_shop.'
			)
			WHERE `id_cart` = '.(int)$this->id;
		Db::getInstance()->execute($sql);
	}
	
	/**
	 * Set an address to all products on the cart without address delivery
	 */
	public function autosetProductAddress()
	{
		// Get the main address of the customer
		if ((int)$this->id_address_delivery > 0)
			$id_address_deivery = (int)$this->id_address_delivery;
		else
		{
			if ((int)$cart->id_customer == 0)
				return;
			
			$customer = new Customer((int)$cart->id_customer);
			$addresses = $customer->getAddresses(Context::getContext()->language->id);
			
			if (count($addresses) == 0)
				return;
			
			$id_address_delivery = $addresses[0]['id_address'];
		}
		
		// Update 
		$sql = 'UPDATE `'._DB_PREFIX_.'cart_product`
			SET `id_address_delivery` =
			(
				SELECT `id_address_delivery`
				FROM `'._DB_PREFIX_.'cart`
				WHERE `id_cart` = '.(int)$this->id.'
					AND `id_shop` = '.(int)$this->id_shop.'
			)
			WHERE `id_cart` = '.(int)$this->id.'
				AND (`id_address_delivery` = 0 OR `id_address_delivery` IS NULL)
				AND `id_shop` = '.(int)$this->id_shop;
		Db::getInstance()->execute($sql);
	}

	public function deleteAssociations()
	{
		return (Db::getInstance()->execute('
				DELETE FROM `'._DB_PREFIX_.'cart_product`
				WHERE `id_cart` = '.(int)($this->id)) !== false);
	}

	/**
	 * isGuestCartByCartId
	 *
	 * @param int $id_cart
	 * @return bool true if cart has been made by a guest customer
	 */
	public static function isGuestCartByCartId($id_cart)
	{
		if (!(int)$id_cart)
			return false;
		return (bool)Db::getInstance()->getValue('
			SELECT `is_guest`
			FROM `'._DB_PREFIX_.'customer` cu
			LEFT JOIN `'._DB_PREFIX_.'cart` ca ON (ca.`id_customer` = cu.`id_customer`)
			WHERE ca.`id_cart` = '.(int)$id_cart);
	}

	/**
	 * isCarrierInRange
	 *
	 * Check if the specified carrier is in range
	 *
	 * @id_carrier int
	 * @id_zone int
	 */
	public function isCarrierInRange($id_carrier, $id_zone)
	{
		$carrier = new Carrier((int)$id_carrier, Configuration::get('PS_LANG_DEFAULT'));
		$shippingMethod = $carrier->getShippingMethod();
		if (!$carrier->range_behavior)
			return true;

		if ($shippingMethod == Carrier::SHIPPING_METHOD_FREE)
			return true;
		if ($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT
		AND (Carrier::checkDeliveryPriceByWeight((int)$id_carrier, $this->getTotalWeight(), $id_zone)))
			return true;
		if ($shippingMethod == Carrier::SHIPPING_METHOD_PRICE
			AND (Carrier::checkDeliveryPriceByPrice((int)$id_carrier, $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), $id_zone, (int)$this->id_currency)))
			return true;

		return false;
	}
}

