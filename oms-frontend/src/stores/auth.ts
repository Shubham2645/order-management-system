import { defineStore } from 'pinia'
import { api } from '../api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(localStorage.getItem('oms_user') || 'null') as null | { id: number; name: string; email: string; role: string },
  }),
  getters: {
    isAuthenticated: (state) => Boolean(state.user),
  },
  actions: {
    async login(email: string, password: string) {
      const { data } = await api.post('/login', { email, password })
      localStorage.setItem('oms_token', data.data.token)
      localStorage.setItem('oms_user', JSON.stringify(data.data.user))
      this.user = data.data.user
    },
    async logout() {
      try { await api.post('/logout') } finally {
        localStorage.removeItem('oms_token')
        localStorage.removeItem('oms_user')
        this.user = null
      }
    },
  },
})
