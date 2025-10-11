<template>
  <Card class="flex flex-col bg-white">
    <div class="px-3 py-3">
      <h3 class="text-lg font-semibold text-gray-900 mb-3">Recent Orders</h3>
      <div v-if="recentOrders.length === 0" class="text-gray-500">
        No recent orders.
      </div>
      <div v-else class="space-y-2">
        <div v-for="order in recentOrders" :key="order.id" class="flex justify-between items-center p-2 bg-white rounded border">
          <div>
            <div class="font-medium">Order #{{ order.id }}</div>
            <div class="text-sm text-gray-500">{{ formatDate(order.created_at) }}</div>
          </div>
          <div class="text-right">
            <div class="font-medium">${{ order.total }}</div>
            <div class="text-sm" :class="statusColor(order.status)">{{ order.status }}</div>
          </div>
        </div>
      </div>
      <div class="mt-4 pt-3 border-t">
        <button @click="viewAllOrders" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded transition-colors">
          View All Orders
        </button>
      </div>
    </div>
  </Card>
</template>

<script>
export default {
  props: [
    'card',
  ],

  computed: {
    recentOrders() {
      return this.card && this.card.recentOrders ? this.card.recentOrders : []
    },
  },

  methods: {
    formatDate(date) {
      return new Date(date).toLocaleDateString()
    },
    statusColor(status) {
      const colors = {
        pending: 'text-yellow-600',
        completed: 'text-green-600',
        cancelled: 'text-red-600',
      }
      return colors[status] || 'text-gray-600'
    },
    viewAllOrders() {
      Nova.visit('/resources/orders')
    },
  },
}
</script>
