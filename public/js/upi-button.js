/**
 * UPI Gateway checkout button.
 *
 * Drop-in button:
 *   <script src="https://YOUR-GATEWAY/js/upi-button.js" data-page="PAGE_ID" async></script>
 *
 * Programmatic checkout for an order created on your server through the API:
 *   UPIGateway.open({ paymentUrl: order.payment_url, onSuccess: function (result) {} });
 *
 * Checkout opens in a secure window on the gateway's own domain. The callbacks
 * are for updating your page only: ALWAYS confirm the payment on your server
 * (signed webhook or Order Status API) before delivering anything.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var scriptUrl = new URL(script.src, window.location.href);
    var base = scriptUrl.href.replace(/js\/upi-button\.js.*$/, '');
    var gatewayOrigin = scriptUrl.origin;
    var active = null;

    function el(tag, style, text) {
        var node = document.createElement(tag);
        if (style) node.style.cssText = style;
        if (text) node.textContent = text;
        return node;
    }

    function open(options) {
        options = options || {};
        if (active) {
            active.popup.focus();
            return;
        }

        var url = new URL(options.paymentUrl || base + 'p/' + encodeURIComponent(options.page || ''), base);
        if (url.origin !== gatewayOrigin) throw new Error('UPIGateway: paymentUrl must belong to ' + gatewayOrigin);
        var plainUrl = url.href;
        url.searchParams.set('embed_origin', window.location.origin);

        var width = 460;
        var height = Math.min(760, window.screen.availHeight - 40);
        var left = Math.max(0, window.screenX + (window.outerWidth - width) / 2);
        var top = Math.max(0, window.screenY + (window.outerHeight - height) / 2);
        var popup = window.open(url.href, 'upi_gateway_checkout',
            'popup=yes,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);

        // Popup blocked: continue in this tab instead.
        if (!popup) {
            window.location.assign(plainUrl);
            return;
        }

        var overlay = el('div', 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.75);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Payment in progress');
        var card = el('div', 'box-sizing:border-box;width:100%;max-width:360px;padding:28px 24px;border-radius:16px;background:#fff;color:#0f172a;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.3)');
        var title = el('div', 'font-size:18px;font-weight:700;margin-bottom:8px', 'Complete your payment');
        var message = el('div', 'font-size:14px;line-height:1.5;color:#64748b;margin-bottom:20px', 'Finish paying in the secure checkout window.');
        var primary = el('button', 'display:block;width:100%;padding:12px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:14px;font-weight:600;cursor:pointer', 'Show payment window');
        var secondary = el('button', 'display:block;width:100%;margin-top:8px;padding:10px;border:0;background:none;color:#64748b;font-size:13px;cursor:pointer', 'Cancel');
        primary.type = secondary.type = 'button';
        card.appendChild(title);
        card.appendChild(message);
        card.appendChild(primary);
        card.appendChild(secondary);
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        primary.focus();

        var result = null;
        var previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        function emit(name, callback, detail) {
            if (typeof callback === 'function') callback(detail);
            document.dispatchEvent(new CustomEvent('upi-gateway:' + name, { detail: detail }));
        }

        function close() {
            if (!active) return;
            active = null;
            clearInterval(watcher);
            window.removeEventListener('message', onMessage);
            document.body.style.overflow = previousOverflow;
            overlay.remove();
            if (!popup.closed) popup.close();
            if (!result || result.status !== 'paid') emit('dismiss', options.onDismiss, result);
        }

        function onMessage(event) {
            if (event.origin !== gatewayOrigin || event.source !== popup) return;
            var data = event.data;
            if (!data || data.source !== 'upi-gateway') return;
            if (result && result.status === data.status) return;
            result = data;

            var copy = {
                paid: ['Payment successful', 'Thank you. Your payment has been received.'],
                review: ['Payment under review', 'The merchant is verifying your payment. Do not pay again.'],
                failed: ['Payment failed', 'The payment did not go through.'],
                expired: ['Payment expired', 'If money was debited, do not pay again. Contact the merchant.']
            }[data.status];
            if (!copy) return;

            title.textContent = copy[0];
            message.textContent = copy[1];
            primary.textContent = 'Done';
            secondary.style.display = 'none';
            primary.onclick = close;

            if (data.status === 'paid') emit('success', options.onSuccess, data);
            else emit('status', options.onStatus, data);
        }

        primary.onclick = function () { popup.focus(); };
        secondary.onclick = close;
        window.addEventListener('message', onMessage);
        var watcher = setInterval(function () {
            // Once a result is showing, the overlay stays until "Done".
            if (popup.closed && !result) close();
            else if (popup.closed) {
                primary.textContent = 'Done';
                primary.onclick = close;
                secondary.style.display = 'none';
            }
        }, 500);
        active = { popup: popup, close: close };
    }

    window.UPIGateway = window.UPIGateway || { open: open, close: function () { if (active) active.close(); } };

    // Render a button where the script tag sits.
    var page = script.getAttribute('data-page');
    if (!page) return;

    var color = script.getAttribute('data-color') || '';
    if (!/^#[0-9a-f]{6}$/i.test(color)) color = '#2563eb';

    var button = el('button', 'display:inline-flex;flex-direction:column;align-items:center;gap:2px;min-width:180px;padding:11px 28px;border:0;border-radius:6px;color:#fff;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;cursor:pointer;box-shadow:0 2px 6px rgba(15,23,42,.2)');
    button.type = 'button';
    button.className = 'upi-gateway-button';
    button.style.backgroundColor = color;
    button.appendChild(el('span', 'font-size:15px;font-weight:700;line-height:1.3', script.getAttribute('data-label') || 'Pay Now'));
    button.appendChild(el('span', 'font-size:10px;font-weight:500;opacity:.8;line-height:1.3', 'Secured by UPI'));
    button.addEventListener('click', function () { open({ page: page }); });
    script.parentNode.insertBefore(button, script.nextSibling);
})();
