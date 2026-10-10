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
class WebhookEnableCommand extends ConsoleCommand
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
			->setName("webhook:enable")
			->setDescription("Enables Printful webhook")
			->addOption(
				'key',
				'k',
				InputOption::VALUE_REQUIRED,
				'Override Printful store API key configured in settings'
			)
			->addOption(
				'api_key',
				'a',
				InputOption::VALUE_REQUIRED,
				'Specify a Grav API with api.access and printful.webhook permissions'
			)


			->setHelp('The <info>webhook:enable</info> command turns on the Printful webhook for your store, to receive order status updates.  It requires a store-specific OAuth key, supplied via the admin interface, or as the --key parameter. You must specify a Grav API key with the --api_key parameter, which needs the api.access and printful.webhook permissions.')
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
			'api_key' => $this->input->getOption('api_key')
		];

		$grav = Grav::instance() ;
		// If the optional `--key` is passed, use the key specified on the command line
		// otherwise use the one from the UI
		if ($this->options['key']) {
			$api_key = $this->options['key'];
		} else {
			$api_key = null ;
		}

		if (strlen($this->options['api_key']) == 0) {
			$this->output->writeln("Error: you must specify a Grav api key with --api_key") ;
		} else {
			$pf_result = PrintfulPlugin::printfulAPI('stores', $api_key) ;
			if (($pf_result === false) || ($pf_result->code != 200)) {
				$this->output->writeln('Error: invalid API key, or unable to talk to Printful') ;
			} else {
				$this->output->writeln(sprintf("Enabling webhooks for Printful shop '%s'\n", $pf_result->result[0]->name)) ;

				$webhooks = [
					'url' => $grav['config']->get('system.custom_base_url') . '/api/v1/printful/webhook?api_key=' . $this->options['api_key'],
					'types' => [ 	'package_shipped',
									'order_updated']
				] ;

				var_dump($webhooks) ;
				/*$pf_enable = PrintfulPlugin::printfulAPI('webhooks', null, null, false, $webhooks) ;

				if (($pf_enable === false) || ($pf_enable->code != 200)) {
					$this->output->writeln("Error talking to Printful; unable to enable webhooks") ;
					$this->output->writeln(json_encode($pf_enable, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
				} else {
					$this->output->writeln("Done") ;
				}*/
			}
		}
	}
}
