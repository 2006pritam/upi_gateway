# Pay button and payment page

## Deploying

This release adds a database migration. Back up the database, set `RUN_MIGRATIONS=true` on Render,
deploy, then set it back to `false`. Other hosts: `php artisan migrate --force`.

## Pay button

Dashboard → **Payment links** → open **Pay button for your website** on a link and copy the code:

```html
<script src="https://YOUR-GATEWAY/js/upi-button.js" data-page="LINK_CODE"
        data-label="Pay Now" data-color="#2563eb" async></script>
```

Checkout opens in a popup on the gateway's domain, with a dimmed overlay on your site.
If the browser blocks the popup, checkout opens in the same tab.

## Checkout for API orders

Create the order on your server, send `payment_url` to the browser, then call this from a click handler:

```js
UPIGateway.open({
    paymentUrl: paymentUrl,
    onSuccess: function (r) { /* verify r.order_id on your server */ },
    onStatus: function (r) { /* review | failed | expired */ },
    onDismiss: function () {}
});
```

Load `/js/upi-button.js` first. Create the order before the click: browsers block popups that open
after a network request.

**Never deliver goods based on a browser callback.** Confirm with the signed webhook or the Order
Status API, and check the amount.

## Notes

- A website sending `Cross-Origin-Opener-Policy: same-origin` will not receive callbacks.
  Use `same-origin-allow-popups`.
- With a strict Content-Security-Policy, allow the gateway origin in `script-src`.
