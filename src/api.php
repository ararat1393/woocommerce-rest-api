<?php 

use Automattic\WooCommerce\Client AS WooClient;
use Automattic\WooCommerce\HttpClient\HttpClientException;

use GuzzleHttp\Client AS GuzzleClient;
use Carbon\Carbon;

class WooCommerce {

	protected $woocommerce;


	protected $countForUpdate = 0;
	protected $countForCreate = 0;
	protected $countForRemoveProduct = 0;
	protected $countForRemoveAttr = 0;
	protected $countForRemoveCat = 0;
	protected $timeStart;

	protected $currentProductCategories = [];
	protected $currentProductAttributes = [];
	protected $currentProductAttrTerms = [];
	protected $SKU = [];
    
	protected $databaseProducts = [];

	protected $wooProducts = [];
	protected $wooCategories = [];
	protected $wooAttributes = [];

	public function __construct(){
		$this->timeStart = Carbon::now()->toDateTimeString();
		$this->setLog();
		$this->conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD,DB_NAME);
		$this->woocommerce = new WooClient( WOO_SITE_URL,WOO_CONSUMER_KEY,WOO_CONSUMER_SECRET,WOO_OPTIONS);
		$this->getProductFromDB();
		$this->getWooCommerceCategories();
		$this->getWooCommerceAttributes();
		$this->getWooCommerceProductsAndFilter();
	}

	public function getProductFromDB(){

		if ($this->conn->connect_error) {
			die("Connection failed: " . $this->conn->connect_error);
		} 

		$sql = "SELECT * FROM gs_product_data_agg";
		$result = $this->conn->query($sql);

		$currentUTCTime  = Carbon::createFromFormat('Y-m-d H:i:s', $this->timeStart);

		if ($result->num_rows > 0) {
			while($row = $result->fetch_assoc()){
				$timestampUpdateCountprice =  Carbon::createFromFormat('Y-m-d H:i:s', $row['timestamp_update_countprice']);
				$minute = (int)$currentUTCTime->diffInMinutes($timestampUpdateCountprice);
				if( $minute <= 60 ){
					$this->databaseProducts[] = $row;
					$this->SKU[] = $row['sku'];
				}
			}
		}else{
			$this->setLog(['message'=>'Not found new products to update or create']);
		}

		$this->conn->close();
	}

	public function getWooCommerceProductsAndFilter( $data = [] ){

		$currentWooProducts = [];$per_page = 100;$offset   = 0;
		try{

			while ( $products = $this->woocommerce->get('products',['per_page'=>$per_page,'offset'=>$offset] ) ) {
				foreach ($products as $product) {
					$currentWooProducts[] = $product;
				}
				$offset += $per_page;
			};

		} catch (HttpClientException $e) {

			$this->setLog(['place' =>'get_product','warning'=>$e->getMessage()]);
		}
		foreach ($currentWooProducts as $key => $product){
			if( in_array($product->sku, $this->SKU) ){
				$this->wooProducts[] = $product; 
			}else{
				try{
					$this->woocommerce->delete("products/".$product->id;, ['force' => true]);
					$this->countForRemoveProduct++;
				} catch (HttpClientException $e) {
					$this->setLog(['place' =>'delete_product','warning'=>$e->getMessage()]);
				}
			}
		}
	}

	public function getWooCommerceCategories( $data = [] ){
		$categories = [];$per_page = 100;$offset = 0;
		try{
			while ($categories = $this->woocommerce->get('products/categories',['per_page'=>$per_page,'offset'=>$offset])) {
				foreach ($categories as $category) {
					$this->wooCategories[] = $category;
				}

				$offset += $per_page;
			}
		}catch (HttpClientException $e) {
			$this->setLog(['place' =>'get_products_categories','warning'=>$e->getMessage()]);
		}
	}

	public function getWooCommerceAttributes( $data = [] ){
		try{
			$this->wooAttributes = $this->woocommerce->get('products/attributes',['per_page'=>1000,'offset'=>0]);
		} catch (HttpClientException $e) {
			$this->setLog(['place' =>'get_products_attributes','warning'=>$e->getMessage()]);
		}
		foreach ($this->wooAttributes as &$wooAttribute) {
			$wooAttribute->terms = $this->getWooCommerceAttributeTerms( $wooAttribute->id );
		}
	}

	public function getWooCommerceAttributeTerms( $wooAttributeId ){

		$per_page = 100;$offset = 0;$allTerms = [];

		try {

			while ( $terms = $this->woocommerce->get("products/attributes/$wooAttributeId/terms",['per_page'=>$per_page,'offset'=>$offset]) ) {

				foreach ($terms as $term) {
					$allTerms[] = $term;
				}
			
				$offset += $per_page;
			}

		} catch (HttpClientException $e) {
			$this->setLog(['place' =>'get_products_attributes_terms','warning'=>$e->getMessage()]);
		}

		return $allTerms;
	}

	public function run(){

		if( !empty( $this->databaseProducts ) ){

			foreach ($this->databaseProducts as $product) {

				$this->currentProductAttributes = [];
				$this->currentProductCategories = [];

				if( isset($product['attributes']) && !empty($product['attributes']) ){
					$this->checkAttributeInWooCommerce($product['attributes']);
				}
				if( isset($product['categories']) && !empty($product['categories'])){
					$this->checkCategoryInWooCommerce($product['categories']);
				}

				$this->checkProductInWooCommerce( $product );
			}

			$this->stop();
			exit();
		}else{
			$this->setLog(['message'=>'Not found new products to update or create']);
			exit();
		}
	}

	public function checkProductInWooCommerce( $product ){
		
		$productSku = $product['sku'];

		$productId = $this->findProductSkuInWooProducts($productSku);
		$parametrs = $this->createProductParametrs( $product );
		
		// If there is a product ID Then update Product Data
		if( $productId ){
			try {

				$this->woocommerce->put('products/'.$productId, $parametrs);
				$this->countForUpdate++;

			} catch (HttpClientException $e) {

				$this->setLog(['place' =>'update_product','warning'=>$e->getMessage()]);

			}

		}else{
			// else create product
			try {

				$product = $this->woocommerce->post('products', $parametrs);
				$this->countForCreate++;
				$this->wooProducts[] = $product;
			} catch (HttpClientException $e) {
				$this->setLog(['place' =>'create_product','warning'=>$e->getMessage()]);

			}
		}
	}

	public function checkAttributeInWooCommerce( $attributes ){

		$attributes  = json_decode($attributes);
		foreach ($attributes as $attribute) {
			$this->addOrUpdateAttribute($attribute);
		}

	}

	public function checkCategoryInWooCommerce( $categories ){
		
		$categories = explode("|",$categories);
		foreach($categories as $category){
			$this->addOrUpdateCategory( $category );
		}
	}



	public function addOrUpdateAttribute( $attribute ){
		
		$issetAttribute = false;
		$breakID = 0;
		for($i = 0;$i<count($this->wooAttributes);$i++){
			if( $this->wooAttributes[$i]->name == $attribute->name){
				$issetAttribute = true;
				$breakID = $i;
				break;
			}
		}
		if( !$issetAttribute ){

			$parametrs = array('name' => $attribute->name,'slug'=>$attribute->slug,'order_by' => 'menu_order','has_archives' => true);

			$attr = $this->woocommerce->post('products/attributes', $parametrs);
			$term = $this->woocommerce->post('products/attributes/'.$attr->id.'/terms', array('name'=>$attribute->_term));
			$this->currentProductAttributes[] = $attr;
			$this->currentProductAttrTerms[$attr->id] = $term;
			$attr->term = array($term);
			$this->wooAttributes[] = $attr;

		}else{

				try {

					$hasTermInAttribute = false;
					$term  = "";
					$id    = $this->wooAttributes[$breakID]->id;
					$terms = $this->wooAttributes[$breakID]->terms;

					for($i = 0; $i < count($terms);$i++){
						if($terms[$i]->name == $attribute->_term){
							$hasTermInAttribute = true;
							$term = $terms[$i];
							break;
						}
					}

					if( !$hasTermInAttribute ){
						$term = $this->woocommerce->post("products/attributes/$id/terms", array('name'=>$attribute->_term));
					}
					$this->currentProductAttrTerms[$id] = $term;

					$issetAttribute = false;
					for($k = 0; $k<count($this->currentProductAttributes); $k++) {
						
						if($this->currentProductAttributes[$k]->id == $this->wooAttributes[$breakID]->id){
							$issetAttribute = true;
						}
					}
					if( !$issetAttribute ){
						$this->currentProductAttributes[] = $this->wooAttributes[$breakID];
					}

				} catch (HttpClientException $e) {
					$this->setLog(['place' =>'create_attribute_terms','warning'=>$e->getMessage()]);
				}
			}

		}




	public function addOrUpdateCategory($category){
		
		$categoryList = explode('>',$category);
		$breakID = $parentID = 0;

		for( $i = 0; $i < count($categoryList);$i++){
			$hasCategory = false; 

			for( $j = 0;$j<count($this->wooCategories);$j++ ){
				if($this->wooCategories[$j]->name == $categoryList[$i] ){
					$hasCategory = true;
					$breakID = $j;
					break;
				}
			}

			if(!$hasCategory){
				$data = [
				    'name' => $categoryList[$i],
				    'slug' => strtolower( str_replace(" ",'_',$categoryList[$i]) ),
				    'parent' => $parentID, 
				    'image' => [
				        'src' => ''
				    ]
				];
				$response = $this->woocommerce->post('products/categories', $data);
				$parentID = $response->id;
				$this->currentProductCategories[] = $response;
				$this->wooCategories[] = $response;
			}else{

				$issetCategory = false;
				$parentID = $this->wooCategories[$breakID]->id;

				for($k = 0; $k<count($this->currentProductCategories); $k++) {
					if($this->currentProductCategories[$k]->id == $this->wooCategories[$breakID]->id){
						$issetCategory = true;
					}
				}

				if( !$issetCategory ){
					$this->currentProductCategories[] = $this->wooCategories[$breakID];
				}
			}
		}
	}

	public function findProductSkuInWooProducts($sku){

		$productId = 0; 
		for($i = 0; $i<count($this->wooProducts);$i++){
			if($this->wooProducts[$i]->sku == $sku ){
				$productId = $this->wooProducts[$i]->id;
				break;
			}
		}

		return $productId;
	}

	public function createProductParametrs( $product ){

		$imagesList = (empty($product['images']) ? [] : explode('|' ,$product['images']));
		$images             = [];
		$meta_data          = [];
		$grouped_products   = [];
		$default_attributes = [];

		$attributes         = [];
		$categories         = [];

		foreach ($imagesList as $key => $link) {
			$images[] = array('src' => $link);
		}

		foreach ($this->currentProductCategories as $key => $category) {
			$categories[] = array('id'=>$category->id);
		}

		foreach ($this->currentProductAttributes as $key => $attr) {
			$attributes[] = [
				'id'      => $attr->id,
				'options' => $this->currentProductAttrTerms[$attr->id]->name
			];
		}
		return [
			'name' => $product['name'],
			'type' => strtolower($product['type']),
			'status' => $product['status'],
			'catalog_visibility' => $product['catalog_visibility'],
			'description' => $product['description'],
			'short_description' => $product['short_description'],
			'sku'=> $product['sku'],
			'regular_price'=> (string)$product['regular_price'],
			'sale_price' => (string)$product['sale_price'],
			'date_on_sale_from' => $product['date_on_sale_from'],
			'date_on_sale_from_gmt' =>$product['date_on_sale_from_gmt'],
			'date_on_sale_to' => $product['date_on_sale_to'],
			'date_on_sale_to_gmt' => $product['date_on_sale_to_gmt'],
			'external_url' => $product['external_url'],
			'button_text' => $product['button_text'],
			'tax_status' => $product['tax_status'],
			'tax_class' => $product['tax_class'],
			'manage_stock' => $product['manage_stock'],
			'stock_quantity' => $product['stock_quantity'],
			'stock_status' => $product['stock_status'],
			'backorders' => $product['backorders'],
			'sold_individually' =>$product['sold_individually'],
			'weight' => (string)$product['weight'],

			'dimensions' =>[
				'length' => (string)$product['dim_ship_length'],
				'width'  => (string)$product['dim_ship_width'],
				'height' => (string)$product['dim_ship_height']
			],

			'reviews_allowed' => $product['reviews_allowed'],
			'upsell_ids' => $product['upsell_ids'],
			'cross_sell_ids' => $product['cross_sell_ids'],
			'parent_id' => $product['parent_id'] ? (int)$product['parent_id'] : null,
			'purchase_note' => $product['purchase_note'],
			'categories'=> $categories,
			'tags' => $product['tags'],
			'images' => $images,
			'attributes' => $attributes,
			'default_attributes' => $default_attributes,
			'grouped_products' => $grouped_products,
			'menu_order' => $product['menu_order'] ? (int)$product['menu_order'] : null,
			'meta_data' => [
				[
					"key"   => 'prop65',
					"value" => $product['prop_65']
				]
			 ],
			'is_active' => $product['is_active'],
			// 'price_dealer' => $product['price_dealer'],
			// 'price_dealer_onsale'=> $product['price_dealer_onsale'],
			// 'price_retail' => $product['price_retail'],
			// 'price_percent' => $product['price_percent'],
			// 'price_stocking' => $product['price_stocking'],
			// 'price_stock_onsale' => $product['price_stock_onsale'],
			// 'price_map' => $product['price_map'],
			// 'price_msrp' =>$product['price_msrp'],
			// 'price_mrp' => $product['price_mrp']
		];
	}

	// public function stop(){
	// 	$timeEnd = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now()->toDateTimeString());
	// 	echo 'The code has been working for '.(int)$timeEnd->diffInSeconds($this->timeStart).'s<br>Updated Products '.$this->countForUpdate ."<br>Created Products ".$this->countForCreate."<br>Deleted Products ".$this->countForRemoveProduct;
	// 	die(); 
	// }
	public function stop(){
		$timeEnd = Carbon::createFromFormat('Y-m-d H:i:s', Carbon::now()->toDateTimeString());
		$data = [
			'The code has been working for ' => (int)$timeEnd->diffInSeconds($this->timeStart),
			'Updated Products' => $this->countForUpdate,
			'Created Products' => $this->countForCreate,
			'Deleted Products' => $this->countForRemoveProduct
		];
		$this->setLog($data);
	}

	public function setLog($data = null){

		$log_filename = $_SERVER['DOCUMENT_ROOT']."./log";
		if (!file_exists($log_filename))

		{
		// create directory/folder uploads.
			mkdir($log_filename, 0777, true);
		}

		$log_file_data = $log_filename.'/log_debug.log';
		if(is_null($data)){

			$new_log_create_time ="\n"."\n"."\n". Carbon::now()->toDateTimeString();
			file_put_contents($log_file_data, $new_log_create_time . "\n", FILE_APPEND);
		}else{

			file_put_contents($log_file_data, json_encode($data) . "\n", FILE_APPEND);
		}
		// $log_file_data = $log_filename.'/log_' .time(). '.log';
		// exit();
	}
}


$woo = new WooCommerce();
$woo->run();