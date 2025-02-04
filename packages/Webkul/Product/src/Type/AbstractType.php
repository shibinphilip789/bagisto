<?php

namespace Webkul\Product\Type;

use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductPriceIndexRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductInventoryRepository;
use Webkul\Product\Repositories\ProductVideoRepository;
use Webkul\Product\Repositories\ProductImageRepository;
use Webkul\Inventory\Repositories\InventorySourceRepository;
use Webkul\Product\Repositories\ProductCustomerGroupPriceRepository;
use Webkul\Product\DataTypes\CartItemValidationResult;
use Webkul\Tax\Repositories\TaxCategoryRepository;
use Webkul\Product\Facades\ProductImage;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartItem;
use Webkul\Tax\Helpers\Tax;

abstract class AbstractType
{
    /**
     * Product instance.
     *
     * @var \Webkul\Product\Models\Product
     */
    protected $product;

    /**
     * Is a composite product type.
     *
     * @var bool
     */
    protected $isComposite = false;

    /**
     * Is a stockable product type.
     *
     * @var bool
     */
    protected $isStockable = true;

    /**
     * Show quantity box.
     *
     * @var bool
     */
    protected $showQuantityBox = false;

    /**
     * Allow multiple qty.
     *
     * @var bool
     */
    protected $allowMultipleQty = true;

    /**
     * Is product have sufficient quantity.
     *
     * @var bool
     */
    protected $haveSufficientQuantity = true;

    /**
     * Product can be moved from wishlist to cart or not.
     *
     * @var bool
     */
    protected $canBeMovedFromWishlistToCart = true;

    /**
     * Products of this type can be copied in the admin backend.
     *
     * @var bool
     */
    protected $canBeCopied = true;

    /**
     * Has child products aka variants.
     *
     * @var bool
     */
    protected $hasVariants = false;

    /**
     * Product children price can be calculated or not.
     *
     * @var bool
     */
    protected $isChildrenCalculated = false;

    /**
     * product options.
     *
     * @var array
     */
    protected $productOptions = [];

    /**
     * Skip attribute for simple product type.
     *
     * @var array
     */
    protected $skipAttributes = [];

    /**
     * These blade files will be included in product edit page.
     *
     * @var array
     */
    protected $additionalViews = [];

