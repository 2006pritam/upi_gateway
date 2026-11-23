@props(['dark' => false])
<span {{ $attributes->merge(['class' => 'brand-lockup inline-flex items-center gap-2.5']) }}>
    <span @class(['inline-flex rounded-md', 'brand-logo-pad px-1.5 py-1 shadow-sm' => $dark])>
        <img src="{{ asset('images/emudra-secure-payment-logo.png') }}" class="block h-10 w-auto max-w-[220px] object-contain sm:h-11" alt="eMudra Secure Payment Gateway">
    </span>
</span>
