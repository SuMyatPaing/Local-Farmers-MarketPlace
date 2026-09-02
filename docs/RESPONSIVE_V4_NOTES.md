# Responsive System v4

## Navigation behavior
- Browser zoom 100%, 110%, 125%: normal desktop header/sidebar remains visible.
- Around 175% browser zoom: Admin, Vendor, and Public/User navigation switches to hamburger/off-canvas mode.
- Real screens below 768px also use hamburger/off-canvas mode.
- High-zoom detection uses `screen.availWidth / window.innerWidth` on maximized Chrome/Edge so Windows display scaling does not incorrectly trigger compact mode.

## Layout fixes
- Admin desktop sidebar/content offset is normalized to 176px.
- Vendor desktop sidebar/content offset is normalized to 256px.
- Medium desktop widths reflow KPI grids, filters, admin dashboard panels, vendor dashboard panels, and product cards instead of forcing fixed wide layouts.
- Wide tables are contained inside their cards and may scroll internally when needed.
- Page-level horizontal overflow is prevented.
- Scrollbars are visually hidden while scrolling remains functional.

## Files
- `assets/css/responsive.css`
- `assets/js/responsive.js`
- shared Admin/Vendor/Public headers and sidebars updated to use the common responsive state.
