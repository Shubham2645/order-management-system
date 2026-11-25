<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

interface ProductOption {
  id: number
  name: string
  sku: string
  price_cents: number
  stock_quantity: number
}

interface OrderLine {
  product_id: number | null
  quantity: number
  discount_cents: number
}

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isEditing = computed(() => Boolean(route.params.id))
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const customers = ref<any[]>([])
const products = ref<ProductOption[]>([])
const staff = ref<any[]>([])
const form = reactive({
  customer_id: null as number | null,
  assigned_to: null as number | null,
  priority: 'normal',
  order_date: new Date().toISOString().slice(0, 10),
  status: 'draft',
  tax_rate: 10,
  discount_cents: 0,
  items: [{ product_id: null, quantity: 1, discount_cents: 0 }] as OrderLine[],
})

const itemTotals = computed(() => form.items.map((item) => {
  const product = products.value.find((candidate) => candidate.id === item.product_id)
  const subtotal = (product?.price_cents || 0) * item.quantity
  const discount = Math.max(0, Number(item.discount_cents) || 0)
  const tax = Math.floor(Math.max(0, subtotal - discount) * form.tax_rate / 100)
  return { subtotal, discount, tax, total: Math.max(0, subtotal - discount) + tax }
}))
const subtotal = computed(() => itemTotals.value.reduce((sum, item) => sum + item.subtotal, 0))
const itemDiscounts = computed(() => itemTotals.value.reduce((sum, item) => sum + item.discount, 0))
const taxTotal = computed(() => itemTotals.value.reduce((sum, item) => sum + item.tax, 0))
const grandTotal = computed(() => subtotal.value - itemDiscounts.value - form.discount_cents + taxTotal.value)
const discountInvalid = computed(() => form.discount_cents + itemDiscounts.value > subtotal.value)
const cents = (value: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value / 100)

function addItem() {
  form.items.push({ product_id: null, quantity: 1, discount_cents: 0 })
}

function removeItem(index: number) {
  if (form.items.length > 1) form.items.splice(index, 1)
}

