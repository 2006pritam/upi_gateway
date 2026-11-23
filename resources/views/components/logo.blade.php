@props(['dark' => false, 'compact' => false])
<span {{ $attributes->merge(['class' => 'brand-lockup inline-flex items-center gap-2.5']) }}>
    <span @class([
        'inline-flex rounded-md',
        'brand-logo-pad shadow-sm' => $dark,
        'px-1 py-0.5' => $dark && $compact,
        'px-1.5 py-1' => $dark && ! $compact,
    ])>
        <img src="{{ asset('images/emudra-secure-payment-logo.png') }}" class="block {{ $compact ? 'h-8 max-w-[160px]' : 'h-10 max-w-[220px] sm:h-11' }} w-auto object-contain" alt="eMudra Secure Payment Gateway">
    </span>
</span>
