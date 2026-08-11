# WooCommerce Nexway Payment

Integrates [Nexway Monetize](https://apidoc.nexway.store) as a WooCommerce payment gateway. Customers are redirected to Nexway's hosted checkout, and order state is updated via Nexway's notification webhook.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- A Nexway Monetize account with API credentials

## Installation

1. Upload the `woocommerce-nexway-payment` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **WooCommerce → Settings → Payments → Nexway** and configure the gateway.

## Configuration

| Setting | Description |
|---|---|
| **Base URL** | Root URL for the Nexway API. Default: `https://api.nexway.store` |
| **Client ID** | OAuth `client_id` from your Nexway account |
| **Client Secret** | OAuth `client_secret` from your Nexway account |
| **Realm** | Your Nexway realm identifier |
| **Store ID** | UUID of the Nexway store carts are created against |
| **Default billing country** | ISO country code used when the WC order has no billing country (default: `FR`) |
| **Allowed currencies** | Currencies for which Nexway is offered at checkout. Leave empty to skip the check |
| **Fulfillment Basic auth user/password** | Credentials Nexway uses to authenticate to the fulfillment endpoint on this site |
| **Notification Basic auth user/password** | Credentials Nexway uses to authenticate to the notification webhook on this site |
| **Skip processing status** | Move paid orders straight to Completed instead of Processing |

### Webhook and fulfillment URLs

The settings page displays the URLs you need to configure manually in your Nexway merchant account:

| Endpoint | URL |
|---|---|
| Notification webhook | `https://yoursite.com/wp-json/nexway/v1/notification/` |
| Fulfillment | `https://yoursite.com/wp-json/nexway/v1/fulfillment/` |

## Product mapping

Each WooCommerce product must be mapped to its corresponding Nexway product UUID. Products without a mapping are not offered for Nexway payment at checkout.

### Individual mapping

Open any product in the WooCommerce product editor. The **Nexway product mapping** meta box on the right accepts the Nexway product UUID for that product. Product variations have their own field under the **Variations** tab.

### Bulk import via CSV

Go to **WooCommerce → Nexway Mapping** and upload a CSV file with two columns:

```
woo_id,nexway_id
123,2f9bb37b-3558-49f0-bea6-69ab834013de
456,9a1cc48d-4669-50g1-cfa7-80bc945124ef
```

A header row is optional and is detected automatically. The results table shows the outcome for every row — updated or skipped with a reason.

## Checkout flow

1. Customer adds mapped products to the cart and selects Nexway at checkout.
2. WooCommerce creates an order and redirects the customer to Nexway's hosted checkout.
3. The customer completes payment on Nexway's pages.
4. Nexway displays its own thank-you page and redirects the customer to the WooCommerce account orders page.
5. Nexway sends a notification to the webhook endpoint, which updates the WC order status.

## Fulfillment

Nexway calls the fulfillment endpoint per line item when an order needs product delivery. This is a synchronous call — the response must include activation data or an error, and it blocks order completion on the Nexway side.

Configure the fulfillment endpoint credentials in the gateway settings (**Fulfillment Basic auth user/password**), and point Nexway at the URL shown on the settings page.

### Providing activation data

Hook into the `nxp_fulfillment_response` filter to return activation data for your products:

```php
add_filter( 'nxp_fulfillment_response', function( array $response, array $payload, ?WC_Order $order ) {
    $nexway_product_id = $payload['product']['id'] ?? '';
    $user_email        = $payload['user']['email'] ?? '';

    // Generate or retrieve an activation code / link for this product and user.
    $response['activationLink'] = 'https://yoursite.com/activate?key=...';

    return $response;
}, 10, 3 );
```

Return a non-empty `errorCode` to signal a failure:

```php
$response['errorCode']    = 'license_unavailable';
$response['errorMessage'] = 'No licenses available for this product.';
return $response;
```

### Fulfillment payload

The `$payload` array contains:

```json
{
  "fulfillmentId": "uuid",
  "checkout": {
    "orderId": "nexway-order-uuid",
    "lineItemId": "uuid",
    "cartExternalContext": "<base64-encoded JSON>"
  },
  "user": {
    "email": "customer@example.com",
    "country": "FR",
    "locale": "fr-FR",
    "firstName": "Jane",
    "lastName": "Doe"
  },
  "product": {
    "id": "nexway-product-uuid",
    "name": "Product name",
    "publisherProductId": "your-sku"
  }
}
```

`cartExternalContext` decodes to `{"merchant_reference":"wc_order_key"}` and is used internally to correlate the fulfillment call to a WC order.

## Hooks

| Hook | Type | Description |
|---|---|---|
| `nxp_form_fields` | filter | Modify the gateway settings fields |
| `nxp_fulfillment_response` | filter | Provide activation data in response to a Nexway fulfillment call |
