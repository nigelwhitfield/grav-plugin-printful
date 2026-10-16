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
        $response = 'ok' ;

        $pf_order = $body['data']['order'] ;
        $this->grav['log']->info('Printful: webhook received: ' . $body['type'] . ' for order ' . $pf_order['external_id']) ;

        $this->grav['log']->debug('Printful: webhook data: ' . json_encode($body, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)) ;

        $kahunacart = KahunaCart::instance();
        $customFields = $kahunacart->customFields();
        $orders = $customFields->rows('order', 'printful_ref', 'asc', [ 'printful_ref' => $pf_order['id']]) ;

        if (count($orders) != 1) {
            $response = [   'status' => 'not found',
                            'printful_order' => $pf_order['id'],
                            'status_to' => $pf_order['status']] ;
            $this->grav['log']->warning('Printful: no order found matching id ' . $pf_order['external_id']) ;
        } else {
            switch ($body['type']) {

                case 'order_updated':
                    // general order update

                    // update the new status on the order
                    $kahunacart->customFields()->write('order', $orders[0]['id'], ['printful_status' => $pf_order['status']], false) ;
                    $response = [   'status' => 'ok',
                                    'local_id' => $orders[0]['id'],
                                    'printful_order' => $pf_order['id'],
                                    'status_to' => $pf_order['status'],
                                    'order' => $orders[0]] ;

                    // certain statuses also need actions on the order or cart
                    switch ($pf_order['status']) {
                        case 'canceled' :
                            // we have to remove this item from the order

                            break ;
                        default:
                            // nothing else to do
                    }

                    break ;

                case 'package_shipped':
                    // items have been shipped
                    // we need to work out which items this refers to
                    $order = $orders[0] ;

                    $pf_shipment = $body['data']['shipment'] ;

                    $pf_shipped_items = $pf_shipment['items'] ;

                    $pf_order_items = $body['data']['order']['items'] ;

                    $shipment_map = array() ;
                    // combine shipped items data with order items, so we only have to iterate both arrays once
                    foreach ($pf_shipped_items as $shipped_item) {
                        foreach ($pf_order_items as $order_item) {
                            if ($order_item['id'] = $shipped_item['item_id']) {
                                // we have a match
                                $shipment_map[] = [ 'item_id' => $order_item['external_id'],
                                                    'quantity'=> $shipped_item['quantity']] ;
                                break ;
                            }
                        }
                    }

                    $items = $kahunacart->orders()->itemsFor($order['id']) ;
                    $shipped_items = array() ;
                    foreach ($items as $item) {
                        $shipping_ref =  $kahunacart->customFields()->valuesFor('order_item', $item['id'])->text('printful_shipping_ref') ;
                        if ($pf_shipment['id'] == $shipping_ref) {
                            // we've seen this reference before
                            $response = 'duplicate webhook' ;
                            break ;
                        }

                        // ok, so let's see what's been shipped
                        foreach ($shipment_map as $shipped) {
                            if ($shipped['item_id'] == $item['id']) {
                                // this is the correct item
                                $shipped_items[] = $shipped ;

                                // mark the reference as having been used, so we avoid duplicates
                                $kahunacart->customFields()->write('order_item', $item['id'], ['printful_shipping_ref' => $pf_shipment['id']]) ;
                                break ;
                            }
                        }
                    }

                    // now we can notify the user, if required
                    if (count($shipped_items) > 0) {
                        $result = KahunaCart::instance()->fulfillment()->ship(
                            $order['id'],
                            $shipped_items,
                            [   'carrier' => $pf_shipment['carrier'],
                                'tracking_number' => $pf_shipment['tracking_number'],
                                'tracking_url' => $pf_shipment['tracking_url'],
                                'created_by_type' => 'integration',
                                'created_by' => 'printful'],
                        ) ;
                    }

                    break ;
            }
        }

        return ApiResponse::create($response);
    }
}
