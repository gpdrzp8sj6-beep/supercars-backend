import axios from 'axios';
import { router } from '@inertiajs/vue3';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.withCredentials = true;

router.on('before', (event) => {
    // Add any global before navigation logic here
});

router.on('finish', (event) => {
    // Add any global after navigation logic here
});