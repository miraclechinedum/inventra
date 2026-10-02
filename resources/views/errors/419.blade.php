{{-- The Figma reads "Unsaved changes are kept on this device." Inventra stores no local form state — there is no localStorage, sessionStorage or IndexedDB use in app.js — so that sentence is omitted rather than reproduced. --}}
@include('errors._state', ['title' => 'Your session has expired', 'state' => 'errors._419-state'])
