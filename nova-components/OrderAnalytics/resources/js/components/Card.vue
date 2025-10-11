<template>
  <Card class="flex flex-col bg-white">
    <div class="px-4 py-4">
      <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Analytics - Last 7 Days</h3>

      <div class="space-y-3">
        <div v-for="day in orderData" :key="day.date" class="flex items-center space-x-3">
          <div class="w-12 text-sm text-gray-600 font-medium">{{ day.day }}</div>
          <div class="flex-1">
            <div class="flex items-center space-x-2">
              <div class="flex-1 bg-gray-200 rounded-full h-6 relative">
                <div
                  class="bg-blue-600 h-6 rounded-full transition-all duration-500 ease-out"
                  :style="{ width: barWidth(day.count) }"
                ></div>
                <div class="absolute inset-0 flex items-center justify-center text-xs font-medium text-gray-700">
                  {{ day.count }}
                </div>
              </div>
              <div class="text-xs text-gray-500 w-10 text-right">{{ day.formatted_date }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 pt-3 border-t border-gray-200">
        <div class="text-sm text-gray-600">
          Total orders: <span class="font-semibold">{{ totalOrders }}</span>
        </div>
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
    orderData() {
      return this.card && this.card.orderData ? this.card.orderData : []
    },
    totalOrders() {
      return this.orderData.reduce((sum, day) => sum + day.count, 0)
    },
    maxOrders() {
      const counts = this.orderData.map(day => day.count)
      return Math.max(...counts, 1) // Minimum of 1 to avoid division by zero
    },
  },

  methods: {
    barWidth(count) {
      return `${(count / this.maxOrders) * 100}%`
    },
  },
}
</script>
