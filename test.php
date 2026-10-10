<?php

// Nigel's test script for Printful->KahunaCart

define('PRINTFUL_API', 'https://api.printful.com/') ;
define('PRINTFUL_KEY', 'wcmS4GTeYwDUzLw7PeV9yebXeS1XCF4TfjydQRiF') ;

$stores = pf_api('stores') ;

if (($stores == false) || ($stores->code != 200)) {
	die("Error getting stores") ;
}

if (count($stores->result) != 1) {
	print("Warning; this may be an account key, or there is no store created") ;
} else {
	printf("Store name is %s\n", $stores->result[0]->name) ;
}

$products = pf_api('store/products') ;

if (($products == false) || ($products->code != 200)) {
	die("Error getting products") ;
}

if (count($products->result) == 0) {
	die("No products found in store") ;
}

foreach ($products->result as $product) {
	printf("Product: %s\n", $product->name) ;

	$sizes = array() ;
	$colours = array() ;

	$variants = pf_api('store/products', $product->id);
	if (count($variants->result->sync_variants) == 0) {
		// no variants
		printf("Skipping this product\n") ;
		break ;
	}

	foreach ($variants->result->sync_variants as $variant) {
		if (($variant->is_ignored) || (! $variant->synced)) {
			break ;
		}
		printf("%s : variant id = %d, size = %s, colour = %s, SKU = %s\n", $variant->name, $variant->id, $variant->size, $variant->color, $variant->sku) ;
		$sizes[$variant->size] = true ;
		$colours[$variant->color] = true ;

		// iterate over the files
		foreach ($variant->files as $file) {
			if (($file->type == 'preview') && ($file->status == 'ok')) {
				printf("Image URL is %s\n", $file->preview_url) ;
			}
		}
	}


	printf("Sizes found: %d Colours found: %d\n", count($sizes), count($colours)) ;
}

function pf_api($endpoint, $id = null)
{
	$api = curl_init() ;

	curl_setopt_array($api, [
	  CURLOPT_URL => PRINTFUL_API . $endpoint . (($id == null) ? '' : '/' . $id) ,
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_ENCODING => "",
	  CURLOPT_MAXREDIRS => 10,
	  CURLOPT_TIMEOUT => 30,
	  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	//  CURLOPT_POSTFIELDS => json_encode($printful_order, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES),
	  CURLOPT_CUSTOMREQUEST => "GET",
	  CURLOPT_HTTPHEADER => [
		"Content-Type: application/json;charset=utf-8",
		"Authorization: Bearer " . PRINTFUL_KEY
	  ],
	]);

	$response = curl_exec($api) ;

	if ($response === false) {
		// curl error
		$grav['log']->error('Printful: Unsuccessful call to ' . $endpoint) ;
		return false ;
	}

	$printful_response = json_decode($response) ;
	return $printful_response ;
}
