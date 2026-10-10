<?php

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Grav\Common\Grav ;
use Grav\Common\Utils ;
use Grav\Plugin\PrintfulPlugin ;
use RocketTheme\Toolbox\File\File;

/**
 * Class CatalogCommand
 *
 * @package Grav\Plugin\Console
 */
class CatalogCommand extends ConsoleCommand
{
	/**
	 * @var array
	 */
	protected $options = [];

	/**
	 * Generate a YAML catalog from the Printful Store
	 */
	protected function configure()
	{
		$this
			->setName("catalog")
			->setDescription("Imports products from a Printful store")
			->addOption(
				'key',
				'k',
				InputOption::VALUE_REQUIRED,
				'Override Printful store API key configured in settings'
			)
			->addOption(
				'brand',
				'b',
				InputOption::VALUE_REQUIRED,
				'Set the product brand (default = Printful)'
			)
			->addOption(
				'manufacturer',
				'm',
				InputOption::VALUE_REQUIRED,
				'Set the product manufacturer'
			)
			->addOption(
				'publish',
				'p',
				InputOption::VALUE_NONE,
				'Mark products as published in YAML catalog'
			)
			->addOption(
				'descriptions',
				'd',
				InputOption::VALUE_NONE,
				'Use Printful product descriptions'
			)
			->addOption(
				'types',
				't',
				InputOption::VALUE_NONE,
				'Use Printful product type name as summary'
			)
			->addOption(
				'categories',
				'c',
				InputOption::VALUE_NONE,
				'Use Printful product categories'
			)
			->addOption(
				'all',
				'a',
				InputOption::VALUE_NONE,
				'Use all data from Printful (descriptions, types, categories, images)'
			)
			->addOption(
				'images',
				'i',
				InputOption::VALUE_NONE,
				'Create image entries during catalog creation (may be slow)'
			)
			->addOption(
				'sizes',
				'z',
				InputOption::VALUE_REQUIRED,
				'Generate sizing tables. Set units in admin and specify attribute name here'
			)
			->addOption(
				'save',
				's',
				InputOption::VALUE_NONE,
				'Save YAML catalog (and images with --images) to a local file'
			)

			->setHelp('The <info>catalog</info> command reads products from a Printful store and creates a KahunaCart YAML catalog. It requires a store-specific OAuth key, supplied via the admin interface, or as the --key parameter')
		;
	}

