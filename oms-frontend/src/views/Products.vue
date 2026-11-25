<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
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
const modal = ref(false)
const editId = ref<number | null>(null)
const form = reactive({ name: '', sku: '', price: '', stock_quantity: 0, is_active: true })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/products', {
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
    error.value = e.response?.data?.message || 'Unable to load products.'
  } finally {
    loading.value = false
  }
}

watch([search, activeFilter], () => { page.value = 1 })
watch([search, activeFilter, page], load)
onMounted(load)

function openCreate() {
  editId.value = null
  Object.assign(form, { name: '', sku: '', price: '', stock_quantity: 0, is_active: true })
  error.value = ''
  modal.value = true
}

function openEdit(product: any) {
  editId.value = product.id
  Object.assign(form, {
    name: product.name,
    sku: product.sku,
    price: (product.price_cents / 100).toFixed(2),
    stock_quantity: product.stock_quantity,
    is_active: product.is_active,
  })
  error.value = ''
  modal.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  const payload = {
    name: form.name,
    sku: form.sku,
    price_cents: Math.round(Number(form.price) * 100),
    stock_quantity: Number(form.stock_quantity),
    ...(editId.value ? { is_active: form.is_active } : {}),
  }
  try {
    if (editId.value) await api.put(`/products/${editId.value}`, payload)
    else await api.post('/products', payload)
    notice.value = editId.value ? 'Product updated.' : 'Product created.'
    modal.value = false
    await load()
  } catch (e: any) {
    const errors = e.response?.data?.errors
    error.value = errors ? Object.values(errors).flat()[0] as string : e.response?.data?.message || 'Unable to save product.'
  } finally {
    saving.value = false
  }
}

async function remove(product: any) {
  if (!window.confirm(`Remove ${product.name}? Products used by orders will be deactivated.`)) return
  error.value = ''
  notice.value = ''
  try {
    const { data } = await api.delete(`/products/${product.id}`)
    notice.value = data.message
    await load()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Unable to remove product.'
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
      <div><p class="eyebrow">Catalog</p><h1>Products</h1></div>
      <button v-if="canManage" class="primary" @click="openCreate">Add product</button>
    </header>
    <div v-if="notice" class="alert success">{{ notice }}</div>
    <div v-if="error && !modal" class="alert error">{{ error }}</div>
    <div class="toolbar">
      <input v-model="search" placeholder="Search product name or SKU..." />
      <select v-model="activeFilter"><option value="">All products</option><option value="1">Active</option><option value="0">Inactive</option></select>
    </div>
    <div v-if="loading" class="loading">Loading products...</div>
    <div v-else-if="error && !rows.length" class="alert error">{{ error }}</div>
    <div v-else-if="!rows.length" class="empty"><h2>No products found</h2><p>Try changing your search or status filter.</p></div>
    <div v-else class="table-wrap">
      <table><thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Stock</th><th>Status</th><th v-if="canManage">Actions</th></tr></thead><tbody>
        <tr v-for="product in rows" :key="product.id">
          <td>{{ product.name }}</td><td>{{ product.sku }}</td><td>{{ cents(product.price_cents) }}</td><td>{{ product.stock_quantity }}</td>
          <td><span :class="['badge', product.is_active ? 'completed' : 'cancelled']">{{ product.is_active ? 'Active' : 'Inactive' }}</span></td>
          <td v-if="canManage"><div class="table-actions"><button class="text-button" @click="openEdit(product)">Edit</button><button class="text-button danger-text" @click="remove(product)">Remove</button></div></td>
        </tr>
      </tbody></table>
    </div>
    <div v-if="meta.last_page > 1" class="pagination">
      <span>Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}</span>
      <div><button class="secondary-button" :disabled="page <= 1" @click="changePage(page - 1)">Previous</button><span>Page {{ page }} of {{ meta.last_page }}</span><button class="secondary-button" :disabled="page >= meta.last_page" @click="changePage(page + 1)">Next</button></div>
    </div>

    <div v-if="modal" class="modal-backdrop" @click.self="modal = false">
      <section class="modal-card" role="dialog" aria-modal="true" :aria-label="editId ? 'Edit product' : 'Add product'">
        <div class="modal-heading"><div><p class="eyebrow">Catalog</p><h2>{{ editId ? 'Edit product' : 'Add product' }}</h2></div><button class="close-button" aria-label="Close dialog" @click="modal = false">×</button></div>
        <div v-if="error" class="alert error">{{ error }}</div>
        <form class="modal-form" @submit.prevent="save">
          <label>Product name<input v-model.trim="form.name" maxlength="150" required /></label>
          <label>SKU<input v-model.trim="form.sku" maxlength="50" required /></label>
          <label>Price (USD)<input v-model="form.price" type="number" min="0" step="0.01" required /></label>
          <label>Stock quantity<input v-model.number="form.stock_quantity" type="number" min="0" step="1" required /></label>
          <label v-if="editId" class="checkbox-label"><input v-model="form.is_active" type="checkbox" /> Active product</label>
          <div class="form-actions"><button type="button" class="secondary-button" @click="modal = false">Cancel</button><button class="primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save product' }}</button></div>
        </form>
      </section>
    </div>
  </section>
</template>
