<template>
  <div class="relative w-full border-0 bg-transparent md:rounded-xl md:border md:border-card-border md:bg-card/95 md:shadow-sm">
    <div class="w-full md:overflow-auto max-h-none md:max-h-[calc(100vh-250px)]">
      <table class="w-full border-collapse block md:table whitespace-normal md:whitespace-nowrap min-w-full" :data-testid="testId">
        <thead class="hidden md:table-header-group">
          <tr class="bg-muted/45">
            <th
              v-for="column in columns"
              :key="column.key"
              class="sticky top-0 z-10 bg-muted/95 px-5 py-3.5 text-left text-xs font-semibold uppercase text-muted-foreground backdrop-blur"
            >
              {{ column.label }}
            </th>
            <th v-if="$slots.actions" class="sticky right-0 top-0 z-20 border-l border-border/50 bg-muted/95 px-5 py-3.5 align-middle text-xs font-semibold uppercase text-muted-foreground backdrop-blur">
              <div class="flex justify-center">Thao tác</div>
            </th>
          </tr>
        </thead>
        <tbody class="block md:table-row-group space-y-4 md:space-y-0">
          <tr
            v-for="(item, index) in data"
            :key="index"
            class="group block rounded-xl border border-border bg-card p-4 shadow-sm transition-colors hover:bg-muted/35 md:table-row md:rounded-none md:border-0 md:border-b md:border-border/70 md:p-0 md:shadow-none"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              class="block border-b border-border/30 px-2 py-3 last-of-type:border-0 md:table-cell md:border-0 md:px-5 md:py-4"
            >
              <div class="flex min-h-[2rem] items-center justify-between md:block w-full gap-4">
                <span class="text-xs font-semibold text-muted-foreground uppercase shrink-0 md:hidden max-w-[40%] text-left">
                  {{ column.label }}
                </span>
                <div class="w-auto flex flex-col justify-center items-end md:items-start md:justify-start text-right md:text-left text-sm text-foreground max-w-[60%] md:max-w-none">
                  <slot :name="`cell-${column.key}`" :item="item" :value="getNestedValue(item, column.key)">
                    {{ getNestedValue(item, column.key) }}
                  </slot>
                </div>
              </div>
            </td>
            <td 
              v-if="$slots.actions" 
              class="mt-1 block border-t border-border/60 bg-inherit px-2 py-4 pt-4 align-middle md:mt-0 md:table-cell md:border-l md:border-t-0 md:border-border/50 md:px-5 md:py-4"
            >
              <div class="flex items-center justify-between md:justify-center w-full">
                <span class="text-xs font-semibold text-muted-foreground uppercase shrink-0 md:hidden">Thao tác</span>
                <div class="flex justify-end md:justify-center gap-2 md:w-full">
                  <slot name="actions" :item="item" />
                </div>
              </div>
            </td>
          </tr>
          <tr v-if="data.length === 0" class="block md:table-row">
            <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="block md:table-cell px-4 py-12 text-center text-muted-foreground bg-card rounded-xl border border-border md:border-0 md:bg-transparent">
              <slot name="empty">
                Không có dữ liệu
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
interface Column {
  key: string;
  label: string;
}

interface Props {
  columns: Column[];
  data: any[];
  testId?: string;
}

defineProps<Props>();

const getNestedValue = (obj: any, path: string) => {
  return path.split('.').reduce((acc, part) => acc?.[part], obj);
};
</script>