async function submit() {
  error.value = ''
  if (discountInvalid.value) {
    error.value = 'Combined discounts cannot exceed the order subtotal.'
    return
  }
  saving.value = true
  try {
    const payload = {
      customer_id: form.customer_id,
      assigned_to: form.assigned_to,
      priority: form.priority,
      order_date: form.order_date,
      tax_rate: Number(form.tax_rate),
      discount_cents: Number(form.discount_cents),
      items: form.items.map((item) => ({
        product_id: item.product_id,
        quantity: Number(item.quantity),
        discount_cents: Number(item.discount_cents) || 0,
      })),
      ...(!isEditing.value ? { status: form.status } : {}),
    }

    const response = isEditing.value
      ? await api.put(`/orders/${route.params.id}`, payload)
      : await api.post('/orders', payload)
    const orderId = response.data.data.id
    await router.push(`/orders/${orderId}`)
  } catch (e: any) {
    const validationErrors = e.response?.data?.errors
    error.value = validationErrors
      ? Object.values(validationErrors).flat()[0] as string
      : e.response?.data?.message || 'Unable to save order.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  if (!['admin', 'manager'].includes(auth.user?.role || '')) {
    await router.replace('/orders')
    return
  }

  try {
    const [customerResponse, productResponse] = await Promise.all([
      api.get('/customers', { params: { is_active: 1, per_page: 100 } }),
      api.get('/products', { params: { is_active: 1, per_page: 100 } }),
    ])
    customers.value = customerResponse.data.data.data
    products.value = productResponse.data.data.data

    if (isEditing.value) {
      const { data } = await api.get(`/orders/${route.params.id}`)
      const order = data.data
      if (['completed', 'cancelled'].includes(order.status)) {
        await router.replace(`/orders/${route.params.id}`)
        return
      }
      form.customer_id = order.customer_id
      form.assigned_to = order.assigned_to
      form.priority = order.priority
      form.order_date = String(order.order_date).slice(0, 10)
      form.tax_rate = order.tax_rate
      form.discount_cents = order.discount_cents
      form.items = order.items.map((item: any) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        discount_cents: item.discount_cents,
      }))
    }

    if (['admin', 'manager'].includes(auth.user?.role || '')) {
      staff.value = (await api.get('/users/assignable')).data.data
    }
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to load order form data.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <header class="page-header">
      <div><p class="eyebrow">Operations</p><h1>{{ isEditing ? 'Edit order' : 'Create order' }}</h1></div>
      <RouterLink to="/orders" class="secondary-button">Back to orders</RouterLink>
    </header>
    <div v-if="loading" class="loading">Loading order form...</div>
    <div v-else>
      <div v-if="error" class="alert error">{{ error }}</div>
      <form class="order-form" @submit.prevent="submit">
        <div class="form-grid">
          <label>Customer
            <select v-model.number="form.customer_id" required>
              <option :value="null" disabled>Select customer</option>
              <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }} · {{ customer.email }}</option>
            </select>
          </label>
          <label>Assigned staff
            <select v-model.number="form.assigned_to">
              <option :value="null">Unassigned</option>
              <option v-for="user in staff" :key="user.id" :value="user.id">{{ user.name }}</option>
            </select>
          </label>
          <label>Priority
            <select v-model="form.priority"><option>low</option><option>normal</option><option>high</option><option>urgent</option></select>
          </label>
          <label>Order date<input v-model="form.order_date" type="date" required /></label>
          <label>Tax rate (%)<input v-model.number="form.tax_rate" type="number" min="0" max="100" required /></label>
          <label>Order discount (cents)<input v-model.number="form.discount_cents" type="number" min="0" /></label>
          <label v-if="!isEditing">Initial status
            <select v-model="form.status"><option value="draft">Draft</option><option value="pending">Pending</option><option value="confirmed">Confirmed (reserves stock)</option></select>
          </label>
        </div>

        <article class="panel line-items-panel">
          <div class="section-title"><h2>Items</h2><button type="button" class="secondary-button" @click="addItem">Add item</button></div>
          <div v-for="(item, index) in form.items" :key="index" class="line-item-row">
            <label>Product
              <select v-model.number="item.product_id" required>
                <option :value="null" disabled>Select product</option>
                <option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }} · {{ product.sku }} · {{ cents(product.price_cents) }}</option>
              </select>
            </label>
            <label>Quantity<input v-model.number="item.quantity" type="number" min="1" required /></label>
            <label>Line discount (cents)<input v-model.number="item.discount_cents" type="number" min="0" /></label>
            <div class="line-total"><span>Line total</span><strong>{{ cents(itemTotals[index]?.total || 0) }}</strong></div>
            <button type="button" class="icon-button" :disabled="form.items.length === 1" aria-label="Remove item" @click="removeItem(index)">×</button>
          </div>
          <p v-if="!products.length" class="muted">No active products are available.</p>
        </article>

        <article class="panel totals-panel">
          <div><span>Subtotal</span><strong>{{ cents(subtotal) }}</strong></div>
          <div><span>Line discounts</span><strong>−{{ cents(itemDiscounts) }}</strong></div>
          <div><span>Order discount</span><strong>−{{ cents(form.discount_cents || 0) }}</strong></div>
          <div><span>Tax</span><strong>{{ cents(taxTotal) }}</strong></div>
          <div class="grand-total"><span>Total</span><strong>{{ cents(grandTotal) }}</strong></div>
          <p class="muted">Totals are recalculated by the server before saving.</p>
        </article>
        <div v-if="discountInvalid" class="alert error">Combined discounts cannot exceed the subtotal.</div>
        <div class="form-actions"><RouterLink to="/orders" class="secondary-button">Cancel</RouterLink><button class="primary" :disabled="saving || discountInvalid || !products.length">{{ saving ? 'Saving...' : isEditing ? 'Save changes' : 'Create order' }}</button></div>
      </form>
    </div>
  </section>
</template>
