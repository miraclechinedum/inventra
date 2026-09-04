import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.csp.esm';

window.Alpine = Alpine;

Livewire.start();

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print-page]')) {
        window.print();
    }
});
