<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const email = ref('admin@oms.test')
const password = ref('password')
const error = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    await router.push('/')
  } catch (e: any) {
    error.value = e.response?.data?.errors?.email?.[0] || 'Unable to sign in'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="auth-page">
    <form class="auth-card" @submit.prevent="submit">
      <div class="brand-mark">OMS</div>
      <h1>Welcome back</h1>
      <p>Sign in to manage orders</p>
      <label>Email<input v-model="email" type="email" required /></label>
      <label>Password<input v-model="password" type="password" required /></label>
      <div v-if="error" class="alert error">{{ error }}</div>
      <button class="primary" :disabled="loading">{{ loading ? 'Signing in...' : 'Sign in' }}</button>
      <small>Demo: admin@oms.test / password</small>
    </form>
  </main>
</template>
