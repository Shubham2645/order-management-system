<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const auth = useAuthStore()
const order = ref<any>(null)
const staff = ref<any[]>([])
const loading = ref(true)
const error = ref('')
const actionError = ref('')
const nextStatus = ref('')
const statusRemarks = ref('')
const assignedTo = ref<number | null>(null)
const noteBody = ref('')
const busy = ref(false)
const isManager = computed(() => ['admin', 'manager'].includes(auth.user?.role || ''))
const isTerminal = computed(() => ['completed', 'cancelled'].includes(order.value?.status))
const canChangeStatus = computed(() => isManager.value || (auth.user?.role === 'staff' && order.value?.assigned_to === auth.user?.id))
const transitions: Record<string, string[]> = {
  draft: ['pending', 'cancelled'],
  pending: ['confirmed', 'cancelled'],
  confirmed: ['processing', 'cancelled'],
  processing: ['ready', 'cancelled'],
  ready: ['completed', 'cancelled'],
}
const statusOptions = computed(() => transitions[order.value?.status] || [])

const cents = (value: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value / 100)

async function loadOrder() {
  const { data } = await api.get(`/orders/${route.params.id}`)
  order.value = data.data
  assignedTo.value = order.value.assigned_to
}

async function changeStatus() {
  if (!nextStatus.value) return
  busy.value = true
  actionError.value = ''
  try {
    await api.post(`/orders/${route.params.id}/status`, { status: nextStatus.value, remarks: statusRemarks.value || null })
    await loadOrder()
    nextStatus.value = ''
    statusRemarks.value = ''
  } catch (e: any) {
    actionError.value = e.response?.data?.errors?.status?.[0] || e.response?.data?.message || 'Unable to update order status.'
  } finally {
    busy.value = false
  }
}

async function assignOrder() {
  if (!assignedTo.value) return
  busy.value = true
  actionError.value = ''
  try {
    await api.post(`/orders/${route.params.id}/assign`, { assigned_to: assignedTo.value })
    await loadOrder()
  } catch (e: any) {
    actionError.value = e.response?.data?.message || 'Unable to assign order.'
  } finally {
    busy.value = false
  }
}

async function addNote() {
  const body = noteBody.value.trim()
  if (!body) return
  busy.value = true
  actionError.value = ''
  try {
    const { data } = await api.post(`/orders/${route.params.id}/notes`, { body })
    order.value.notes.unshift(data.data)
    noteBody.value = ''
  } catch (e: any) {
    actionError.value = e.response?.data?.errors?.body?.[0] || e.response?.data?.message || 'Unable to add note.'
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await loadOrder()
    if (isManager.value) staff.value = (await api.get('/users/assignable')).data.data
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to load order.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section v-if="loading" class="loading">Loading order...</section>
  <section v-else-if="error" class="alert error">{{ error }}</section>
  <section v-else>
    <header class="page-header">
      <div><p class="eyebrow">Order detail</p><h1>{{ order.order_number }}</h1></div>
      <div class="actions">
        <RouterLink v-if="isManager && !isTerminal" :to="`/orders/${order.id}/edit`" class="secondary-button">Edit order</RouterLink>
        <RouterLink to="/orders" class="secondary-button">Back</RouterLink>
      </div>
    </header>
    <div v-if="actionError" class="alert error">{{ actionError }}</div>
    <div class="detail-grid">
      <article class="panel">
        <h2>Order summary</h2>
        <dl><dt>Customer</dt><dd>{{ order.customer.name }}</dd><dt>Status</dt><dd><span :class="['badge', order.status]">{{ order.status }}</span></dd><dt>Priority</dt><dd>{{ order.priority }}</dd><dt>Order date</dt><dd>{{ order.order_date }}</dd><dt>Assigned</dt><dd>{{ order.assignee?.name || 'Unassigned' }}</dd><dt>Total</dt><dd>{{ cents(order.total_cents) }}</dd></dl>
        <div v-if="canChangeStatus && statusOptions.length" class="detail-action">
          <label>Status remarks<input v-model="statusRemarks" placeholder="Optional update note" /></label>
          <div class="actions"><select v-model="nextStatus"><option value="">Select next status</option><option v-for="status in statusOptions" :key="status" :value="status">{{ status }}</option></select><button class="primary" :disabled="busy || !nextStatus" @click="changeStatus">Update status</button></div>
        </div>
      </article>
      <article class="panel">
        <h2>Customer</h2>
        <dl><dt>Name</dt><dd>{{ order.customer.name }}</dd><dt>Email</dt><dd>{{ order.customer.email }}</dd><dt>Phone</dt><dd>{{ order.customer.phone || '—' }}</dd><dt>Address</dt><dd>{{ order.customer.address || '—' }}</dd></dl>
      </article>
    </div>
    <article v-if="isManager && !isTerminal" class="panel assignment-panel">
      <h2>Assignment</h2>
      <div class="actions"><select v-model.number="assignedTo"><option :value="null">Choose staff member</option><option v-for="user in staff" :key="user.id" :value="user.id">{{ user.name }} · {{ user.email }}</option></select><button class="primary" :disabled="busy || !assignedTo" @click="assignOrder">Assign order</button></div>
    </article>
    <article class="panel">
      <h2>Order items</h2>
      <div class="table-wrap"><table><thead><tr><th>Product</th><th>SKU</th><th>Quantity</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead><tbody>
        <tr v-for="item in order.items" :key="item.id"><td>{{ item.product.name }}</td><td>{{ item.product.sku }}</td><td>{{ item.quantity }}</td><td>{{ cents(item.unit_price_cents) }}</td><td>{{ cents(item.discount_cents) }}</td><td>{{ cents(item.tax_cents) }}</td><td>{{ cents(item.line_total_cents) }}</td></tr>
      </tbody></table></div>
    </article>
    <div class="detail-grid">
      <article class="panel">
        <h2>Status history</h2>
        <div v-for="history in order.status_history" :key="history.id" class="timeline"><strong>{{ history.from_status ? `${history.from_status} → ` : '' }}{{ history.to_status }}</strong><span>{{ history.user?.name }} · {{ history.created_at }}</span><p v-if="history.remarks">{{ history.remarks }}</p></div>
      </article>
      <article class="panel">
        <h2>Notes</h2>
        <div v-for="note in order.notes" :key="note.id" class="note"><strong>{{ note.user?.name }}</strong><span class="muted">{{ note.created_at }}</span><p>{{ note.body }}</p></div>
        <p v-if="!order.notes?.length" class="muted">No notes yet.</p>
        <form class="note-form" @submit.prevent="addNote"><textarea v-model="noteBody" rows="3" maxlength="5000" placeholder="Add an internal note..." required></textarea><button class="primary" :disabled="busy || !noteBody.trim()">Add note</button></form>
      </article>
    </div>
    <article v-if="order.audits?.length" class="panel">
      <h2>Activity</h2>
      <div v-for="audit in order.audits" :key="audit.id" class="timeline"><strong>{{ audit.action.replaceAll('.', ' ') }}</strong><span>{{ audit.created_at }}</span></div>
    </article>
  </section>
</template>
