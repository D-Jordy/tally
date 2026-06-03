<template>
    <Head title="Dividends" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Incoming Dividends</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">

                <!-- Summary cards -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Expected next 12 months</p>
                        <p class="mt-1 text-2xl font-semibold text-green-600">{{ eur(summary.next_12m_total_eur) }}</p>
                        <p class="mt-1 text-xs text-gray-400">gross, before withholding tax</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Received last 12 months</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ eur(summary.trailing_12m_received_eur) }}</p>
                        <p class="mt-1 text-xs text-gray-400">net dividends booked</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm col-span-2 sm:col-span-1">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Dividend-paying holdings</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ summary.instrument_count }}</p>
                        <p class="mt-1 text-xs text-gray-400">open positions with forecast data</p>
                    </div>
                </div>

                <!-- Empty state -->
                <template v-if="events.length === 0">
                    <div class="rounded-lg bg-white p-10 text-center shadow-sm">
                        <p class="text-gray-700 font-medium">No dividend forecast yet.</p>
                        <p class="mt-1 text-sm text-gray-500">
                            Import your transactions, then run <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">php artisan dividends:fetch</code> to populate dividend history.
                        </p>
                        <Link
                            :href="route('accounts.index')"
                            class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            Go to Accounts
                        </Link>
                    </div>
                </template>

                <template v-else>
                    <!-- Monthly bar chart -->
                    <DividendChart :monthly="monthly" />

                    <!-- Upcoming events table -->
                    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b bg-gray-50 text-xs font-medium uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Instrument</th>
                                    <th class="px-4 py-3 text-right">Ex-date</th>
                                    <th class="px-4 py-3 text-right">Pay-date</th>
                                    <th class="px-4 py-3 text-right">Per share</th>
                                    <th class="px-4 py-3 text-right">Qty</th>
                                    <th class="px-4 py-3 text-right">Expected (EUR)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr
                                    v-for="(ev, i) in events"
                                    :key="i"
                                    class="hover:bg-gray-50"
                                >
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 italic">{{ ev.name }}</div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs text-gray-400">{{ ev.yahoo_symbol }}</span>
                                            <span class="rounded bg-violet-100 px-1 py-0.5 text-xs font-medium text-violet-600">projected</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ ev.ex_date }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ ev.pay_date ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-600">
                                        {{ fmt(ev.amount_per_share, ev.currency) }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ ev.quantity }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium">
                                        <span v-if="ev.expected_eur != null" class="text-green-600">
                                            {{ eur(ev.expected_eur) }}
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import DividendChart from '@/Components/DividendChart.vue'

defineProps({
    events:  { type: Array,  required: true },
    monthly: { type: Array,  required: true },
    summary: { type: Object, required: true },
})

const eurFmt = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 })

function eur(v) { return v != null ? eurFmt.format(v) : '—' }
function fmt(price, currency) {
    if (price == null) return '—'
    try {
        return new Intl.NumberFormat('nl-NL', {
            style: 'currency',
            currency: currency ?? 'EUR',
            maximumFractionDigits: 4,
        }).format(price)
    } catch {
        return price
    }
}
</script>
