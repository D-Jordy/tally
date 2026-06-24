<template>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-medium uppercase tracking-wider text-gray-500">
                Expected dividend income — next 12 months
            </h3>
        </div>

        <VueApexCharts
            type="bar"
            height="220"
            :options="chartOptions"
            :series="series"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps({
    monthly: { type: Array, required: true },
})

const eurFmt = new Intl.NumberFormat('nl-NL', {
    style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
})

const series = computed(() => [{
    name: 'Expected dividends',
    data: props.monthly.map(m => ({
        x: m.month,
        y: m.expected_eur,
    })),
}])

const chartOptions = computed(() => ({
    chart: {
        type: 'bar',
        toolbar: { show: false },
        animations: { enabled: false },
        fontFamily: 'inherit',
    },
    colors: ['#8b5cf6'],
    plotOptions: {
        bar: { borderRadius: 3, columnWidth: '60%' },
    },
    dataLabels: { enabled: false },
    xaxis: {
        type: 'category',
        labels: { style: { fontSize: '11px' } },
    },
    yaxis: {
        labels: {
            formatter: v => eurFmt.format(v),
            style: { fontSize: '11px' },
        },
        min: 0,
    },
    tooltip: {
        y: { formatter: v => eurFmt.format(v) },
    },
    grid: { strokeDashArray: 3, borderColor: '#e5e7eb' },
}))
</script>
