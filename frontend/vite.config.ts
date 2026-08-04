import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/',
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    hmr: {
      host: 'leadswhats.appsato7.com.br',
      protocol: 'wss',
      clientPort: 443,
    },    
    allowedHosts: [
      'leadswhats.appsato7.com.br',
      '.appsato7.com.br',
      'localhost',
      '127.0.0.1',
      '0.0.0.0'
    ]
  },
  preview: {
    host: true,
    port: 5173,
    allowedHosts: [
      'leadswhats.appsato7.com.br',
      '.appsato7.com.br'
    ]
  }
})