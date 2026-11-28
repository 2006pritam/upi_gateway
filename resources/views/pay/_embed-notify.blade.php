{{-- Tells the website that opened this checkout popup what happened. --}}
@if (($embedOrigin ?? null) && $order->status !== 'pending')
@push('scripts')
<script nonce="{{ $csp_nonce }}">
window.opener && window.opener.postMessage({
    source: 'upi-gateway',
    order_id: @json($order->order_no),
    client_order_id: @json($order->client_order_id),
    status: @json($order->status),
    test: @json($order->environment === 'sandbox')
}, @json($embedOrigin));
</script>
@endpush
@endif
