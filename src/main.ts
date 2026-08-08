import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.ts'

import './css/app.css'

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(App)
	app.use(createPinia())
	app.use(router)
	app.mount('.app-projektwerk')
})
