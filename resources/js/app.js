import './bootstrap';

// Bootstrap 5's JS (dropdowns, modals, tooltips). Exposed on window so Blade
// can call `new bootstrap.Modal(...)` inline where that is simpler than a
// dedicated module.
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// SortableJS powers drag-and-drop reordering on the builder canvas. Exposed on
// window because the canvas is re-rendered by Livewire, so the Blade view has
// to re-initialise it after each DOM patch.
import Sortable from 'sortablejs';
window.Sortable = Sortable;