    /**
     * Create a new product type instance.
     *
     * @param  \Webkul\Customer\Repositories\CustomerRepository  $customerRepository
     * @param  \Webkul\Attribute\Repositories\AttributeRepository  $attributeRepository
     * @param  \Webkul\Product\Repositories\ProductRepository   $productRepository
     * @param  \Webkul\Product\Repositories\ProductPriceIndexRepository   $productPriceIndexRepository
     * @param  \Webkul\Product\Repositories\ProductAttributeValueRepository  $attributeValueRepository
     * @param  \Webkul\Product\Repositories\ProductInventoryRepository  $productInventoryRepository
     * @param  \Webkul\Product\Repositories\ProductImageRepository  $productImageRepository
     * @param  \Webkul\Product\Repositories\ProductVideoRepository  $productVideoRepository
     * @return void
     */
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected AttributeRepository $attributeRepository,
        protected ProductRepository $productRepository,
        protected ProductPriceIndexRepository $productPriceIndexRepository,
        protected ProductAttributeValueRepository $attributeValueRepository,
        protected ProductInventoryRepository $productInventoryRepository,
        protected ProductImageRepository $productImageRepository,
        protected ProductVideoRepository $productVideoRepository
    )
    {
    }

    /**
     * Is the administrator able to copy products of this type in the admin backend?
     *
     * @return bool
     */
    public function canBeCopied(): bool
    {
        return $this->canBeCopied;
    }

    /**
     * Create product.
     *
     * @param  array  $data
     * @return \Webkul\Product\Contracts\Product
     */
    public function create(array $data)
    {
        return $this->productRepository->getModel()->create($data);
    }

    /**
     * Update product.
     *
     * @param  array  $data
     * @param  int  $id
     * @param  string  $attribute
     * @return \Webkul\Product\Contracts\Product
     */
    public function update(array $data, $id, $attribute = 'id')
    {
        $product = $this->productRepository->find($id);

        $product->update($data);

        $route = request()->route()?->getName();

        foreach ($product->attribute_family->custom_attributes as $attribute) {
            if (
                $attribute->type === 'boolean'
                && $route !== 'admin.catalog.products.mass_update'
            ) {
                $data[$attribute->code] = ! empty($data[$attribute->code]);
            }

            if (
                $attribute->type == 'multiselect'
                || $attribute->type == 'checkbox'
            ) {
                $data[$attribute->code] = isset($data[$attribute->code]) ? implode(',', $data[$attribute->code]) : null;
            }

            if (! isset($data[$attribute->code])) {
                continue;
            }

            if (
                $attribute->type === 'price'
                && empty($data[$attribute->code])
            ) {
                $data[$attribute->code] = null;
            }

            if (
                $attribute->type === 'date'
                && $data[$attribute->code] === ''
                && $route !== 'admin.catalog.products.mass_update'
            ) {
                $data[$attribute->code] = null;
            }

            if (
                $attribute->type === 'image'
                || $attribute->type === 'file'
            ) {
                $data[$attribute->code] = gettype($data[$attribute->code]) === 'object'
                    ? request()->file($attribute->code)->store('product/' . $product->id)
                    : null;
            }

            if ($attribute->value_per_channel) {
                if ($attribute->value_per_locale) {
                    $productAttributeValue = $product->attribute_values
                        ->where('channel', $attribute->value_per_channel ? $data['channel'] : null)
                        ->where('locale', $attribute->value_per_locale ? $data['locale'] : null)
                        ->where('attribute_id', $attribute->id)
                        ->first();
                } else {
                    $productAttributeValue = $product->attribute_values
                        ->where('channel', $attribute->value_per_channel ? $data['channel'] : null)
                        ->where('attribute_id', $attribute->id)
                        ->first();
                }
            } else {
                if ($attribute->value_per_locale) {
                    $productAttributeValue = $product->attribute_values
                        ->where('locale', $attribute->value_per_locale ? $data['locale'] : null)
                        ->where('attribute_id', $attribute->id)
                        ->first();
                } else {
                    $productAttributeValue = $product->attribute_values
                        ->where('attribute_id', $attribute->id)
                        ->first();
                }
            }

            if (! $productAttributeValue) {
                $this->attributeValueRepository->create([
                    'product_id'            => $product->id,
                    'attribute_id'          => $attribute->id,
                    $attribute->column_name => $data[$attribute->code],
                    'channel'               => $attribute->value_per_channel ? $data['channel'] : null,
                    'locale'                => $attribute->value_per_locale ? $data['locale'] : null,
                ]);
            } else {
                $productAttributeValue->update([$attribute->column_name => $data[$attribute->code]]);

                if (
                    $attribute->type == 'image'
                    || $attribute->type == 'file'
                ) {
                    Storage::delete($attributeValue->text_value);
                }
            }
        }

        if ($route == 'admin.catalog.products.mass_update') {
            return $product;
        }

        if (! isset($data['categories'])) {
            $data['categories'] = [];
        }

        $product->categories()->sync($data['categories']);

        $product->up_sells()->sync($data['up_sell'] ?? []);

        $product->cross_sells()->sync($data['cross_sell'] ?? []);

        $product->related_products()->sync($data['related_products'] ?? []);

        $this->productInventoryRepository->saveInventories($data, $product);

        $this->productImageRepository->uploadImages($data, $product);

        $this->productVideoRepository->uploadVideos($data, $product);

        app(ProductCustomerGroupPriceRepository::class)->saveCustomerGroupPrices(
            $data,
            $product
        );

        return $product;
    }

    /**
     * Specify type instance product.
     *
     * @param  \Webkul\Product\Contracts\Product  $product
     * @return \Webkul\Product\Type\AbstractType
     */
    public function setProduct($product)
    {
        $this->product = $product;

        return $this;
    }

    /**
     * @param  string  $code
     * @return mixed
     */
    public function getAttributeByCode($code)
    {
        return core()
            ->getSingletonInstance(AttributeRepository::class)
            ->getAttributeByCode($code);
    }

    /**
     * @param  integer  $id
     * @return mixed
     */
    public function getAttributeById($id)
    {
        return core()
            ->getSingletonInstance(AttributeRepository::class)
            ->getAttributeById($id);
    }

    /**
     * Returns children ids.
     *
     * @return array
     */
    public function getChildrenIds()
    {
        return [];
    }

    /**
     * Check if catalog rule can be applied.
     *
     * @return bool
     */
    public function priceRuleCanBeApplied()
    {
        return true;
    }

    /**
     * Return true if this product type is saleable.
     *
     * @return bool
     */
    public function isSaleable()
    {
        if (! $this->product->status) {
            return false;
        }

        if (
            is_callable(config('products.isSaleable')) &&
            call_user_func(config('products.isSaleable'), $this->product) === false
        ) {
            return false;
        }

        return true;
    }

    /**
     * Return true if this product can have inventory.
     *
     * @return bool
     */
    public function isStockable()
    {
        return $this->isStockable;
    }

    /**
     * Return true if this product can be composite.
     *
     * @return bool
     */
    public function isComposite()
    {
        return $this->isComposite;
    }

    /**
     * Return true if this product can have variants.
     *
     * @return bool
     */
    public function hasVariants()
    {
        return $this->hasVariants;
    }

    /**
     * Product children price can be calculated or not.
     *
     * @return bool
     */
    public function isChildrenCalculated()
    {
        return $this->isChildrenCalculated;
    }

    /**
     * Have sufficient quantity.
     *
     * @param  int  $qty
     * @return bool
     */
    public function haveSufficientQuantity(int $qty): bool
    {
        return $this->haveSufficientQuantity;
    }

    /**
     * Return true if this product can have inventory.
     *
     * @return bool
     */
    public function showQuantityBox()
    {
        return $this->showQuantityBox;
    }

    /**
     * Return true if more than one qty can be added to cart.
     *
     * @return bool
     */
    public function isMultipleQtyAllowed()
    {
        return $this->allowMultipleQty;
    }

    /**
     * Is item have quantity.
     *
     * @param  \Webkul\Checkout\Contracts\CartItem  $cartItem
     * @return bool
     */
    public function isItemHaveQuantity($cartItem)
    {
        return $cartItem->product->getTypeInstance()->haveSufficientQuantity($cartItem->quantity);
    }

    /**
     * Total quantity.
     *
     * @return int
     */
    public function totalQuantity()
    {
        $total = 0;

        $channelInventorySourceIds = app(InventorySourceRepository::class)->getChannelInventorySourceIds();

        $productInventories = $this->productInventoryRepository->checkInLoadedProductInventories($this->product);

        foreach ($productInventories as $inventory) {
            if (is_numeric($channelInventorySourceIds->search($inventory->inventory_source_id))) {
                $total += $inventory->qty;
            }
        }

        $orderedInventory = $this->product->ordered_inventories
            ->where('channel_id', core()->getCurrentChannel()->id)->first();

        if ($orderedInventory) {
            $total -= $orderedInventory->qty;
        }

        return $total;
    }

    /**
     * Return true if item can be moved to cart from wishlist.
     *
     * @param  \Webkul\Checkout\Contracts\CartItem  $item
     * @return bool
     */
    public function canBeMovedFromWishlistToCart($item)
    {
        return $this->canBeMovedFromWishlistToCart;
    }

    /**
     * Retrieve product attributes.
     *
     * @param  \Webkul\Attribute\Contracts\Group  $group
     * @param  bool  $skipSuperAttribute
     * @return \Illuminate\Support\Collection
     */
    public function getEditableAttributes($group = null, $skipSuperAttribute = true)
    {
        if ($skipSuperAttribute) {
            $this->skipAttributes = array_merge(
                $this->product->super_attributes->pluck('code')->toArray(),
                $this->skipAttributes
            );
        }

        if (! $group) {
            return $this->product->attribute_family->custom_attributes()->whereNotIn(
                'attributes.code',
                $this->skipAttributes
            )->get();
        }

        return $group->custom_attributes()->whereNotIn('code', $this->skipAttributes)->get();
    }

    /**
     * Returns additional views.
     *
     * @return array
     */
    public function getAdditionalViews()
    {
        return $this->additionalViews;
    }

    /**
     * Returns validation rules.
     *
     * @return array
     */
    public function getTypeValidationRules()
    {
        return [];
    }

    /**
     * Get product minimal price.
     *
     * @param  int  $qty
     * @return float
     */
    public function getMinimalPrice()
    {
        if (! $priceIndex = $this->getPriceIndex()) {
            return $this->product->price;
        }

        return $priceIndex->min_price;
    }

    /**
     * Get product regular minimal price.
     *
     * @return float
     */
    public function getRegularMinimalPrice()
    {
        if (! $priceIndex = $this->getPriceIndex()) {
            return $this->product->price;
        }

        return $priceIndex->regular_min_price;
    }

    /**
     * Get product maximum price.
     *
     * @return float
     */
    public function getMaximumPrice()
    {
        if (! $priceIndex = $this->getPriceIndex()) {
            return $this->product->price;
        }

        return $priceIndex->max_price;
    }

    /**
     * Get product regular minimal price.
     *
     * @return float
     */
    public function getRegularMaximumPrice()
    {
        if (! $priceIndex = $this->getPriceIndex()) {
            return $this->product->price;
        }

        return $priceIndex->regular_max_price;
    }

    /**
     * Get product minimal price.
     *
     * @param  int  $qty
     * @return float
     */
    public function getFinalPrice($qty = null)
    {
        if (
            is_null($qty)
            || $qty == 1
        ) {
            return $this->getMinimalPrice();
        }

        $customerGroup = $this->customerRepository->getCurrentGroup();

        $indexer = $this->getPriceIndexer()
            ->setCustomerGroup($customerGroup)
            ->setProduct($this->product);

        return $indexer->getMinimalPrice($qty);
    }

    /**
     * Have special price.
     *
     * @return \Webkul\Product\Contracts\ProductPriceIndex
     */
    public function getPriceIndex()
    {
        static $indices = [];

        if (array_key_exists($this->product->id, $indices)) {
            return $indices[$this->product->id];
        }

        $customerGroup = $this->customerRepository->getCurrentGroup();

        $indices[$this->product->id] = $this->product
            ->price_indices
            ->where('customer_group_id', $customerGroup->id)
            ->first();

        return $indices[$this->product->id];
    }

    /**
     * Have special price.
     *
     * @param  int  $qty
     * @return bool
     */
    public function haveDiscount($qty = null)
    {
        if (! $priceIndex = $this->getPriceIndex()) {
            return false;
        }

        return $priceIndex->min_price != $this->product->regular_min_price;
    }

    /**
     * Get product prices.
     *
     * @return array
     */
    public function getProductPrices()
    {
        return [
            'regular_price' => [
                'price'           => core()->convertPrice($this->evaluatePrice($regularPrice = $this->product->price)),
                'formatted_price' => core()->currency($this->evaluatePrice($regularPrice)),
            ],
            'final_price'   => [
                'price'           => core()->convertPrice($this->evaluatePrice($minimalPrice = $this->getMinimalPrice())),
                'formatted_price' => core()->currency($this->evaluatePrice($minimalPrice)),
            ],
        ];
    }

    /**
     * Get product price html.
     *
     * @return string
     */
    public function getPriceHtml()
    {
        $minPrice = $this->getMinimalPrice();

        if ($minPrice < $this->product->price) {
            $html = '<div class="sticker sale">' . trans('shop::app.products.sale') . '</div>'
            . '<span class="regular-price">' . core()->currency($this->evaluatePrice($this->product->price)) . '</span>'
            . '<span class="special-price">' . core()->currency($this->evaluatePrice($minPrice)) . '</span>';
        } else {
            $html = '<span>' . core()->currency($this->evaluatePrice($this->product->price)) . '</span>';
        }

        return $html;
    }

    /**
     * Get inclusive tax rates.
     *
     * @param  float  $totalPrice
     * @return float
     */
    public function getTaxInclusiveRate($totalPrice)
    {
        /* this is added for future purpose like if shipping tax also added then case is needed */
        $address = null;

        if ($taxCategory = $this->getTaxCategory()) {
            if (
                $address === null
                && auth()->guard('customer')->check()
            ) {
                $address = auth()->guard('customer')->user()->addresses->where('default_address', 1)->first();
            }

            if ($address === null) {
                $address = Tax::getDefaultAddress();
            }

            Tax::isTaxApplicableInCurrentAddress($taxCategory, $address, function ($rate) use (&$totalPrice) {
                $totalPrice = round($totalPrice, 4) + round(($totalPrice * $rate->tax_rate) / 100, 4);
            });
        }

        return $totalPrice;
    }

    /**
     * Get tax category.
     *
     * @return \Webkul\Tax\Models\TaxCategory
     */
    public function getTaxCategory()
    {
        $taxCategoryId = $this->product->parent
            ? $this->product->parent->tax_category_id
            : $this->product->tax_category_id;

        return app(TaxCategoryRepository::class)->find($taxCategoryId);
    }

    /**
     * Evaluate price.
     *
     * @return array
     */
    public function evaluatePrice($price)
    {
        $roundedOffPrice = round($price, 2);

        return Tax::isTaxInclusive()
            ? $this->getTaxInclusiveRate($roundedOffPrice)
            : $roundedOffPrice;
    }

    /**
     * Add product. Returns error message if can't prepare product.
     *
     * @param  array  $data
     * @return array
     */
    public function prepareForCart($data)
    {
        $data['quantity'] = $this->handleQuantity((int) $data['quantity']);

        $data = $this->getQtyRequest($data);

        if (! $this->haveSufficientQuantity($data['quantity'])) {
            return trans('shop::app.checkout.cart.quantity.inventory_warning');
        }

        $price = $this->getFinalPrice();

        $products = [
            [
                'product_id'        => $this->product->id,
                'sku'               => $this->product->sku,
                'quantity'          => $data['quantity'],
                'name'              => $this->product->name,
                'price'             => $convertedPrice = core()->convertPrice($price),
                'base_price'        => $price,
                'total'             => $convertedPrice * $data['quantity'],
                'base_total'        => $price * $data['quantity'],
                'weight'            => $this->product->weight ?? 0,
                'total_weight'      => ($this->product->weight ?? 0) * $data['quantity'],
                'base_total_weight' => ($this->product->weight ?? 0) * $data['quantity'],
                'type'              => $this->product->type,
                'additional'        => $this->getAdditionalOptions($data),
            ],
        ];

        return $products;
    }

    /**
     * Handle quantity.
     *
     * @param  int  $quantity
     * @return int
     */
    public function handleQuantity(int $quantity): int
    {
        return $quantity ?: 1;
    }

    /**
     * Get request quantity.
     *
     * @param  array  $data
     * @return array
     */
    public function getQtyRequest($data)
    {
        if ($item = Cart::getItemByProduct(['additional' => $data])) {
            $data['quantity'] += $item->quantity;
        }

        return $data;
    }

    /**
     * Compare options.
     *
     * @param  array  $options1
     * @param  array  $options2
     * @return bool
     */
    public function compareOptions($options1, $options2)
    {
        if ($this->product->id != $options2['product_id']) {
            return false;
        } else {
            if (
                isset($options1['parent_id'])
                && isset($options2['parent_id'])
            ) {
                return $options1['parent_id'] == $options2['parent_id'];
            } elseif (
                isset($options1['parent_id'])
                && ! isset($options2['parent_id'])
            ) {
                return false;
            } elseif (
                isset($options2['parent_id'])
                && ! isset($options1['parent_id'])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns additional information for items.
     *
     * @param  array  $data
     * @return array
     */
    public function getAdditionalOptions($data)
    {
        return $data;
    }

    /**
     * Get actual ordered item.
     *
     * @param  \Webkul\Checkout\Contracts\CartItem $item
     * @return \Webkul\Checkout\Contracts\CartItem|\Webkul\Sales\Contracts\OrderItem|\Webkul\Sales\Contracts\InvoiceItem|\Webkul\Sales\Contracts\ShipmentItem|\Webkul\Customer\Contracts\Wishlist
     */
    public function getOrderedItem($item)
    {
        return $item;
    }

    /**
     * Get product base image.
     *
     * @param  \Webkul\Customer\Contracts\CartItem|\Webkul\Checkout\Contracts\CartItem $item
     * @return array
     */
    public function getBaseImage($item)
    {
        return ProductImage::getProductBaseImage($item->product);
    }

    /**
     * Validate cart item product price and other things.
     *
     * @param  \Webkul\Checkout\Models\CartItem  $item
     * @return \Webkul\Product\DataTypes\CartItemValidationResult
     */
    public function validateCartItem(CartItem $item): CartItemValidationResult
    {
        $result = new CartItemValidationResult();

        if ($this->isCartItemInactive($item)) {
            $result->itemIsInactive();

            return $result;
        }

        $price = round($item->product->getTypeInstance()->getFinalPrice($item->quantity), 4);

        if ($price == $item->base_price) {
            return $result;
        }

        $item->base_price = $price;
        $item->price = core()->convertPrice($price);

        $item->base_total = $price * $item->quantity;
        $item->total = core()->convertPrice($price * $item->quantity);

        $item->save();

        return $result;
    }

    /**
     * Get product options.
     *
     * @return array
     */
    public function getProductOptions()
    {
        return $this->productOptions;
    }

    /**
     * Returns true, if cart item is inactive.
     *
     * @param  \Webkul\Checkout\Contracts\CartItem  $item
     * @return bool
     */
    public function isCartItemInactive(\Webkul\Checkout\Contracts\CartItem $item): bool
    {
        if (! $item->product->status) {
            return true;
        }

        switch ($item->product->type) {
            case 'bundle':
                foreach ($item->children as $child) {
                    if (! $child->product->status) {
                        return true;
                    }
                }
                break;

            case 'configurable':
                if (
                    $item->child
                    && ! $item->child->product->status
                ) {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Get more offers for customer group pricing.
     *
     * @return array
     */
    public function getCustomerGroupPricingOffers()
    {
        $offerLines = [];
        
        $customerGroup = $this->customerRepository->getCurrentGroup();

        $customerGroupPrices = $this->product->customer_group_prices()->where(function ($query) use ($customerGroup) {
                $query->where('customer_group_id', $customerGroup->id)
                    ->orWhereNull('customer_group_id');
            })
            ->where('qty', '>', 1)
            ->groupBy('qty')
            ->orderBy('qty')
            ->get();

        foreach ($customerGroupPrices as $customerGroupPrice) {
            if (
                ! is_null($this->product->special_price)
                && $customerGroupPrice->value >= $this->product->special_price
            ) {
                continue;
            }

            array_push($offerLines, $this->getOfferLines($customerGroupPrice));
        }

        return $offerLines;
    }

    /**
     * Get offers lines.
     *
     * @param  object  $customerGroupPrice
     * @return array
     */
    public function getOfferLines($customerGroupPrice)
    {
        $price = $this->getCustomerGroupPrice($this->product, $customerGroupPrice->qty);

        $discount = number_format((($this->product->price - $price) * 100) / ($this->product->price), 2);

        $offerLines = trans('shop::app.products.offers', [
            'qty'      => $customerGroupPrice->qty,
            'price'    => core()->currency($price),
            'discount' => $discount,
        ]);

        return $offerLines;
    }

    /**
     * Get product group price.
     *
     * @return float
     */
    public function getCustomerGroupPrice($product, $qty)
    {
        if (is_null($qty)) {
            $qty = 1;
        }

        $customerGroup = $this->customerRepository->getCurrentGroup();

        $customerGroupPrices = app(ProductCustomerGroupPriceRepository::class)->checkInLoadedCustomerGroupPrice($product, $customerGroup->id);

        if ($customerGroupPrices->isEmpty()) {
            return $product->price;
        }

        $lastQty = 1;

        $lastPrice = $product->price;

        $lastCustomerGroupId = null;

        foreach ($customerGroupPrices as $customerGroupPrice) {
            if ($qty < $customerGroupPrice->qty) {
                continue;
            }

            if ($customerGroupPrice->qty < $lastQty) {
                continue;
            }

            if (
                $customerGroupPrice->qty == $lastQty
                && ! empty($lastCustomerGroupId)
                && empty($customerGroupPrice->customer_group_id)
            ) {
                continue;
            }

            if ($customerGroupPrice->value_type == 'discount') {
                if (
                    $customerGroupPrice->value >= 0
                    && $customerGroupPrice->value <= 100
                ) {
                    $lastPrice = $product->price - ($product->price * $customerGroupPrice->value) / 100;

                    $lastQty = $customerGroupPrice->qty;

                    $lastCustomerGroupId = $customerGroupPrice->customer_group_id;
                }
            } else {
                if (
                    $customerGroupPrice->value >= 0
                    && $customerGroupPrice->value < $lastPrice
                ) {
                    $lastPrice = $customerGroupPrice->value;

                    $lastQty = $customerGroupPrice->qty;

                    $lastCustomerGroupId = $customerGroupPrice->customer_group_id;
                }
            }
        }

        return $lastPrice;
    }

    /**
     * Check in loaded saleable.
     *
     * @return object
     */
    public function checkInLoadedSaleableChecks($product, $callback)
    {
        static $loadedSaleableChecks = [];

        if (array_key_exists($product->id, $loadedSaleableChecks)) {
            return $loadedSaleableChecks[$product->id];
        }

        return $loadedSaleableChecks[$product->id] = $callback($product);
    }
}
