<?php

namespace Grav\Plugin\PrintfulPlugin;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Grav\Plugin\KahunaCart\KahunaCart ;

/**
 * REST controller for the Printful plugin.
 *
 * Extends the API plugin's AbstractApiController, which is constructed with
 * ($grav, $config) and provides helpers such as requirePermission(),
 * getRouteParam(), getRequestBody(), getPagination() and getUser().
 *
 * Do NOT declare a constructor here — the API plugin instantiates the
 * controller for you.
 */
class PrintfulApiController extends AbstractApiController
{
    /**
     * GET /api/v1/printful/items
     *
     * Returns a sample payload. Responses are wrapped in a { "data": ... }
     * envelope by ApiResponse. Throw an ApiException subclass (NotFound,
     * Validation, Forbidden, ...) to return an RFC 7807 error.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function listItems(ServerRequestInterface $request): ResponseInterface
    {
        // Permissions are enforced here, not on the route.
        $this->requirePermission($request, 'printful.view');

        $kahunacart = KahunaCart::instance();
        $customFields = $kahunacart->customFields();
        $orders = $customFields->rows('order', 'printful_status', 'asc') ;

        $items = array() ;
        foreach ($orders as $order) {
            $status = $kahunacart->customFields()->valuesFor('order', $order['id'])->text('printful_status') ;
            $ref = $kahunacart->customFields()->valuesFor('order', $order['id'])->text('printful_ref') ;
            $billing = json_decode($order['billing_json']) ;
            ($status != 'na') && $items[] = [ 'id' => $order['id'],
                             'ref' => $ref,
                             'status' => $status,
                             'name' => $billing->name] ;
        }
        return ApiResponse::create($items);
    }

    public function webhook(ServerRequestInterface $request): ResponseInterface
    {
        // this is our handler for incoming webhook deliveries from Printful
        $this->requirePermission($request, 'printful.webhook');

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['type']);
        $response = 'not sure yet ...' ;

        $pf_order = $body['data']['order'] ;
        $this->grav['log']->info('Printful: webhook received: ' . $body['type'] . ' for order ' . $pf_order['external_id']) ;

        $this->grav['log']->debug('Printful: webhook data: ' . json_encode($body, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;

        switch ($body['type']) {

            case 'order_updated':
                // general order update

                $kahunacart = KahunaCart::instance();
                $customFields = $kahunacart->customFields();
                $order = $customFields->rows('order', 'printful_ref', 'asc', [ 'printful_ref' => $pf_order['id']]) ;

                if (count($order) == 1) {
                    // update the new status on the order
                    $kahunacart->customFields()->write('order', $order['id'], ['printful_status' => $pf_order['status']], false) ;
                    $response = [   'status' => 'ok',
                                    'local_id' => $order['id'],
                                    'printful_order' => $pf_order['id'],
                                    'status_to' => $pf_order['status'],
                                    'order' => $order] ;

                    // certain statuses also need actions on the order or cart
                    switch ($pf_order['status']) {
                        case 'canceled' :
                            break ;
                        case 'partial' :
                            break ;
                        case 'shipped' :
                            break ;
                        default:
                            // nothing
                    }
                } else {
                    $response = [ 'status' => 'not found',
                                    'printful_order' => $pf_order['id'],
                                    'status_to' => $pf_order['status']] ;
                    $this->grav['log']->warning('Printful: no order found matching id ' . $pf_order['external_id']) ;
                }
                break ;

            case 'package_shipped':
                // items have been shipped


                break ;

        }


        return ApiResponse::create($response);
    }
}
