<?php

abstract class PowaTagAbstract
{

	/**
	 * Request datas
	 * @var array
	 */
	protected $datas;

	/**
	 * Current context
	 * @var Context
	 */
	protected $context;

	/**
	 * Errors
	 * @var array
	 */
	protected $error = null;

	/**
	 * Total without tax
	 * @var integer
	 */
	protected $subTotal = 0;

	/**
	 * Total with tax
	 * @var integer
	 */
	protected $subTotalWt = 0;

	/**
	 * Total tax for products
	 * @var integer
	 */
	protected $subTax = 0;

	public function __construct(stdClass $datas)
	{
		$this->datas = $datas;
		$this->context = Context::getContext();
	}
	
	/**
	 * Get error
	 * @return string Error
	 */
	public function getError()
	{
		return $this->error;
	}

	/**
	 * Get currency object by iso_code
	 * @param  string $iso_code ISO code
	 * @return Currency         Currency Object
	 */
	protected function getCurrencyByIsoCode($iso_code)
	{
		$idCurrency = (int)Currency::getIdByIsoCode($iso_code);
		$currency = new Currency($idCurrency);


		if (!PowaTagValidate::currencyEnable($currency))
		{
			$this->error = "Currency not found : ".$iso_code;
			return false;
		}

		return $currency;
	}

	/**
	 * Get Product object by code
	 * @param  string $code Code
	 * @return Product      Product object
	 */
	protected function getProductByCode($code)
	{
		$idProduct = (int)Product::getIdByEan13($code);
		$product = new Product($idProduct, true, (int)$this->context->language->id);

		return $product;
	}

	/**
	 * Get Country object by code
	 * @param  string $code Code
	 * @return Country      Country object
	 */
	protected function getCountryByCode($code)
	{
		$idCountry = (int)Country::getByIso($code);
		$country = new Country($idCountry, (int)$this->context->language->id);

		return $country;
	}

	/**
	 * Calculate total of products without tax
	 * @return float Total of products
	 */
	protected function getSubTotal($products, $codeCountry)
	{

		if (Validate::isInt($codeCountry))
			$country = new Country($codeCountry);
		else if (!$codeCountry instanceof Country)
			$country = $this->getCountryByCode($codeCountry);

		$address = Address::initialize();
		$address->id_country = $country->id;

		if ($products && count($products))
		{
			foreach ($products as $p)
			{

				$product = self::getProductByCode($p->product->code);

				if (!Validate::isLoadedObject($product))
				{
					$this->error = "This product does not exists : ".$p->product->code;
					return false;
				}

				$variants = $p->product->productVariants;

				$product_rate = 1 + ($product->getTaxesRate($address) / 100);

				foreach ($variants as $variant)
				{
					$variantCurrency = $this->getCurrencyByIsoCode($variant->finalPrice->currency);

					if (!PowaTagValidate::currencyEnable($variantCurrency))
					{
						$this->error = "Currency not found, ".$variant->code." ".$variant->code;
						return false;
					}

					$variantAmount = $variant->finalPrice->amount;

					if ($idProductAttribute = $this->getCombinationByEAN13($product->id, $variant->code))
					{
						$priceAttribute   = $product->getPrice(false, $idProductAttribute);
						$qtyInStock = Product::getQuantity($product->id, $idProductAttribute);
					}
					else if (Validate::isInt($variant->code))
					{
						$priceAttribute   = $product->getPrice(false);
						$qtyInStock = Product::getQuantity($product->id);
					}
					else
					{
						$this->error = "This variant does not exist, ".$variant->code." ".$variant->code;
						return false;
					}

					$priceAttributeWt = $priceAttribute * $product_rate;

					$priceAttribute   = Tools::ps_round($priceAttribute, 2);
					$variantAmount    = Tools::ps_round($variantAmount, 2);

					$this->convertToCurrency($variantAmount, $variantCurrency, false);

					$priceAttribute   = Tools::ps_round($priceAttribute, 2);
					$variantAmount    = Tools::ps_round($variantAmount, 2);
					$priceAttributeWt = Tools::ps_round($priceAttributeWt, 2);

					if ($priceAttribute != $variantAmount)
					{
						$this->error = "Price variant is different with the price shop, ".$variant->code." ".$priceAttribute." != ".$variantAmount;
						return false;
					}

					if ($qtyInStock < $p->quantity)
					{
						$this->error = "Quantity > Stock Count : ".$variant->code;
						return false;
					}

					$totalPriceAttribute = ($priceAttribute * $p->quantity);
					$totalPriceAttributeWt = ($priceAttributeWt * $p->quantity);

					$this->subTotal   += $totalPriceAttribute;
					$this->subTotalWt += $totalPriceAttributeWt;
					$this->subTax     += ($totalPriceAttributeWt - $totalPriceAttribute);

				}

			}
			return true;
		}
		else
			return false;

	}

