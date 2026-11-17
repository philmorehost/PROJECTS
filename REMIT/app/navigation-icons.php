<?php
declare(strict_types=1);
function navIcon(string $name): string {
 $paths=[
  'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
  'payments'=>'<path d="M3 7h18v14H3zM3 11h18M7 16h4M7 3h10"/>',
  'church'=>'<path d="M4 21V11l8-6 8 6v10M2 21h20M10 21v-6h4v6M12 2v6M9 4h6"/>',
  'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
  'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
  'profile'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
  'settings'=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/>',
  'security'=>'<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6zM8 12l3 3 5-6"/>',
 ];
 return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name] ?? $paths['menu']).'</svg>';
}
