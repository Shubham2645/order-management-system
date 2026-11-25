<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'
import { Chart as ChartJS, ArcElement, Tooltip, Legend, CategoryScale, LinearScale, PointElement, LineElement } from 'chart.js'
import { Doughnut, Line } from 'vue-chartjs'

ChartJS.register(ArcElement, Tooltip, Legend, CategoryScale, LinearScale, PointElement, LineElement)
const loading = ref(true)
const error = ref('')
const stats = ref<any>({})
const groupBy = ref('day')

function dateValue(date: Date) {
  const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000)
  return localDate.toISOString().slice(0, 10)
}

const today = new Date()
const fromDate = ref(dateValue(new Date(today.getTime() - 29 * 24 * 60 * 60 * 1000)))
const toDate = ref(dateValue(today))
const rangeInvalid = computed(() => toDate.value < fromDate.value)
const cents = (value: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value / 100)

async function load() {
  if (rangeInvalid.value) {
    error.value = 'End date must be on or after start date.'
    return
  }
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/dashboard', {
      params: { from_date: fromDate.value, to_date: toDate.value, group_by: groupBy.value },
    })
    stats.value = data.data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to load dashboard.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section>
    <header class="page-header"><div><p class="eyebrow">Overview</p><h1>Dashboard</h1></div></header>
    <form class="dashboard-filters" @submit.prevent="load">
      <label>From<input v-model="fromDate" type="date" required /></label>
      <label>To<input v-model="toDate" type="date" required /></label>
      <label>Revenue grouping<select v-model="groupBy"><option value="day">Daily</option><option value="week">Weekly</option><option value="month">Monthly</option></select></label>
      <button class="primary" :disabled="loading || rangeInvalid">Apply</button>
    </form>
    <div v-if="error" class="alert error">{{ error }}</div>
    <div v-if="loading" class="loading">Loading dashboard...</div>
    <template v-else-if="stats.total_orders !== undefined">
      <p class="range-caption">Showing order and revenue data from {{ stats.from_date }} to {{ stats.to_date }}.</p>
      <div class="metrics">
        <article v-for="card in [
          ['Total orders', stats.total_orders],
          ['Pending', stats.pending_orders],
          ['Confirmed', stats.confirmed_orders],
          ['Processing', stats.processing_orders],
          ['Ready', stats.ready_orders],
          ['Completed', stats.completed_orders],
          ['Cancelled', stats.cancelled_orders],
          ['Revenue', cents(stats.total_revenue_cents)],
          ['Average order', cents(stats.average_order_value_cents)],
          ['Customers', stats.total_customers],
          ['Products', stats.total_products],
        ]" :key="card[0]"><span>{{ card[0] }}</span><strong>{{ card[1] }}</strong></article>
      </div>
      <div class="charts">
        <article class="panel">
          <h2>Order status</h2>
          <Doughnut :data="{ labels: Object.keys(stats.status_distribution), datasets: [{ data: Object.values(stats.status_distribution), backgroundColor: ['#94a3b8','#60a5fa','#f59e0b','#8b5cf6','#10b981','#ef4444','#64748b'] }] }" :options="{ responsive: true, maintainAspectRatio: false }" />
        </article>
        <article class="panel">
          <h2>{{ groupBy.charAt(0).toUpperCase() + groupBy.slice(1) }} revenue trend</h2>
          <Line :data="{ labels: stats.revenue_trend.map((x:any) => x.period), datasets: [{ label: 'Revenue', data: stats.revenue_trend.map((x:any) => Number(x.revenue_cents) / 100), borderColor: '#2563eb', tension: .3 }] }" :options="{ responsive: true, maintainAspectRatio: false }" />
        </article>
      </div>
    </template>
  </section>
</template>
