import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import Login from '../views/Login.vue'
import Dashboard from '../views/Dashboard.vue'
import Orders from '../views/Orders.vue'
import OrderDetail from '../views/OrderDetail.vue'
import Customers from '../views/Customers.vue'
import Products from '../views/Products.vue'
import OrderForm from '../views/OrderForm.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: Login, meta: { guest: true } },
    { path: '/', component: Dashboard, meta: { requiresAuth: true } },
    { path: '/orders', component: Orders, meta: { requiresAuth: true } },
    { path: '/orders/new', component: OrderForm, meta: { requiresAuth: true } },
    { path: '/orders/:id/edit', component: OrderForm, meta: { requiresAuth: true } },
    { path: '/orders/:id', component: OrderDetail, meta: { requiresAuth: true } },
    { path: '/customers', component: Customers, meta: { requiresAuth: true } },
    { path: '/products', component: Products, meta: { requiresAuth: true } },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.isAuthenticated) return '/login'
  if (to.meta.guest && auth.isAuthenticated) return '/'
})

export default router
