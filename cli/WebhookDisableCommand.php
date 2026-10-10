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
class WebhookDisableCommand extends ConsoleCommand
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
			->setName("webhook:disable")
			->setDescription("Disables Printful webhook")
			->addOption(
				'key',
				'k',
				InputOption::VALUE_REQUIRED,
				'Override Printful store API key configured in settings'
			)


			->setHelp('The <info>webhook:disable</info> turns off the Printful webhook for your store. This means orders statuses will NOT update. It requires a store-specific OAuth key, supplied via the admin interface, or as the --key parameter')
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
		];

		$grav = Grav::instance() ;
		// If the optional `--key` is passed, use the key specified on the command line
		// otherwise use the one from the UI
		if ($this->options['key']) {
			$api_key = $this->options['key'];
		} else {
			$api_key = null ;
		}

		$pf_result = PrintfulPlugin::printfulAPI('stores', $api_key) ;
		if (($pf_result === false) || ($pf_result->code != 200)) {
			$this->output->writeln('Error: invalid API key, or unable to talk to Printful') ;
		} else {
			$this->output->writeln(sprintf("Disabling webhooks for Printful shop '%s'\n", $pf_result->result[0]->name)) ;

			$pf_disable = PrintfulPlugin::printfulAPI('webhooks', null, null, false, null, 'DELETE') ;

			if (($pf_disable === false) || ($pf_disable->code != 200)) {
				$this->output->writeln("Error talking to Printful; unable to disable webhooks") ;
				$this->output->writeln(json_encode($pf_disable, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
			} else {
				$this->output->writeln("Done") ;
			}
		}
	}
}
