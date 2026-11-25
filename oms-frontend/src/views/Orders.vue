<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const canAssign = ['admin', 'manager'].includes(auth.user?.role || '')
const loading = ref(true)
const error = ref('')
const orders = ref<any[]>([])
const customers = ref<any[]>([])
const staff = ref<any[]>([])
const meta = ref<any>({})
const page = ref(1)
let debounceTimer: ReturnType<typeof setTimeout> | undefined
let requestVersion = 0
const filters = reactive({
  search: '',
  status: '',
  priority: '',
  customer_id: '',
  assigned_to: '',
  from_date: '',
  to_date: '',
  sort: 'order_date',
  direction: 'desc',
})

async function load() {
  const version = ++requestVersion
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/orders', { params: { ...filters, page: page.value, per_page: 15 } })
    if (version !== requestVersion) return
    orders.value = data.data.data
    meta.value = data.data
  } catch (e: any) {
    if (version === requestVersion) error.value = e.response?.data?.message || 'Unable to load orders.'
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

function scheduleLoad(resetPage = false) {
  if (resetPage && page.value !== 1) page.value = 1
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => { void load() }, 250)
}

watch(filters, () => scheduleLoad(true))
watch(page, () => scheduleLoad())
onMounted(async () => {
  void load()
  try {
    const customerResponse = await api.get('/customers', { params: { per_page: 100 } })
    customers.value = customerResponse.data.data.data
    if (canAssign) staff.value = (await api.get('/users/assignable')).data.data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to load filter options.'
  }
})

function resetFilters() {
  Object.assign(filters, {
    search: '', status: '', priority: '', customer_id: '',
    assigned_to: '', from_date: '', to_date: '',
    sort: 'order_date', direction: 'desc',
  })
}

function changePage(nextPage: number) {
  if (nextPage >= 1 && nextPage <= meta.value.last_page) page.value = nextPage
}

const cents = (value: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value / 100)
</script>

<template>
  <section>
    <header class="page-header">
      <div><p class="eyebrow">Operations</p><h1>Orders</h1></div>
      <RouterLink v-if="canAssign" to="/orders/new" class="primary">Create order</RouterLink>
    </header>
    <div class="order-filters">
      <input v-model="filters.search" placeholder="Search order, customer, staff, or product..." aria-label="Search orders" />
      <select v-model="filters.status" aria-label="Filter by status"><option value="">All statuses</option><option>draft</option><option>pending</option><option>confirmed</option><option>processing</option><option>ready</option><option>completed</option><option>cancelled</option></select>
      <select v-model="filters.priority" aria-label="Filter by priority"><option value="">All priorities</option><option>low</option><option>normal</option><option>high</option><option>urgent</option></select>
      <select v-model="filters.customer_id" aria-label="Filter by customer"><option value="">All customers</option><option v-for="customer in customers" :key="customer.id" :value="String(customer.id)">{{ customer.name }}</option></select>
      <select v-if="canAssign" v-model="filters.assigned_to" aria-label="Filter by assignee"><option value="">All assignees</option><option value="unassigned">Unassigned</option><option v-for="user in staff" :key="user.id" :value="String(user.id)">{{ user.name }}</option></select>
      <label>From date<input v-model="filters.from_date" type="date" aria-label="From date" /></label>
      <label>To date<input v-model="filters.to_date" type="date" aria-label="To date" /></label>
      <select v-model="filters.sort" aria-label="Sort orders by"><option value="order_date">Order date</option><option value="total_cents">Total</option><option value="status">Status</option><option value="priority">Priority</option></select>
      <select v-model="filters.direction" aria-label="Sort direction"><option value="desc">Descending</option><option value="asc">Ascending</option></select>
      <button class="secondary-button" @click="resetFilters">Reset filters</button>
    </div>
    <div v-if="error" class="alert error">{{ error }}</div>
    <div v-if="loading" class="loading">Loading orders...</div>
    <div v-else-if="!orders.length" class="empty"><h2>No orders found</h2><p>Try changing your search or filters.</p></div>
    <div v-else class="table-wrap">
      <table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Priority</th><th>Assigned</th><th>Order date</th><th>Total</th></tr></thead><tbody>
        <tr v-for="order in orders" :key="order.id" @click="$router.push(`/orders/${order.id}`)">
          <td><strong>{{ order.order_number }}</strong></td><td>{{ order.customer?.name }}</td><td><span :class="['badge', order.status]">{{ order.status }}</span></td><td>{{ order.priority }}</td><td>{{ order.assignee?.name || 'Unassigned' }}</td><td>{{ String(order.order_date).slice(0, 10) }}</td><td>{{ cents(order.total_cents) }}</td>
        </tr>
      </tbody></table>
    </div>
    <div v-if="meta.last_page > 1" class="pagination">
      <span>Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}</span>
      <div><button class="secondary-button" :disabled="page <= 1" @click="changePage(page - 1)">Previous</button><span>Page {{ page }} of {{ meta.last_page }}</span><button class="secondary-button" :disabled="page >= meta.last_page" @click="changePage(page + 1)">Next</button></div>
    </div>
  </section>
</template>
