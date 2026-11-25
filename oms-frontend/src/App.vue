<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  await router.push('/login')
}
</script>

<template>
  <div v-if="auth.isAuthenticated" class="app-shell">
    <aside class="sidebar">
      <RouterLink class="brand" to="/">OMS</RouterLink>
      <nav>
        <RouterLink to="/">Dashboard</RouterLink>
        <RouterLink to="/orders">Orders</RouterLink>
        <RouterLink to="/customers">Customers</RouterLink>
        <RouterLink to="/products">Products</RouterLink>
      </nav>
      <button class="logout" @click="logout">Logout</button>
    </aside>
    <main class="content"><RouterView /></main>
  </div>
  <RouterView v-else />
</template>
