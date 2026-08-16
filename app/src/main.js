import { createApp } from 'vue';
import App from './App.vue';
import './style.css';

const mount = document.getElementById('mag-app');
if (mount) {
  createApp(App).mount(mount);
}
