<template>
  <div class="space-y-4">
    <!-- Drawing Area -->
    <div class="relative bg-muted/40 dark:bg-muted/10 border-2 border-dashed border-border rounded-2xl overflow-hidden shadow-inner">
      <canvas 
        ref="canvasRef"
        class="w-full h-64 block touch-none cursor-crosshair"
        @mousedown="startDrawing"
        @mousemove="draw"
        @mouseup="stopDrawing"
        @mouseleave="stopDrawing"
        @touchstart="startDrawingTouch"
        @touchmove="drawTouch"
        @touchend="stopDrawing"
      ></canvas>

      <!-- Draw Prompt Overlay -->
      <div 
        v-if="isEmpty" 
        class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-muted-foreground select-none"
      >
        <svg class="w-10 h-10 stroke-current opacity-60 animate-bounce" fill="none" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
        </svg>
        <span class="text-xs font-semibold mt-2">Dùng chuột hoặc ngón tay để vẽ chữ ký của bạn tại đây</span>
      </div>
    </div>

    <!-- Controls -->
    <div class="flex items-center justify-between gap-3">
      <BaseButton 
        variant="outline" 
        size="sm"
        class="px-4"
        @click="clearCanvas"
        :disabled="isEmpty"
      >
        Xóa chữ ký
      </BaseButton>
      <BaseButton 
        size="sm"
        class="px-6"
        @click="confirmSignature"
        :disabled="isEmpty"
      >
        Xác nhận ký tên
      </BaseButton>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import BaseButton from './BaseButton.vue';

const emit = defineEmits(['save', 'cancel']);

const canvasRef = ref(null);
const isEmpty = ref(true);
const isDrawing = ref(false);
let ctx = null;

// Drawing context initialization
const initCtx = () => {
  const canvas = canvasRef.value;
  if (!canvas) return;

  // Set logical coordinate resolution matching css layout
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width * window.devicePixelRatio;
  canvas.height = rect.height * window.devicePixelRatio;
  
  ctx = canvas.getContext('2d');
  ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
  
  // Custom paint brush config
  ctx.strokeStyle = '#000000'; // Dark stroke for contrast
  ctx.lineWidth = 3;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
};

// Handle window resizing
const handleResize = () => {
  if (canvasRef.value) {
    const savedData = canvasRef.value.toDataURL();
    const img = new Image();
    img.onload = () => {
      initCtx();
      ctx.drawImage(img, 0, 0, canvasRef.value.width / window.devicePixelRatio, canvasRef.value.height / window.devicePixelRatio);
    };
    img.src = savedData;
  }
};

onMounted(() => {
  initCtx();
  window.addEventListener('resize', handleResize);
});

onUnmounted(() => {
  window.removeEventListener('resize', handleResize);
});

// Coordinate conversion helpers
const getMousePos = (e) => {
  const canvas = canvasRef.value;
  const rect = canvas.getBoundingClientRect();
  return {
    x: e.clientX - rect.left,
    y: e.clientY - rect.top
  };
};

const getTouchPos = (e) => {
  const canvas = canvasRef.value;
  const rect = canvas.getBoundingClientRect();
  const touch = e.touches[0];
  return {
    x: touch.clientX - rect.left,
    y: touch.clientY - rect.top
  };
};

// Canvas drawing functions
const startDrawing = (e) => {
  isDrawing.value = true;
  isEmpty.value = false;
  const pos = getMousePos(e);
  ctx.beginPath();
  ctx.moveTo(pos.x, pos.y);
};

const draw = (e) => {
  if (!isDrawing.value) return;
  const pos = getMousePos(e);
  ctx.lineTo(pos.x, pos.y);
  ctx.stroke();
};

const startDrawingTouch = (e) => {
  isDrawing.value = true;
  isEmpty.value = false;
  const pos = getTouchPos(e);
  ctx.beginPath();
  ctx.moveTo(pos.x, pos.y);
};

const drawTouch = (e) => {
  if (!isDrawing.value) return;
  const pos = getTouchPos(e);
  ctx.lineTo(pos.x, pos.y);
  ctx.stroke();
};

const stopDrawing = () => {
  isDrawing.value = false;
};

const clearCanvas = () => {
  if (!ctx || !canvasRef.value) return;
  ctx.clearRect(0, 0, canvasRef.value.width, canvasRef.value.height);
  isEmpty.value = true;
};

const confirmSignature = () => {
  if (isEmpty.value || !canvasRef.value) return;
  const signatureBase64 = canvasRef.value.toDataURL('image/png');
  emit('save', signatureBase64);
};
</script>
