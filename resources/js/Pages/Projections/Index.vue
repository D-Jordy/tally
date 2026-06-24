<template>
    <Head title="Projections" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Projections</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">

                <!-- Controls row: horizon + contribution -->
                <div class="flex flex-wrap items-start gap-4">
                    <!-- Horizon selector -->
                    <div class="rounded-lg bg-white p-4 shadow-sm flex items-center gap-3">
                        <span class="text-sm font-medium text-gray-600">Horizon:</span>
                        <div class="flex gap-1">
                            <button
                                v-for="h in [1, 3, 5, 10]"
                                :key="h"
                                @click="setHorizon(h)"
                                class="rounded px-3 py-1 text-sm font-medium transition-colors"
                                :class="horizon_years === h
                                    ? 'bg-gray-800 text-white'
                                    : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                            >
                                {{ h }}y
                            </button>
                        </div>
                    </div>

                    <!-- Annual contribution input -->
                    <div class="rounded-lg bg-white p-4 shadow-sm flex items-center gap-3">
                        <span class="text-sm font-medium text-gray-600">Annual contribution:</span>
                        <div class="flex rounded-md shadow-sm">
                            <span class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2 text-sm text-gray-500">€</span>
                            <input
                                v-model.number="contributionInput"
                                type="number"
                                min="0"
                                step="100"
                                class="w-28 rounded-none border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                placeholder="0"
                            />
                            <select
                                v-model="cadence"
                                class="rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"
                            >
                                <option value="year">/ year</option>
                                <option value="month">/ month</option>
                            </select>
                        </div>
                        <button
                            @click="saveContribution"
                            class="rounded-md bg-indigo-600 px-3 py-1 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            Save
                        </button>
                    </div>
                </div>

                <!-- Empty state -->
                <template v-if="starting_value_eur === 0">
                    <div class="rounded-lg bg-white p-10 text-center shadow-sm">
                        <p class="text-gray-700 font-medium">No portfolio data yet.</p>
                        <p class="mt-1 text-sm text-gray-500">Import your transactions to see projections.</p>
                        <Link
                            :href="route('accounts.index')"
                            class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            Go to Accounts
                        </Link>
                    </div>
                </template>

                <template v-else>
                    <!-- Summary cards -->
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Current portfolio</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ eur(starting_value_eur) }}</p>
                        </div>
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Projected in {{ horizon_years }}y</p>
                            <p class="mt-1 text-2xl font-semibold text-indigo-600">{{ eur(finalValue) }}</p>
                            <p class="mt-1 text-xs text-gray-400">incl. {{ eur(annual_contribution_eur) }}/yr contribution</p>
                        </div>
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Blended growth rate</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ pct(growth_rate) }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ pct(prior_rate) }} prior · {{ pct(analyst_rate) }} analyst</p>
                        </div>
                        <div class="rounded-lg bg-white p-5 shadow-sm">
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Dividends in {{ horizon_years }}y</p>
                            <p class="mt-1 text-2xl font-semibold text-green-600">{{ eur(finalDividend) }}</p>
                            <p class="mt-1 text-xs text-gray-400">projected annual income</p>
                        </div>
                    </div>

                    <!-- Charts -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <ProjectionChart
                            title="Portfolio value"
                            series-name="Projected value"
                            color="#6366f1"
                            :data="value_series.map(d => ({ year: d.year, value: d.projected_value_eur }))"
                        />
                        <ProjectionChart
                            title="Annual dividend income"
                            series-name="Projected dividends"
                            color="#22c55e"
                            :data="dividend_series.map(d => ({ year: d.year, value: d.projected_dividends_eur }))"
                        />
                    </div>

                    <!-- Methodology note -->
                    <div class="rounded-lg bg-gray-50 border border-gray-200 px-4 py-3 text-xs text-gray-500">
                        Growth rate is a 50/50 blend of your portfolio's historical annualised return (XIRR) and
                        position-weighted analyst target prices from Yahoo Finance.
                        Projections are estimates — actual returns will vary.
                    </div>
                </template>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import ProjectionChart from '@/Components/ProjectionChart.vue'

const props = defineProps({
    horizon_years:            { type: Number, required: true },
    growth_rate:              { type: Number, required: true },
    prior_rate:               { type: Number, required: true },
    analyst_rate:             { type: Number, required: true },
    annual_contribution_eur:  { type: Number, required: true },
    starting_value_eur:       { type: Number, required: true },
    value_series:             { type: Array,  required: true },
    dividend_series:          { type: Array,  required: true },
})

const contributionInput = ref(props.annual_contribution_eur)
const cadence           = ref('year')

const finalValue    = computed(() => props.value_series.at(-1)?.projected_value_eur ?? 0)
const finalDividend = computed(() => props.dividend_series.at(-1)?.projected_dividends_eur ?? 0)

const eurFmt = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 })
const pctFmt = new Intl.NumberFormat('nl-NL', { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 })

function eur(v) { return v != null ? eurFmt.format(v) : '—' }
function pct(v) { return v != null ? pctFmt.format(v) : '—' }

function setHorizon(h) {
    router.get(route('projections'), { horizon: h }, { preserveState: false })
}

function saveContribution() {
    router.patch(route('projections.settings'), {
        contribution: contributionInput.value,
        cadence: cadence.value,
    }, { preserveScroll: true })
}
</script>
