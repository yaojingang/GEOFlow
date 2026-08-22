import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const realtimePage = document.querySelector('[data-ai-workspace], [data-task-realtime]');

if (realtimePage) {
    await import('./echo');
} else {
    window.Echo = null;
}
