<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Page\Collection;
use Grav\Common\Uri;
use Grav\Common\Grav ;
use Grav\Common\Taxonomy;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\KahunaCart\KahunaCart ;
use Grav\Plugin\KahunaCart\Admin\Alerts\CallableAlert;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;

/**
 * Class PrintfulPlugin
 * @package Grav\Plugin
 */
class PrintfulPlugin extends Plugin
{
    // The Printful API
    public const PRINTFUL_API_ROOT = 'https://api.printful.com/' ;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onKahunaCartRegisterCustomFields' => ['onKahunaCartRegisterCustomFields', 0],
            'onKahunaCartOrderPaid' => ['onKahunaCartOrderPaid',0],
            'onSchedulerInitialized' => ['onSchedulerInitialized',0],
            'onKahunaCartRegisterDashboardAlerts' => ['onRegisterAlerts', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 0],
            ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            // Put your main events here
        ]);
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $permissions = $event->permissions;
        $permissions->addActions(PermissionsReader::fromYaml("plugin://printful/permissions.yaml"));
    }

    /*
     * For handling calls to the Plugin
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\PrintfulPlugin\PrintfulApiController::class;

        $routes->group('/printful', function ($group) use ($controller) {
            $group->get('/items', [$controller, 'listItems']);
            $group->post('/items', [$controller, 'updateItem']);
            $group->post('/webhook', [$controller, 'webhook']);
        });
    }


    /*
     * Main work function - check paid orders for Printful products
     *
     */
    public function onKahunaCartOrderPaid(Event $event): void
    {
        $oid = $event['order_id'] ;
        $printful = false ;

        $kahunacart = KahunaCart::instance();
        $items = $kahunacart->orders()->itemsFor($oid) ;

        // iterate over the items, to see if any has a Printful template id
        foreach ($items as $item) {
            $pfid = $kahunacart->customFields()->valuesFor('product', $item['product_id'])->text('printful_product') ;
            if ($pfid > 0) {
                $printful = true ;
                // we only need one product to confirm this has to go to Printful, so we can escape the loop here
                break ;
            }
        }
        if ($printful) {
            // add this order to our Printful job queue
            // to do this we set the printful_status to queued
            $kahunacart->customFields()->write('order', $oid, ['printful_status' => 'queued'], false) ;
        }
    }

    /*
     * Add extra fields to manage Printful orders
     */
    public function onKahunaCartRegisterCustomFields(Event $event): void
    {
        $event['registry']->declare([
            'entity' => 'product',
            'name' => 'printful_product',
            'type' => 'integer',
            'label_key' => 'Printful product id',
            'help_key' => '0 = not a Printful product',
            'sortable' => true,
            'filterable' => true,
            'default' => 0,
            'position' => 10,
            'visibility' => 'internal'
        ], 'printful')
        ->declare([
            'entity' => 'variant',
            'name' => 'printful_variant',
            'type' => 'integer',
            'label_key' => 'Printful variant id',
            'help_key' => '0 = not a Printful product',
            'sortable' => true,
            'filterable' => true,
            'default' => 0,
            'position' => 10,
            'visibility' => 'internal'
        ], 'printful')
        ->declare([
            'entity' => 'order',
            'name' => 'printful_status',
            'type' => 'select',
            'validate' => [ 'options' => [ 'na', 'queued', 'submitted', 'draft', 'failed','inreview','pending','canceled','onhold','inprocess','partial','fulfilled' ]],
            'label_key' => 'Printful status',
            'sortable' => true,
            'filterable' => true,
            'default' => 'na',
            'position' => 10,
            'visibility' => 'internal'
        ], 'printful')
        ->declare([
            'entity' => 'order',
            'name' => 'printful_ref',
            'type' => 'text',
            'label_key' => 'Printful order ref',
            'sortable' => true,
            'filterable' => true,
            'position' => 10,
            'visibility' => 'internal'
        ], 'printful')
        ->declare([
            'entity' => 'order_item',
            'name' => 'printful_shipping_ref',
            'type' => 'text',
            'label_key' => 'Printful shipping ref',
            'sortable' => true,
            'filterable' => true,
            'position' => 10,
            'visibility' => 'internal'
        ], 'printful');
    }

    // Set up a scheduled job, to check on Printful orders
    public function onSchedulerInitialized(Event $e): void
    {
        $scheduler = $e['scheduler'];
        $at = '*/30 * * * *';
        $job = $scheduler->addFunction('Grav\Plugin\PrintfulPlugin::processPrintfulJobs', [], 'printful-process');
        $job->at($at);
        $job->backlink('/plugins/printful');
    }

    // handle alerts in the KahunaCart strip
    public function onRegisterAlerts(Event $event): void
    {
        $event['registry']->register(new CallableAlert(
            'printful_order_errors',
            // What the check finds. Return an array. Every other callback here is
            // handed this, and it is what the payload carries.
            fn (): array => ['count' => $this->countPrintfulErrors()],
            // Seconds before it runs again. This one is a counting query over an
            // indexed column, so five minutes is generous.
            recheckInterval: 1800,
            // Whether there is anything to say. A check that finds nothing wrong
            // should return data and answer false here.
            shouldShow: fn (array $d): bool => $d['count'] > 0,
            // The headline, as a key and its arguments.
            title: fn (array $d): array => [
                'key' => 'PLUGIN_PRINTFUL.ALERT_ERRORS',
                'args' => [(string)$d['count']],
            ],
            // The sentence under it, saying what to do. Optional.
            detail: fn (): ?array => ['key' => 'PLUGIN_PRINTFUL.ALERT_ERRORS_DETAIL', 'args' => []],
            severity: fn (): string => 'error',
            // Where the button goes, as an admin route inside the KahunaCart
            // section. The shell navigates to it without reloading the page.
            actions: fn (): array => [[
                'code' => 'open',
                'label_key' => 'PLUGIN_PRINTFUL.ALERT_ERRORS_ACTION',
                'route' => '/orders?cf[printful_status]=failed',
            ]],
            // How long a dismissal lasts, in seconds. Null means until the user
            // puts it back.
            dismissWindow: 86400,
        ));

        $event['registry']->register(new CallableAlert(
            'printful_order_notices',
            // What the check finds. Return an array. Every other callback here is
            // handed this, and it is what the payload carries.
            fn (): array => ['count' => $this->countPrintfulNotices()],
            // Seconds before it runs again. This one is a counting query over an
            // indexed column, so five minutes is generous.
            recheckInterval: 1800,
            // Whether there is anything to say. A check that finds nothing wrong
            // should return data and answer false here.
            shouldShow: fn (array $d): bool => $d['count'] > 0,
            // The headline, as a key and its arguments.
            title: fn (array $d): array => [
                'key' => 'PLUGIN_PRINTFUL.ALERT_ERRORS',
                'args' => [(string)$d['count']],
            ],
            // The sentence under it, saying what to do. Optional.
            detail: fn (): ?array => ['key' => 'PLUGIN_PRINTFUL.ALERT_NOTICE_DETAIL', 'args' => []],
            severity: fn (): string => 'info',
            // Where the button goes, as an admin route inside the KahunaCart
            // section. The shell navigates to it without reloading the page.
            actions: fn (): array => [[
                'code' => 'open',
                'label_key' => 'PLUGIN_PRINTFUL.ALERT_ERRORS_ACTION',
                'route' => '/orders?cf[printful_status]=onhold',
            ]],
            // How long a dismissal lasts, in seconds. Null means until the user
            // puts it back.
            dismissWindow: 86400,
        ));
    }

    public static function countPrintfulErrors(): int
    {
        $kahunacart = KahunaCart::instance();

        $customFields = $kahunacart->customFields();

        $orders = $customFields->rows('order', 'printful_status', 'asc', [ 'printful_status' => 'failed']) ;
        return count($orders) ;
    }

    public static function countPrintfulNotices(): int
    {
        $kahunacart = KahunaCart::instance();

        $customFields = $kahunacart->customFields();

        $orders = $customFields->rows('order', 'printful_status', 'asc', [ 'printful_status' => 'onhold']) ;
        return count($orders) ;
    }

    /*
     * These are functions for our background tasks
     * first, scan the queue for jobs that need despatching
     */
    public static function processPrintfulJobs(): void
    {
        $grav = Grav::instance() ;

        // iterate over the orders
        $kahunacart = KahunaCart::instance();

        $customFields = $kahunacart->customFields();

        $orders = $customFields->rows('order', 'printful_status', 'asc', [ 'printful_status' => 'queued']) ;

        $delay = $grav['config']->get('plugins.printful.delay_hours')*3600 ;

        foreach ($orders as $order) {
            $oid = $order['id'] ;
            $address = json_decode($order['shipping_json'], false) ;

            $updated = $order['updated_at'] ;
            if ($updated < time()-$delay) {
                $grav['log']->info('Printful: Creating order for ' . $number) ;
                $printful_ref = self::despatchPrintfulOrder($oid, $order['number'], $address) ;
                if ($printful_ref !== false) {
                    $kahunacart->customFields()->write('order', $oid, ['printful_ref' => $printful_ref], false) ;
                    // the 'submitted' status ensures we don't try this again, before we get an update from Printful
                    $kahunacart->customFields()->write('order', $oid, ['printful_status' => 'submitted'], false) ;
                } else {
                    // mark an error in the dashboard
                    $kahunacart->customFields()->write('order', $oid, ['printful_status' => 'failed'], false) ;
                }
            }
        }
    }

    /*
     * And now send an order to the Printful api
     */
    public static function despatchPrintfulOrder(int $orderid, $number, $address)
    {
        $grav = Grav::instance() ;

        // quick sanity check
        $printful_api_key = $grav['config']->get('plugins.printful.api_key') ;

        if (trim($printful_api_key) == '') {
            $grav['log']->warning('Printful: API key has not been configured. Unable to process orders') ;
            return false ;
        }

        if ($address == null) {
            $grav['log']->warning('Printful: No address available for order ' . $number) ;
            return false ;
        }

        // build the order JSON to send to Printful
        $kahunacart = KahunaCart::instance();

        $items = $kahunacart->orders()->itemsFor($orderid) ;

        // iterate over the items, to see if any has a Printful product id
        $printful_products = array() ;

        foreach ($items as $item) {
            $grav['log']->debug('Cart item ' . json_encode($item)) ;
            $pfid = $kahunacart->customFields()->valuesFor('product', $item['product_id'])->text('printful_product') ;
            if ($pfid > 0) {
                // get the sync variant
                $pfvid = $kahunacart->customFields()->valuesFor('variant', $item['variant_id'])->text('printful_variant') ;
                if ($pfvid > 0) {
                    $pf_product = new \stdClass() ;
                    $pf_product->sync_variant_id = $pfvid ;
                    $pf_product->quantity = $item['qty'] ;
                    $pf_product->retail_price = sprintf("%0.2f", $item['unit_price_minor']/100) ;
                    $pf_product->name = $item['title'] ;
                    $pf_product->external_id = $item['id'] ;
                    $printful_products[] = $pf_product ;
                }
            }
        }

        if (count($printful_products) == 0) {
            // something wrong here - perhaps misconfigured products
            $grav['log']->warning('Printful: No products found. Check product or variant configurations for order ' . $number) ;
            return false ;
        }

        // if we got here, we have an array of products to send to Printful, so we need to make the rest of the order object
        $printful_order = new \stdClass() ;
        $printful_order->external_id = $grav['config']->get('plugins.printful.order_prefix') . $number ;

        $printful_order->items = $printful_products ;

        // customer information
        $recipient = new \stdClass() ;
        $recipient->name = $address->name ;
        $recipient->company = $address->company ;
        $recipient->address1 = $address->address1 ;
        $recipient->address2 = $address->address2 ;
        $recipient->city = $address->city ;
        $recipient->state_name = $address->region ;
        $recipient->country_code = $address->country ;
        $recipient->zip = $address->postcode ;
        $recipient->phone = $address->phone ;

        $printful_order->recipient = $recipient ;

        // packing slip
        $email = $grav['config']->get('plugins.printful.store_email') ;
        if (strlen($email) > 0) {
            $packing_slip = new \stdClass() ;
            $packing_slip->email = $email ;
            $packing_slip->phone = $grav['config']->get('plugins.printful.store_phone') ;
            $packing_slip->message = $grav['config']->get('plugins.printful.message') ;
            $packing_slip->logo_url = $grav['config']->get('plugins.printful.logo_url') ;
            $packing_slip->store_name = $grav['config']->get('plugins.printful.store_name') ;
            $packing_slip->custom_order_id = $number ;

            $printful_order->packing_slip = $packing_slip ;
        }

        // To - Do: rewrite this to use the generate PrintfulAPI function below
        // draft mode - orders have to be confirmed separately; useful for testing
        $confirm = ($grav['config']->get('plugins.printful.draft_mode') == 1) ? 'false' : 'true' ;

        $endpoint = self::PRINTFUL_API_ROOT . 'orders?confirm=' . $confirm ;

        $api = curl_init() ;

        curl_setopt_array($api, [
          CURLOPT_URL => $endpoint,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_POSTFIELDS => json_encode($printful_order, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES),
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_HTTPHEADER => [
            "Content-Type: application/json;charset=utf-8",
            "Authorization: Bearer " . $printful_api_key
          ],
        ]);

        $response = curl_exec($api) ;

        if ($response === false) {
            // curl error
            $grav['log']->error('Printful: Unsuccessful call to ' . $endpoint . ' for order ' . $number) ;
            return false ;
        }

        $printful_response = json_decode($response) ;

        if ($grav['config']->get('plugins.printful.order_email') == 1) {
            $to = $grav['config']->get('plugins.printful.store_email') ;
            $from = $grav['config']->get('plugins.printful.store_email') ;
            $subject = 'Printful order response for ' . $endpoint ;
            $content = $response ;
            $message = $grav['Email']->message($subject, $content, 'text/html')
               ->setFrom($from)
               ->setTo($to);

            $sent = $grav['Email']->send($message);
        }

        if (!isset($printful_response->code)) {
            // unexpected return value
            $grav['log']->error('Printful: Unexpected response from ' . $endpoint . 'for order ' . $number) ;
            return false ;
        }

        if ($printful_response->code != 200) {
            $grav['log']->error('Printful: ' . $printful_response->code . ': ' . $printful_response->result . ' for order ' . $number) ;
            return false ;
        }

        if ($printful_response->result->status == 'failed') {
            $grav['log']->warning('Printful: Unable to create order for ' . $number) ;
            return false ;
        }

        return $printful_response->result->id ;
    }


    /*
     * General helper function for the Printful api
     */
    public static function printfulApi(string $endpoint, string $api_key = null, int $id = null, $offset = false, $postdata = null, $method = null)
    {
        $grav = Grav::instance() ;

        // quick sanity check
        ($api_key == null) && $api_key = $grav['config']->get('plugins.printful.api_key') ;

        $api = curl_init() ;

        $url = self::PRINTFUL_API_ROOT . $endpoint . (($id == null) ? '' : '/' . $id) ;

        if ($offset !== false) {
            $url.= ($id == null) ? '?offset=' . $offset : '&offset=' . $offset ;
        }

        if (($method == null) && ($postdata == null)) {
            $method = 'GET' ;
        } elseif ($postdata != null) {
            $method = ($method == null) ? 'POST' : $method ;
        }

        curl_setopt_array($api, [
          CURLOPT_URL => $url ,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST =>  $method,
          CURLOPT_HTTPHEADER => [
            "Content-Type: application/json;charset=utf-8",
            "Authorization: Bearer " . $api_key
          ],
        ]);

        if ($postdata != null) {
            curl_setopt($api, CURLOPT_POSTFIELDS, json_encode($postdata, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;
        }

        $response = curl_exec($api) ;

        if ($response === false) {
            // curl error
            $grav['log']->error('Printful: Unsuccessful call to ' . $url) ;
            return false ;
        }

        $printful_response = json_decode($response) ;

        // paging
        if (($printful_response->code == 200) && isset($printful_response->paging) && ($offset === false)) {
            $offset = $printful_response->paging->offset ;
            $pagesize = $printful_response->paging->limit ;
            $total = $printful_response->paging->total ;

            while (count($printful_response->result) < $total) {
                // get another page
                $offset = $offset+$pagesize ;
                $nextpage = self::printfulApi($endpoint, $api_key, $id, $offset) ;
                if ($nextpage->response->code == 200) {
                    $printful_response->result[] = $nextpage->result ;
                } else {
                    break ;
                }
            }
        }

        $printful_response->called_url = $url ;
        return $printful_response ;
    }
}
