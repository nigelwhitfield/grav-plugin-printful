# v0.9.6
## 10/16/2026
1. [](#new)
    * Printful shipments are now correctly recorded via the webhook and will trigger Kahunacart's "On the way" email

# v0.9.5
## 10/11/2026
1. [](#bugfix)
    * Fix eror in status check for failed orders

# v0.9.4
## 10/11/2026

1. [](#changed)
    * Printful is now passed the order 'number' rather than the 'id' to be consistent with the list of orders in admin
    * Grav logs now show the order number, rather than the id
    * Printful status is updated from webhooks
    
    [](#new)
    * Order items now have a printful_shipping_ref which will be set when they are shipped by Printful
    * new function cancelOrder will return false if an order cannot be cancelled (eg already in production), or true if the order has been successfully cancelled on Printful, or has not been sent to Printful. The printful_status will be set to 'exception' so that it can be picked up by the alerts system
    * When an order is reversed in full, an attempt will be made to cancel any associated Printful order
    * An alert will be shown when an order has the Printful status 'exception', eg if it is cancelled, but production has already begun.

# v0.9.3
## 10/10/2026

1. [](#new)
    * Add webhook:disable and webhook:enable cli commands
    * update Readme
    * update PrintfulPlugin::printfulAPI function to add more options

# v0.9.2
##  10/10/2026

1. [](#new)
    * First public version