	/**
	 * Calculate shipping costs without tax
	 * @return float Shipping costs
	 */
	protected function getShippingCost($products, Currency $currency, $country)
	{

		$id_carrier = (int)Configuration::get('POWATAG_SHIPPING');

		if (!$country instanceof Country)
		{
			if (Validate::isInt($country))
				$country = new Country((int)$country, (int)$this->context->language->id);
			else
				$country = $this->getCountryByCode($country);
		}

		if (!PowaTagValidate::countryEnable($country))
		{
			$this->error = "Country is does not exists or does not enable for this shop : ".$countryIso;
			return false;
		}

		$shippingCost = $this->getShippingCostByCarrier($products, $currency, $id_carrier, $country);

		if (Validate::isFloat($shippingCost))
			return $shippingCost;
		else
			return false;
	}

	/**
	 * Get Shipping By barrier
	 * @param  int     $id_carrier ID Carrier
	 * @param  Country $country    Country
	 * @param  float   $subTotal   Total Products
	 * @param  boolean $use_tax    If use tax
	 * @return float               Shipping Costs
	 */
	private function getShippingCostByCarrier($products, Currency $currency, $id_carrier, Country $country, $use_tax = false)
	{
		$productLists = $products;

		$shippingCost = 0;

		$idZone = (int)$country->id_zone;

		$carrier = new Carrier($id_carrier, (int)$this->context->language->id);

		if ($this->ifCarrierDeliveryZone($carrier, $idZone))
			return false;

		$address = new Address();
		$address->id_country = (int)$country->id;
		$address->id_state = 0;
		$address->postcode = 0;

		if ($use_tax && !Tax::excludeTaxeOption())
			$carrier_tax = $carrier->getTaxesRate($address);

		$configuration = Configuration::getMultiple(array(
			'PS_SHIPPING_FREE_PRICE',
			'PS_SHIPPING_HANDLING',
			'PS_SHIPPING_METHOD',
			'PS_SHIPPING_FREE_WEIGHT'
		));

		// Get shipping cost using correct method
		if ($carrier->range_behavior)
		{

			if (($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT && !Carrier::checkDeliveryPriceByWeight($carrier->id, 0, (int)$idZone))
			|| ($shippingMethod == Carrier::SHIPPING_METHOD_PRICE && !Carrier::checkDeliveryPriceByPrice($carrier->id, $this->subTotalWt, $idZone, (int)$this->id_currency)
			))
				$shippingCost += 0;
			else
			{
				if ($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT)
					$shippingCost += $carrier->getDeliveryPriceByWeight(0, $idZone);
				else // by price
					$shippingCost += $carrier->getDeliveryPriceByPrice($this->subTotalWt, $idZone, (int)$currency->id);
			}
		}
		else
		{
			if ($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT)
				$shippingCost += $carrier->getDeliveryPriceByWeight(0, $idZone);
			else
				$shippingCost += $carrier->getDeliveryPriceByPrice($this->subTotalWt, $idZone, (int)$currency->id);
		}

		if (isset($configuration['PS_SHIPPING_HANDLING']) && $carrier->shipping_handling)
			$shippingCost += (float)$configuration['PS_SHIPPING_HANDLING'];

		foreach ($productLists as $p)
		{
			$product = new Product($p->product->code);
			$shippingCost += $product->additional_shipping_cost;
		}

		// Apply tax
		if ($use_tax && isset($carrier_tax))
			$shippingCost *= 1 + ($carrier_tax / 100);

		$shippingCost = (float)Tools::ps_round((float)$shippingCost, 2);

		return $shippingCost;
	}
	
	private function isCarrierInRange($carrier, $idZone)
	{

		if (!$carrier->range_behavior)
			return true;

		$shipping_method = $carrier->getShippingMethod();

		if ($shipping_method == Carrier::SHIPPING_METHOD_FREE)
			return true;

		$check_delivery_price_by_weight = Carrier::checkDeliveryPriceByWeight(
			(int)$id_carrier,
			null,
			$idZone
		);

		if ($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT && $check_delivery_price_by_weight)
			return true;

		$check_delivery_price_by_price = Carrier::checkDeliveryPriceByPrice(
			(int)$id_carrier,
			$this->subTotal,
			$idZone,
			(int)$this->id_currency
		);

		if ($shipping_method == Carrier::SHIPPING_METHOD_PRICE && $check_delivery_price_by_price)
			return true;

		return false;
	}

