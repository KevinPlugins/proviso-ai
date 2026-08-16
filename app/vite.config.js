import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

// Everything is bundled into two files under ../assets so the plugin ships
// without a CDN dependency and passes WordPress.org review.
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: '../assets',
    emptyOutDir: false,
    cssCodeSplit: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./src/main.js', import.meta.url)),
      output: {
        entryFileNames: 'app.js',
        assetFileNames: 'app.[ext]',
        format: 'iife',
      },
    },
  },
});
