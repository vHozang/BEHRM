<template>
  <!-- Bảng: mô phỏng dòng × cột (dùng khi đang tải BaseTable) -->
  <div v-if="type === 'table'" class="w-full" role="status" aria-label="Đang tải">
    <div v-for="r in rows" :key="r" class="flex items-center gap-4 py-3.5 border-b border-border/40">
      <div
        v-for="c in cols"
        :key="c"
        class="h-3.5 rounded bg-muted animate-pulse"
        :class="c === 1 ? 'w-1/5' : 'flex-1'"
      ></div>
    </div>
  </div>

  <!-- Lưới thẻ (KPI/card) -->
  <div v-else-if="type === 'cards'" class="grid gap-4" :class="gridClass" role="status" aria-label="Đang tải">
    <div v-for="r in rows" :key="r" class="rounded-2xl border border-border p-4 space-y-3">
      <div class="h-3.5 w-1/3 rounded bg-muted animate-pulse"></div>
      <div class="h-7 w-1/2 rounded bg-muted animate-pulse"></div>
      <div class="h-3 w-2/3 rounded bg-muted animate-pulse"></div>
    </div>
  </div>

  <!-- Nhiều dòng chữ -->
  <div v-else-if="type === 'text'" class="space-y-2.5" role="status" aria-label="Đang tải">
    <div
      v-for="r in rows"
      :key="r"
      class="h-4 rounded bg-muted animate-pulse"
      :style="{ width: r === rows ? '60%' : '100%' }"
    ></div>
  </div>

  <!-- Một khối đơn (tùy chỉnh width/height) -->
  <div v-else class="rounded-lg bg-muted animate-pulse" :style="{ width, height }" role="status" aria-label="Đang tải"></div>
</template>

<script setup>
// Khung xám nhấp nháy hiển thị trong lúc chờ dữ liệu — làm UX mượt hơn khi deploy
// (API qua mạng chậm hơn local). Thuần CSS, ~1KB, không thư viện, không gọi API.
defineProps({
  type: { type: String, default: 'block' }, // block | text | table | cards
  rows: { type: Number, default: 5 },
  cols: { type: Number, default: 4 },
  width: { type: String, default: '100%' },
  height: { type: String, default: '1rem' },
  gridClass: { type: String, default: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4' },
});
</script>
