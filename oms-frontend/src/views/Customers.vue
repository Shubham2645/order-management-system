<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const canManage = auth.user?.role === 'admin'
const rows = ref<any[]>([])
const meta = ref<any>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const activeFilter = ref('')
const page = ref(1)
const modal = ref<'form' | 'detail' | null>(null)
const editId = ref<number | null>(null)
const selectedCustomer = ref<any>(null)
const detailLoading = ref(false)
const form = reactive({ name: '', email: '', phone: '', address: '', is_active: true })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/customers', {
      params: {
        search: search.value || undefined,
        is_active: activeFilter.value || undefined,
        page: page.value,
        per_page: 15,
      },
    })
    rows.value = data.data.data
    meta.value = data.data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to load customers.'
  } finally {
    loading.value = false
  }
}

watch([search, activeFilter], () => { page.value = 1 })
watch([search, activeFilter, page], load)
onMounted(load)

function openCreate() {
  editId.value = null
  Object.assign(form, { name: '', email: '', phone: '', address: '', is_active: true })
  error.value = ''
  modal.value = 'form'
}

function openEdit(customer: any) {
  editId.value = customer.id
  Object.assign(form, {
    name: customer.name,
    email: customer.email,
    phone: customer.phone || '',
    address: customer.address || '',
    is_active: customer.is_active,
  })
  error.value = ''
  modal.value = 'form'
}

async function openDetail(customer: any) {
  modal.value = 'detail'
  detailLoading.value = true
  error.value = ''
  try {
    selectedCustomer.value = (await api.get(`/customers/${customer.id}`)).data.data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to load customer details.'
  } finally {
    detailLoading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const payload = { ...form }
    if (editId.value) await api.put(`/customers/${editId.value}`, payload)
    else await api.post('/customers', payload)
    notice.value = editId.value ? 'Customer updated.' : 'Customer created.'
    modal.value = null
    await load()
  } catch (e: any) {
    const errors = e.response?.data?.errors
    error.value = errors ? Object.values(errors).flat()[0] as string : e.response?.data?.message || 'Unable to save customer.'
  } finally {
    saving.value = false
  }
}

async function remove(customer: any) {
  if (!window.confirm(`Remove ${customer.name}? Customers with orders will be deactivated.`)) return
  error.value = ''
  notice.value = ''
  try {
    const { data } = await api.delete(`/customers/${customer.id}`)
    notice.value = data.message
    await load()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to remove customer.'
  }
}

function changePage(nextPage: number) {
  if (nextPage >= 1 && nextPage <= meta.value.last_page) page.value = nextPage
}

const cents = (value: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value / 100)
</script>

