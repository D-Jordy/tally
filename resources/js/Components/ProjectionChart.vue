<template>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <h3 class="mb-3 text-sm font-medium uppercase tracking-wider text-gray-500">{{ title }}</h3>
        <VueApexCharts
            type="area"
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
    title:      { type: String, required: true },
    seriesName: { type: String, required: true },
    color:      { type: String, default: '#3b82f6' },
    data:       { type: Array,  required: true },  // [{ year, value }, ...]
})

const eurFmt = new Intl.NumberFormat('nl-NL', {
    style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
})

const series = computed(() => [{
    name: props.seriesName,
    data: props.data.map(d => [String(d.year), d.value]),
}])

const chartOptions = computed(() => ({
    chart: {
        type: 'area',
        toolbar: { show: false },
        animations: { enabled: false },
        fontFamily: 'inherit',
    },
    colors: [props.color],
    stroke: { curve: 'smooth', width: 2 },
    fill: {
        type: 'gradient',
        gradient: { opacityFrom: 0.35, opacityTo: 0.05 },
    },
    markers: { size: 4 },
    xaxis: {
        type: 'category',
        labels: {
            formatter: v => v === '0' ? 'Now' : `+${v}y`,
            style: { fontSize: '11px' },
        },
    },
    yaxis: {
        labels: {
            formatter: v => eurFmt.format(v),
            style: { fontSize: '11px' },
        },
    },
    tooltip: {
        x: { formatter: v => v === '0' ? 'Now' : `Year ${v}` },
        y: { formatter: v => eurFmt.format(v) },
    },
    grid: { strokeDashArray: 3, borderColor: '#e5e7eb' },
    legend: { show: false },
}))
</script>
