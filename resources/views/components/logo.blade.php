@props(['dark' => false])
<span {{ $attributes->merge(['class' => 'brand-lockup inline-flex items-center gap-2.5']) }}>
    <img src="{{ asset('images/emudra-mark.svg') }}" class="h-9 w-9 shrink-0" alt="" aria-hidden="true">
    <span class="brand-wordmark font-extrabold tracking-[-0.045em] text-[19px] leading-none {{ $dark ? 'text-white' : 'text-slate-900' }}">
        <span class="brand-wordmark-e {{ $dark ? 'text-emerald-300' : 'text-brand-600' }}">e</span><span>Mudra</span>
    </span>
</span>