<template>
  <section>
    <header class="page-header">
      <div><p class="eyebrow">Directory</p><h1>Customers</h1></div>
      <button v-if="canManage" class="primary" @click="openCreate">Add customer</button>
    </header>
    <div v-if="notice" class="alert success">{{ notice }}</div>
    <div v-if="error && !modal" class="alert error">{{ error }}</div>
    <div class="toolbar">
      <input v-model="search" placeholder="Search customer name or email..." />
      <select v-model="activeFilter"><option value="">All customers</option><option value="1">Active</option><option value="0">Inactive</option></select>
    </div>
    <div v-if="loading" class="loading">Loading customers...</div>
    <div v-else-if="error && !rows.length" class="alert error">{{ error }}</div>
    <div v-else-if="!rows.length" class="empty"><h2>No customers found</h2><p>Try changing your search or status filter.</p></div>
    <div v-else class="table-wrap">
      <table><thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Status</th><th v-if="canManage">Actions</th></tr></thead><tbody>
        <tr v-for="customer in rows" :key="customer.id">
          <td><button class="link-button" @click="openDetail(customer)">{{ customer.name }}</button></td>
          <td>{{ customer.email }}</td><td>{{ customer.phone || '—' }}</td>
          <td><span :class="['badge', customer.is_active ? 'completed' : 'cancelled']">{{ customer.is_active ? 'Active' : 'Inactive' }}</span></td>
          <td v-if="canManage"><div class="table-actions"><button class="text-button" @click="openEdit(customer)">Edit</button><button class="text-button danger-text" @click="remove(customer)">Remove</button></div></td>
        </tr>
      </tbody></table>
    </div>
    <div v-if="meta.last_page > 1" class="pagination">
      <span>Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}</span>
      <div><button class="secondary-button" :disabled="page <= 1" @click="changePage(page - 1)">Previous</button><span>Page {{ page }} of {{ meta.last_page }}</span><button class="secondary-button" :disabled="page >= meta.last_page" @click="changePage(page + 1)">Next</button></div>
    </div>

    <div v-if="modal" class="modal-backdrop" @click.self="modal = null">
      <section v-if="modal === 'form'" class="modal-card" role="dialog" aria-modal="true" :aria-label="editId ? 'Edit customer' : 'Add customer'">
        <div class="modal-heading"><div><p class="eyebrow">Customer</p><h2>{{ editId ? 'Edit customer' : 'Add customer' }}</h2></div><button class="close-button" aria-label="Close dialog" @click="modal = null">×</button></div>
        <div v-if="error" class="alert error">{{ error }}</div>
        <form class="modal-form" @submit.prevent="save">
          <label>Name<input v-model.trim="form.name" maxlength="150" required /></label>
          <label>Email<input v-model.trim="form.email" type="email" maxlength="255" required /></label>
          <label>Phone<input v-model.trim="form.phone" maxlength="30" /></label>
          <label>Address<textarea v-model.trim="form.address" rows="3" maxlength="1000"></textarea></label>
          <label v-if="editId" class="checkbox-label"><input v-model="form.is_active" type="checkbox" /> Active customer</label>
          <div class="form-actions"><button type="button" class="secondary-button" @click="modal = null">Cancel</button><button class="primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save customer' }}</button></div>
        </form>
      </section>

      <section v-else class="modal-card customer-detail" role="dialog" aria-modal="true" aria-label="Customer details">
        <div class="modal-heading"><div><p class="eyebrow">Customer profile</p><h2>{{ selectedCustomer?.name || 'Customer details' }}</h2></div><button class="close-button" aria-label="Close dialog" @click="modal = null">×</button></div>
        <div v-if="detailLoading" class="loading">Loading customer details...</div>
        <div v-else-if="error" class="alert error">{{ error }}</div>
        <template v-else-if="selectedCustomer">
          <dl class="customer-contact"><dt>Email</dt><dd>{{ selectedCustomer.email }}</dd><dt>Phone</dt><dd>{{ selectedCustomer.phone || '—' }}</dd><dt>Address</dt><dd>{{ selectedCustomer.address || '—' }}</dd></dl>
          <div class="customer-stats">
            <article><span>Total orders</span><strong>{{ selectedCustomer.orders_count }}</strong></article>
            <article><span>Pending</span><strong>{{ selectedCustomer.pending_orders_count }}</strong></article>
            <article><span>Completed</span><strong>{{ selectedCustomer.completed_orders_count }}</strong></article>
            <article><span>Cancelled</span><strong>{{ selectedCustomer.cancelled_orders_count }}</strong></article>
            <article><span>Total order value</span><strong>{{ cents(selectedCustomer.total_order_value_cents) }}</strong></article>
          </div>
          <h3>Recent orders</h3>
          <div v-if="!selectedCustomer.orders?.length" class="empty compact">No orders for this customer yet.</div>
          <div v-else class="table-wrap"><table><thead><tr><th>Order</th><th>Status</th><th>Date</th><th>Total</th></tr></thead><tbody>
            <tr v-for="order in selectedCustomer.orders" :key="order.id"><td><RouterLink :to="`/orders/${order.id}`" @click="modal = null">{{ order.order_number }}</RouterLink></td><td><span :class="['badge', order.status]">{{ order.status }}</span></td><td>{{ String(order.order_date).slice(0, 10) }}</td><td>{{ cents(order.total_cents) }}</td></tr>
          </tbody></table></div>
          <div class="form-actions"><button v-if="canManage" class="secondary-button" @click="openEdit(selectedCustomer)">Edit customer</button><button class="primary" @click="modal = null">Close</button></div>
        </template>
      </section>
    </div>
  </section>
</template>
