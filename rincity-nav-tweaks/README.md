# rincity-nav-tweaks

**Version:** 1.1.3  
**Deploy path:** `wp-content/plugins/rincity-nav-tweaks/`

Fixes submenu expand/collapse on the Ashe Pro theme for touch devices.

## Problems

**Mobile nav (`#mobile-menu`, active ≤979px):** The theme injects a `.sub-menu-btn`
overlay as the tap target but `#mobile-menu li a` has `position: relative; z-index: 5`,
rendering it dead. The `.sub-menu-btn-icon` chevron is visible but has no handler.
Result: `href="#"` parents scroll to top on tap; real-link parents navigate away
instead of expanding.

**Desktop nav (`#main-menu`, active >979px):** The theme uses `mouseenter`/`mouseleave`
only — no touch events. On a touch device above the 979px breakpoint, tapping a
`href="#"` parent item scrolled the page to the top instead of opening its submenu.

## Solution

**Mobile nav:** Wire `.sub-menu-btn-icon` chevron clicks to `slideToggle` the sibling
`.sub-menu` at all depths. Also toggle the submenu on a click of the parent anchor
itself. Real-link parents (About, Spoil Me!) are left navigable via their anchor.

**Desktop nav:** Several JS-based touch-interception approaches (open-on-first-tap,
`touchstart`/`touchend` handlers, etc.) were tried and abandoned as unreliable across
browsers. The final approach needs no JS at all: the `nav_menu_link_attributes` filter
strips the `href` attribute from any `menu-item-has-children` anchor whose href is
`#`, so the browser has no default action (no scroll-to-top) on tap. Desktop submenus
continue to open via the theme's existing `mouseenter`/`mouseleave` hover behavior.

The `.sub-menu-btn` div injected by the theme into `#mobile-menu` is left untouched.

## Deploy

```bash
make rincity-nav-tweaks
```

## Changelog

- **1.1.3** — Strip `href="#"` from `menu-item-has-children` anchors via the `nav_menu_link_attributes` filter, so the browser has no default action to prevent — eliminates desktop scroll-to-top on tap without any JS.
- **1.1.0–1.1.2** — Iterated on and ultimately dropped JS-based desktop touch interception (click/touchstart/touchend handling proved unreliable across browsers); desktop submenus rely on the theme's native hover behavior.
- **1.0.0–1.0.5** — Initial release and mobile touch-handling fixes: wired `.sub-menu-btn-icon` chevron and parent-anchor clicks to toggle submenus; iterated on desktop touch support later superseded by 1.1.x.
