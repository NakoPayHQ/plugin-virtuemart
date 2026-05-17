# NakoPay for VirtueMart

Accept Bitcoin and other crypto on your Joomla VirtueMart store through [NakoPay](https://nakopay.com).

- Stripe-style API: invoices created server-side, polled and webhook-notified.
- Signed webhooks (HMAC-SHA256, 5-minute replay window).
- Sandbox/test mode for safe development.
- Direct-to-wallet settlement - 1% flat fee.

## Requirements

- Joomla 4.x or 5.x
- VirtueMart 4.x+
- PHP 8.1+
- PHP `curl` extension
- HTTPS in production
- A NakoPay account (free) - <https://nakopay.com/dashboard/api-keys>

## Download

| # | Source | When to use |
|---|--------|-------------|
| 1 | **GitHub Releases zip** - <https://github.com/NakoPayHQ/plugin-virtuemart/releases/latest/download/nakopay-virtuemart.zip> | Download `nakopay-virtuemart.zip`. |
| 2 | **Build from source** | Clone this repo and copy the files manually. |

## Install

1. Download `nakopay-virtuemart.zip`.

2. In the Joomla admin, go to **System - Install - Extensions** and upload the zip.

   Alternatively, copy the files manually:
   ```
   plugins/vmpayment/nakopay/
       nakopay.php
       nakopay.xml
       nakopay/
           NakoPayApi.php
           NakoPayWebhook.php
       language/en-GB/
           en-GB.plg_vmpayment_nakopay.ini
   ```

3. Go to **Extensions - Plugins**, find **VM Payment - NakoPay**, and enable it.

4. In VirtueMart admin, go to **Shop - Payment Methods**, create a new method:
   - Payment Method: **NakoPay**
   - Published: **Yes**

5. In the configuration tab, enter:
   - **API Key** - your `sk_test_*` or `sk_live_*` key from nakopay.com/dashboard/api-keys
   - **Webhook Secret** - the `whsec_*` value from nakopay.com/dashboard/webhooks

6. Set up the webhook in your NakoPay dashboard:
   - Go to nakopay.com/dashboard/webhooks
   - Add endpoint: `https://YOUR-DOMAIN/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=nakopay`
   - Subscribe to: `invoice.paid`, `invoice.expired`, `invoice.refunded`

7. Run a test order with `sk_test_*` keys before switching to `sk_live_*`.

## Configuration

| Setting | Description |
|---------|-------------|
| API Key | Your NakoPay secret key (`sk_test_*` or `sk_live_*`) |
| Webhook Secret | Used to verify incoming webhook signatures (`whsec_*`) |
| Sandbox Mode | Enable to test without real funds |
| Payment Name | Label shown at checkout (default: "Pay with Bitcoin") |

## Webhook Setup

Your webhook endpoint:

```
https://YOUR-DOMAIN/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=nakopay
```

VirtueMart routes plugin notification callbacks automatically. Make sure the URL is reachable over HTTPS.

### Supported events

- `invoice.paid` - confirms VirtueMart order
- `invoice.expired` - cancels order
- `invoice.refunded` - triggers refund flow

## How it works

1. Customer selects "Pay with Bitcoin" at VirtueMart checkout.
2. The plugin calls the NakoPay `invoices-create` endpoint with the order amount, currency, description, and customer email.
3. NakoPay returns a `checkout_url` - the customer is redirected there to pay in Bitcoin.
4. After payment, NakoPay sends a signed webhook to the plugin notification URL.
5. The plugin verifies the HMAC-SHA256 signature, maps the event type to a VirtueMart order status, and updates the order.

## Testing

1. Use `sk_test_*` keys - test invoices are free and never settle on-chain.
2. Check the Joomla admin system logs while paying to see the webhook fire.
3. Resend webhooks from nakopay.com/dashboard/webhooks if needed.

## Troubleshooting

**Order stays pending after customer pays:**
- Check that the webhook URL is correct and reachable over HTTPS.
- Verify the webhook secret matches what's in your NakoPay dashboard.
- Check Joomla error logs for signature verification failures.

**Plugin not showing at checkout:**
- Make sure the Joomla plugin is enabled at Extensions - Plugins.
- Make sure a VirtueMart payment method is created and published.

**Webhook signature mismatch:**
- Re-copy the `whsec_*` value from nakopay.com/dashboard/webhooks.
- Make sure your server clock is accurate (NTP enabled) - signatures expire after 5 minutes.

## Support

- Issues: <https://github.com/NakoPayHQ/plugin-virtuemart/issues>
- Email: [support@nakopay.com](mailto:support@nakopay.com)
- Website: <https://nakopay.com>

## About VirtueMart

[VirtueMart](https://virtuemart.net/) is a free, open-source e-commerce solution for Joomla. It is one of the most popular Joomla shopping cart extensions with a long track record.

## License

MIT - see [LICENSE](LICENSE).
