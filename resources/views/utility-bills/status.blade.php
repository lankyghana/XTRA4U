{{--
    Customer status page / receipt for a Utility Bill. Reached by an opaque token
    (never an account number). Shows only masked identifiers. The headline says
    "successful" only once the provider has reported the bill completed.
--}}
@extends('layouts.app')

@section('title', 'Utility Bill '.$view['reference'].' - XTRA4U')
@section('body-class', 'x4')

@section('site-header')
    <x-storefront.header :shop-url="route('services.utility-bills')" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="route('services.utility-bills')" />
@endsection

@section('content')
<div class="x4-page" style="padding-top: 64px;"
     x-data="{ v: @js($view), async refresh() { if (this.v.terminal) return; try { const r = await fetch('{{ route('utility-bills.poll', ['token' => $u->access_token]) }}', {headers:{Accept:'application/json'}}); if (r.ok) this.v = await r.json(); } catch (e) {} if (!this.v.terminal) setTimeout(() => this.refresh(), 5000); } }"
     x-init="setTimeout(() => refresh(), 5000)">
    <div class="max-w-xl mx-auto px-4 sm:px-5" style="padding-top: 40px; padding-bottom: 72px;">
        <div style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 24px; box-shadow: var(--x4-shadow-1);">
            <div role="status" aria-live="polite">
                <p class="text-xs uppercase tracking-wide mb-1" style="color: var(--x4-ink-sec);">Utility Bills</p>
                <h1 class="x4-heading-md mb-1" style="color: var(--x4-ink);" x-text="v.headline"></h1>
                <p class="text-sm mb-4" style="color: var(--x4-ink-body);" x-text="v.detail"></p>
            </div>

            <dl class="text-sm" style="border-top:1px solid var(--x4-hairline);">
                @foreach ([
                    ['Reference', 'reference'], ['Bill', 'biller'], ['Account', 'account_masked'],
                    ['Account name', 'account_name'], ['Amount', 'amount'], ['Status', 'status_label'], ['Date', 'date'],
                ] as [$label, $key])
                    <div class="flex justify-between gap-4 py-2" style="border-bottom:1px solid var(--x4-hairline);" x-show="v['{{ $key }}']">
                        <dt style="color: var(--x4-ink-sec);">{{ $label }}</dt>
                        <dd class="text-right font-medium" style="color: var(--x4-ink);"
                            @if ($key === 'amount') x-text="v.currency + ' ' + v.amount" @else x-text="v['{{ $key }}']" @endif></dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 flex flex-wrap gap-3">
                <button type="button" class="font-semibold" x-show="v.stage === 'completed'" onclick="window.print()"
                        style="background:var(--x4-violet);color:#fff;border-radius:12px;padding:12px 18px;min-height:44px;">Print receipt</button>
                <a href="{{ route('services.utility-bills') }}" class="font-semibold"
                   style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 18px;min-height:44px;color:var(--x4-ink);">Pay another bill</a>
            </div>
            <p class="text-xs mt-4" style="color: var(--x4-ink-sec);">Keep your reference. Quote it if you need help.</p>
        </div>
    </div>
</div>
@endsection
