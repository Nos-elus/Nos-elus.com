import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  build: { outDir: 'dist' },
  server: {
    proxy: {
      // Cible API : prod par défaut. Pour API locale : VITE_API_TARGET=http://localhost:8080 npm run dev
      '/api': {
        target: process.env.VITE_API_TARGET || 'https://nos-elus.com',
        changeOrigin: true,
        secure: true,
      },
      '/photos': {
        target: process.env.VITE_API_TARGET || 'https://nos-elus.com',
        changeOrigin: true,
        secure: true,
      }
    }
  }
})
