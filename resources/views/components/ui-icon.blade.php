@props(['name' => 'globe'])
<svg {{ $attributes->class(['ui-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('database')<ellipse cx="12" cy="5" rx="8" ry="3" /><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0" />@break
@case('globe')<path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM3 12h18M12 3c4 5 4 13 0 18-4-5-4-13 0-18Z" />@break
@case('folder')<path d="M3 7V5h6l2 2h10v13H3ZM3 10h18" />@break
@case('backup')<path d="M4 10a8 8 0 1 1 1 8M4 4v6h6M12 8v5l3 2" />@break
@case('code')<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-3-14-2 16" />@break
@case('file')<path d="M14 3H5v18h14V8ZM14 3v5h5M8 12h8M8 16h6" />@break
@case('git')<path d="M6 6v12m0-9c9 0 12 0 12 9M8 4a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM8 20a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm12 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z" />@break
@case('package')<path d="m12 3 9 5-9 5-9-5ZM3 8v9l9 5 9-5V8M12 13v9M7 5.8l9 5" />@break
@case('terminal')<path d="M3 4h18v16H3Zm4 5 3 3-3 3m6 0h4" />@break
@case('clock')<path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 7v5l3 2" />@break
@case('sun')<circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41" />@break
@case('queue')<path d="M4 4h16v4H4Zm0 6h16v4H4Zm0 6h16v4H4M7 6h.01M7 12h.01M7 18h.01" />@break
@case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Zm-4 9 3 3 5-6" />@break
@case('settings')<path d="M4 7h16M4 17h16M8 4v6M16 14v6" />@break
@case('network')<path d="M9 3h6v6H9ZM2 16h6v5H2Zm14 0h6v5h-6ZM12 9v4M5 16v-3h14v3" />@break
@case('activity')<path d="M3 12h4l3-8 4 16 3-8h4" />@break
@case('external')<path d="M14 4h6v6M20 4l-9 9M19 14v5H5V5h5" />@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16" />@break
@default<path d="M4 4h16v16H4Z" />
@endswitch
</svg>