	/**
	 * Calculate tax (Shipping + Products)
	 * @return float Total tax
	 */
	protected function getTax($products, Currency $currency, $country)
	{

		$id_carrier = (int)Configuration::get('POWATAG_SHIPPING');

		if (!$country instanceof Country)
			$country = new Country($country);

		$tax = $this->subTax;
		$shippingCostWt = $this->getShippingCostByCarrier($products,  $currency, $id_carrier, $country, $this->subTotal, true);
		$tax += ($shippingCostWt - $this->shippingCost);

		return (float)Tools::ps_round($tax, 2);
	}

	/**
	 * Check if customer has tax
	 * @param  mix $customer Customer information (id|email|object)
	 * @return boolean       Tax enable
	 */
	protected function taxEnableByCustomer($customer)
	{
		
		if (!Validate::isLoadedObject($customer))
		{
			if (Validate::isEmail($customer))
				$customer = self::getCustomerByEmail($customer);
			else if (Validate::isInt($customer))
				$customer = new Customer((int)$customer);
		}

		return !Group::getPriceDisplayMethod((int)$customer->id_default_group);
	}

	protected static function getCustomerByEmail($email, $register = false, $lastName = null, $firstName = null, $emailAddress = null)
	{
		$customer = new Customer();
		$customer->getByEmail($email);

		if (!Validate::isLoadedObject($customer) && $register)
		{
			if (PowaTagAPI::apiLog())
				PowaTagLogs::initAPILog('Create customer', PowaTagLogs::IN_PROGRESS, 'Customer : '.$lastName . ' ' . $firstName);

			$customer->lastname  = $lastName;
			$customer->firstname = $firstName;
			$customer->email     = $emailAddress;
			$customer->setWsPasswd(substr($customer->lastname, 0, 1).$firstName);

			if (!$customer->save())
			{
				$this->error = "Impossible to save customer";

				if (PowaTagAPI::apiLog())
					PowaTagLogs::initAPILog('Create customer', PowaTagLogs::ERROR, $this->error);

				return false;
			}

			if (PowaTagAPI::apiLog())
				PowaTagLogs::initAPILog('Create customer', PowaTagLogs::SUCCESS, 'Customer ID : '.$customer->id);

		}

		return $customer;
	}

	protected function formatNumber($number, $precision = 0)
	{

		$number = Tools::ps_round($number, $precision);

		return number_format($number, 2, ".", "");
	}

	protected function getCombinationByEAN13($id_product, $ean13)
	{
		if (empty($ean13))
			return 0;

		$query = new DbQuery();
		$query->select('pa.id_product_attribute');
		$query->from('product_attribute', 'pa');
		$query->where('pa.ean13 = \''.pSQL($ean13).'\'');
		$query->where('pa.id_product = '.(int)$id_product);

		return Db::getInstance()->getValue($query);
	}

	protected function ifCarrierDeliveryZone($carrier, $idZone = false, $country = false)
	{
		if (!$carrier instanceof Carrier)
		{
			if (Validate::isInt($carrier))
				$carrier = new Carrier((int)$carrier);
			else
			{
				$this->error = "Error since load carrier";
				return false;
			}
		}

		if (!$idZone && !$country)
		{
			$this->error = "Thanks to fill country or id zone";
			return false;
		}
		else if (!$idZone && $country)
		{
			if (!$country instanceof Country)
			{
				if (Validate::isInt($country))
					$country = new Country($country);
				else
					$country = self::getCountryByCode($country);
			}

			if (!PowaTagValidate::countryEnable($country))
			{
				$this->error = "Country does not exists or not active";
				return false;
			}

			$idZone = (int)$country->id_zone;
		}

		if (!$this->isCarrierInRange($carrier, $idZone))
		{
			$this->error = "Carrier not delivery in : ".$country->name;
			return false;
		}

		if (!$carrier->active)
		{
			$this->error = "Carrier is not active : ".$carrier->name;
			return false;
		}

		if ($carrier->is_free == 1)
			return 0;

		$shippingMethod = $carrier->getShippingMethod();

		// Get only carriers that are compliant with shipping method
		if (($shippingMethod == Carrier::SHIPPING_METHOD_WEIGHT && $carrier->getMaxDeliveryPriceByWeight($idZone) === false)
			|| ($shippingMethod == Carrier::SHIPPING_METHOD_PRICE && $carrier->getMaxDeliveryPriceByPrice($idZone) === false))
		{
			$this->error = "Carrier not delivery for this shipping method in : ".$country->name;
			return false;
		}

		return true;
	}

	protected function convertToCurrency(&$amount, $currency, $toCurrency = true)
	{
		if ($currency->iso_code != $this->context->currency->iso_code)
			$amount = Tools::convertPrice($amount, $variantCurrency, $toCurrency);	
	}

}

?>