	/**
	 * @return int|null|void
	 */
	protected function serve()
	{
		// Collects the arguments and options as defined
		$this->options = [
			'key' => $this->input->getOption('key'),
			'images' => $this->input->getOption('images'),
			'publish' => $this->input->getOption('publish'),
			'save' => $this->input->getOption('save'),
			'brand' => $this->input->getOption('brand'),
			'manufacturer' => $this->input->getOption('manufacturer'),
			'categories' => $this->input->getOption('categories'),
			'descriptions' => $this->input->getOption('descriptions'),
			'sizes' => $this->input->getOption('sizes'),
			'types' => $this->input->getOption('types'),
			'all' => $this->input->getOption('all')
		];

		if ($this->options['all']) {
			// get all the printful info
			$this->options['images'] = true ;
			$this->options['categories'] = true ;
			$this->options['descriptions'] = true ;
			$this->options['sizes'] = true ;
			$this->options['types'] = true ;
		}

		$grav = Grav::instance() ;
		// If the optional `--key` is passed, use the key specified on the command line
		// otherwise use the one from the UI
		if ($this->options['key']) {
			$api_key = $this->options['key'];
		} else {
			$api_key = null ;
		}

		// are we going to publish these products automatically?
		$status = ($this->options['publish']) ? 'published' : 'draft'  ;

		$pf_result = PrintfulPlugin::printfulAPI('stores', $api_key) ;

		if (($pf_result === false) || ($pf_result->code != 200)) {
			$this->output->writeln('Error talking to Printful; check your key is valid and correctly scoped to a store') ;
			$this->output->writeln(json_encode($pf_result, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
		} else {
			// we have a valid store key, now let's get the products and start building output
			$catalog = array() ;
			$catalog[ 'version' ] = 1  ;

			// custom field definitions
			$catalog[ 'custom_fields'] = [
				[	'entity' => 'product',
					'name' => 'printful_product',
					'type' => 'integer',
					'label' => 'Printful product id',
					'help' => '0 = not a Printful product',
					'visibility' => 'internal',
					'sortable' => true,
					'filterable' => true,
					'default' => 0,
					'position' =>10 ],
				[	'entity' => 'variant',
					'name' => 'printful_variant',
					'type' => 'integer',
					'label' => 'Printful variant id',
					'help' => '0 = not a Printful product',
					'visibility' => 'internal',
					'sortable' => true,
					'filterable' => true,
					'default' => 0,
					'position' => 10 ]
			] ;

			if ($this->options['save']) {
				$yaml_file = $this->slugify($pf_result->result[0]->name) . '-' . time() . '.yaml';
				$this->output->writeln(sprintf("Saving YAML catalog for Printful shop '%s'", $pf_result->result[0]->name)) ;
			} else {
				$this->output->writeln(sprintf("Generating YAML catalog for Printful shop '%s'\n", $pf_result->result[0]->name)) ;
			}

			// do we need to get the Prinful category list?
			if ($this->options['categories']) {
				$c_result = PrintfulPlugin::printfulAPI('categories', $api_key) ;
				if (($c_result === false) || ($c_result->code != 200)) {
					$this->output->writeln("Error talking to Printful; unable to fetch categories") ;
				} else {
					$categories = array() ;
					foreach ($c_result->result->categories as $category) {
						$categories[$category->id] = $category->title ;
					}
				}
			}

			// now, start iterating over the products
			$pf_result = PrintfulPlugin::printfulAPI('store/products', $api_key) ;
			if (($pf_result === false) || ($pf_result->code != 200)) {
				$this->output->writeln("Error talking to Printful; unable to fetch products") ;
			} else {
				$products = array() ;
				$currency_saved = false ;

				foreach ($pf_result->result as $product) {
					if ($product->is_ignored) {
						break ;
					}
					// work out options
					$sizes = array() ;
					$colours = array() ;
					$options = array() ;
					$rgb = array() ;
					$main_category = 0 ;

					if ($this->options['images']) {
						$media = array() ;
						$fetched = array() ;
						$colour_images = array() ;
					}

					// iterate over the variants of this product
					$p_result = PrintfulPlugin::printfulAPI('store/products', $api_key, $product->id) ;
					if (($p_result == false) || ($p_result->code != 200)) {
						$this->output->writeln("Error talking to Prinful; unable to fetch variants") ;
						$this->output->writeln(json_encode($p_result, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
					} else {

						// get the base image, to use if there are no variants
						if ($this->options['images']) {
							$imginfo  = pathinfo($p_result->result->sync_product->thumbnail_url) ;
							$image_file = 'printful_p_' . $p_result->result->sync_product->id . '.' . $imginfo['extension'] ;
							$image = file_get_contents($p_result->result->sync_product->thumbnail_url) ;
							$size = getimagesizefromstring($image) ;

							$main_image = [
								'kind' => 'image',
								'role' => 'gallery',
								'path' => $image_file,
								'mime' => $size['mime'] ,
								'alt' => $product->name,
								'position' => count($media),
							] ;
						}

						// now the work for each variant
						$variants = array() ;
						foreach ($p_result->result->sync_variants as $variant) {
							if ($variant->is_ignored) {
								break ;
							}
							if (! $currency_saved) {
								// get the currency for the top of the catalog
								$currency_saved = true ;
								$catalog['currency'] = $variant->currency ;
							}
							if ($main_category == 0) {
								$main_category = $variant->main_category_id ;
							}
							$sizes[$variant->size] = true ;
							$colours[$variant->color] = true ;

							// we have to look up the root variant to be able to get the rgb color code for the swatches
							if (!isset($rgb[$variant->color])) {
								$v_lookup = PrintfulPlugin::printfulAPI('products/variant', $api_key, $variant->variant_id) ;
								if (($v_lookup == false) || ($v_lookup->code != 200)) {
									$this->output->writeln("Error talking to Prinful; unable to fetch variant info") ;
									$this->output->writeln(json_encode($v_lookup, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
								} else {
									$rgb[$variant->color] = $v_lookup->result->variant->color_code ;
								}
							}
							$v = [
								'position' => count($variants),
								// these are placeholders; we fold them into options later
								'size' => $variant->size,
								'color' => $variant->color,
								'price' => $variant->retail_price,
								'options' => array(),
								'custom_fields' => [ 'printful_variant' => $variant->id],
								'is_default' => (count($variants) == 0)
							] ;

							// handle variant images if required
							if ($this->options['images']) {
								foreach ($variant->files as $file) {
									if (($file->type == 'preview') && ($file->status == 'ok')) {
										$imginfo  = pathinfo($file->preview_url) ;
										// this checks if we have already got the same image
										if (!isset($fetched[$imginfo['basename']])) {
											$image_file = 'printful_v_' . $variant->id . '.' . $imginfo['extension'] ;

											$image = file_get_contents($file->preview_url) ;
											$size = getimagesizefromstring($image) ;

											$m = [
												'kind' => 'image',
												'role' => 'gallery',
												'path' => $image_file,
												'mime' => $size['mime'] ,
												'alt' => $product->name,
												'position' => count($media),
											] ;

											if ($this->options['save']) {
												$filename = GRAV_WEBROOT . "/" . GRAV_USER_PATH . '/images/kahunacart/products/' . $image_file ;
												$file = File::instance($filename);
												$file->save($image);
												$m['bytes'] = filesize($filename);
											}
											$fetched[$imginfo['basename']] = $image_file ;

											$media[] = $m ;

											$colour_images[$variant->color] = $image_file ;
										}
									}
								}
								// end of image loops
							}
							// end of variant processing
							$variants[] = $v ;

							// if we didn't get any images, add the main image, and save it if necessary
							if (($this->options['images'] && count($media) == 0) && isset($main_image)) {

								// only write images if --save is specified
								if ($this->options['save']) {
									$filename = GRAV_WEBROOT . "/" . GRAV_USER_PATH . '/images/kahunacart/products/' . $main_image['path'] ;
									$file = File::instance($filename);
									$file->save($image);
									$main_image['bytes'] = filesize($filename) ;
								}

								$media[] = $main_image ;
							}
						}
						// end of all variants
					}

					// Must do colors before sizes
					if (count($colours) > 1) {
						$swatches = array() ;
						foreach (array_keys($colours) as $swatch) {
							$swatches[] = [ 'label' => $swatch, 'color' => $rgb[$swatch], 'image' => $colour_images[$swatch]] ;
						}
						$options[] = [ 'name' => 'Color', 'widget' => 'swatches', 'values' => $swatches, ] ;
					}

					if (count($sizes) > 1) {
						$options[] = [ 'name' => 'Size', 'values' => array_keys($sizes)] ;
					}

					// fold in the necessary variants - colors before sizes
					$i = 0 ;
					foreach ($variants as $v) {
						if (count($colours) > 1) {
							$v['options'][] = $v['color'] ;
						}
						if (count($sizes) > 1) {
							$v['options'][] = $v['size'] ;
						}

						unset($v['color'],$v['size']) ;
						$variants[$i] = $v ;
						$i++ ;
					}

					// now put together the complete product with variants
					$p = [
						'title' => $product->name,
						'slug' => $this->slugify($product->name),
						'status' => $status,
						'type' => 'physical',
						'custom_fields' => [ 'printful_product' => $product->id],
						'brand' => ($this->options['brand']) ? $this->options['brand'] : 'Printful'
					] ;

					if ($this->options['categories']) {
						$slug = $this->slugify($categories[$main_category]) ;
						$p['categories'] = [ $slug ] ;
						$c_categories[] = [ 'slug' => $slug, 'title' => $categories[$main_category] ] ;
						//$p['categories'] = slugify($product->main_product)
					}

					if ($this->options['manufacturer']) {
						$p['manufacturer'] = $this->options['manufacturer'] ;
					}

					// has the description or summary been requested? If so, we need to get the info from the base product
					if ($this->options['types'] || $this->options['descriptions']) {
						$base_lookup = PrintfulPlugin::printfulAPI('products', $api_key, $p_result->result->sync_variants[0]->product->product_id) ;
						if (($base_lookup == false) || ($base_lookup->code != 200)) {
							$this->output->writeln("Error talking to Prinful; unable to fetch base product info") ;
							$this->output->writeln(json_encode($base_lookup, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
						} else {
							if ($this->options['types']) {
								$p['summary'] = $base_lookup->result->product->type_name ;
							}
							if ($this->options['descriptions']) {
								// we replace bullet points with a + for proper markdown lists
								$desc = preg_replace('/\n· /', "\n+ ", $base_lookup->result->product->description) ;
								$p['description'] = preg_replace('/\n• /', "\n+ ", $desc) ;
							}
						}
					}

					// has a sizing table been requested? If so, we get that from the base product too
					if ($this->options['sizes']) {
						$unit = ($grav['config']->get('plugins.printful.size_units') == 1) ? 'inches' : 'cm' ;

						$size_lookup = PrintfulPlugin::printfulAPI('products/' .$p_result->result->sync_variants[0]->product->product_id . '/sizes?unit=' . $unit, $api_key) ;
						if (($size_lookup == false) || ($size_lookup->code != 200)) {
							$this->output->writeln("Error talking to Prinful; unable to fetch product sizing info") ;
							$this->output->writeln(json_encode($size_lookup, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
						} else {
							$sizing = $size_lookup->result->size_tables ;
							foreach ($sizing as $table) {
								if ($table->type == 'product_measure') {
									$units = $table->unit ;
									$measurements = array() ;
									$m_sizes = array() ;
									$m_labels = array() ;
									foreach ($table->measurements as $m) {
										!in_array($m->type_label, $m_labels) && $m_labels[] = $m->type_label ;
										foreach ($m->values as $v) {
											!in_array($v->size, $m_sizes) && $m_sizes[] = $v->size ;
											$measurements[$v->size][$m->type_label] = $v->value ;
										}
									}
								}
							}
							// ok, now we iterate over the table, if there are entries, to create the MarkDown
							if (count($m_sizes) > 0) {
								$size_table = "|Size|" ;
								$separator = "|---|" ;
								foreach ($m_labels as $label) {
									$size_table .= $label . " ($units)|" ;
									$separator .= '---|' ;
								}
								$size_table .= "\n" . $separator . "\n";

								foreach ($m_sizes as $sz) {
									$size_table .= "|" . $sz . "|";
									foreach ($m_labels as $m) {
										$size_table .= $measurements[$sz][$m] . "|" ;
									}
									$size_table .= "\n" ;
								}

								// now we have the table as MarkDown
								// the user needs to have defined an attribute as a paragraph for this
								$p['attributes'] = [ ($this->options['sizes'] === true) ? 'sizing' : $this->slugify($this->options['sizes']) => $size_table ] ;
							}
						}
					}

					if (count($options) > 0) {
						$p['options'] = $options ;
					}

					if (($this->options['images']) && (count($media) > 0)) {
						$p['media'] = $media ;
					}

					$p['variants'] = $variants ;

					$products[] = $p ;
				}
				// end of products loop

				if ($this->options['categories']) {
					$catalog['categories'] = array_unique($c_categories, SORT_REGULAR) ;
				}

				if ($this->options['sizes']) {
					$attrib_name = ($this->options['sizes'] === true) ? 'Sizing' : $this->options['sizes'] ;
					$attributes = array() ;
					$attributes[] = [ 'slug' => $this->slugify($attrib_name),
										'label' => $attrib_name,
										'type' => 'textarea',
										'filterable' => false,
										'show_on_product' => true ] ;
					$catalog['attributes'] = $attributes ;
				}

				$catalog[ 'products' ] = $products  ;
				if ($this->options['save']) {
					$filename = GRAV_WEBROOT . "/" . GRAV_USER_PATH . '/data/printful/' . $yaml_file ;
					$file = File::instance($filename);
					$file->save(Yaml::dump($catalog, 8, 2, Yaml::DUMP_OBJECT_AS_MAP));
					//$file->save(yaml_emit($catalog)) ;
					$this->output->writeln('Data saved in user/data/printful/' . $yaml_file) ;
				} else {
					//$this->output->writeln(yaml_emit($catalog)) ;
					$this->output->writeln(Yaml::dump($catalog, 8, 2, Yaml::DUMP_OBJECT_AS_MAP));
				}
			}
		}
	}

	private function slugify(string $input): string
	{
		// transliterate
		$title = iconv('utf-8', 'ISO-8859-1//TRANSLIT', $input);

		// Convert all dashes, spaces, & underscores to hyphens
		$title = str_replace(' - ', '-', $title);
		$title = str_replace('—', '-', $title);
		$title = str_replace('‒', '-', $title);
		$title = str_replace('―', '-', $title);
		$title = str_replace('_', '-', $title);
		$title = str_replace(' ', '-', $title);

		// Remove everything except 0-9, a-z, A-Z and hyphens
		$title = preg_replace('/[^A-Za-z0-9-]+/', '', $title);

		// Make lowercase - no need for this to be multibyte
		// since there are only 0-9, a-z, A-Z and hyphens left in the string
		$slug = strtolower($title);

		return $slug;
	}
}
