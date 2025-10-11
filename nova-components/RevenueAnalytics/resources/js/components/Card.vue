<template>
  <Card class="flex flex-col bg-white">
    <div class="px-4 py-4">
      <h3 class="text-lg font-semibold text-gray-900 mb-4">Revenue Analytics</h3>

      <div class="space-y-4">
        <div v-for="item in revenueData" :key="item.period" class="flex items-center space-x-3">
          <div class="w-20 text-sm text-gray-600 font-medium">{{ item.label }}</div>
          <div class="flex-1">
            <div class="flex items-center space-x-2">
              <div class="flex-1 bg-gray-200 rounded-full h-8 relative">
                <div
                  class="bg-green-600 h-8 rounded-full transition-all duration-500 ease-out"
                  :style="{ width: barWidth(item.revenue) }"
                ></div>
                <div class="absolute inset-0 flex items-center justify-center text-xs font-medium text-gray-700">
                  ${{ formatCurrency(item.revenue) }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 pt-3 border-t border-gray-200">
        <div class="text-sm text-gray-600">
          Total revenue: <span class="font-semibold text-green-600">${{ formatCurrency(totalRevenue) }}</span>
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
    revenueData() {
      return this.card && this.card.revenueData ? this.card.revenueData : []
    },
    totalRevenue() {
      return this.revenueData.reduce((sum, item) => sum + item.revenue, 0)
    },
    maxRevenue() {
      const revenues = this.revenueData.map(item => item.revenue)
      return Math.max(...revenues, 1) // Minimum of 1 to avoid division by zero
    },
  },

  methods: {
    barWidth(revenue) {
      return `${(revenue / this.maxRevenue) * 100}%`
    },
    formatCurrency(amount) {
      return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(amount)
    },
  },
}
</script>
