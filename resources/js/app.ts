import { createApp } from 'vue';
import App from './App.vue';
import '../css/keylashop-theme.css';
import '../css/sales.css';
import '../css/baskets.css';

const publicOrder = location.pathname.match(/^\/s\/([a-f0-9]{64})\/?$/);
if (publicOrder) {
    import('./components/PublicOrderRequest.vue').then(({ default: PublicOrderRequest }) => createApp(PublicOrderRequest, { token: publicOrder[1] }).mount('#app'));
} else {
    createApp(App).mount('#app');
}
