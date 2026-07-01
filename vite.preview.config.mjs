import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "tailwindcss";
import path from "path";
import { fileURLToPath } from "url";

// Config tối giản dùng RIÊNG cho Claude Preview (cổng 5050). Bỏ plugin Replit +
// autoprefixer (không có trong node_modules); cấu hình postcss inline chỉ dùng
// tailwind nên UI vẫn render đầy đủ style cho việc test chức năng.
const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [vue()],
  css: {
    postcss: {
      plugins: [tailwindcss(path.resolve(__dirname, "tailwind.preview.config.mjs"))],
    },
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "client", "src"),
      "@shared": path.resolve(__dirname, "shared"),
      "@assets": path.resolve(__dirname, "attached_assets"),
    },
  },
  root: path.resolve(__dirname, "client"),
  server: {
    host: "127.0.0.1",
    port: 5050,
    strictPort: true,
    allowedHosts: true,
  },
});
