# rincity-nav-tweaks

Fixes submenu expand/collapse on the Ashe Pro theme for touch devices.

## Problems

**Mobile nav (`#mobile-menu`, active ≤979px):** The theme injects a `.sub-menu-btn`
overlay as the tap target but `#mobile-menu li a` has `position: relative; z-index: 5`,
rendering it dead. The `.sub-menu-btn-icon` chevron is visible but has no handler.
Result: `href="#"` parents scroll to top on tap; real-link parents navigate away
instead of expanding.

**Desktop nav (`#main-menu`, active >979px):** The theme uses `mouseenter`/`mouseleave`
only — no touch events. Submenus never open on touch devices.

## Solution

**Mobile nav:** Wire `.sub-menu-btn-icon` chevron clicks to `slideToggle` the sibling
`.sub-menu` at all depths. Intercept `href="#"` anchor taps to expand instead of
scroll. Real-link parents (About, Spoil Me!) are left navigable via their anchor.

**Desktop nav:** On first tap of a `menu-item-has-children` anchor, open the submenu
(`fadeIn`) and add `.submenu-open` class instead of following the link. On second tap:
if the link is real, navigate; if `href="#"`, close the submenu. Tapping outside the
menu closes all open submenus. Works at all nesting depths (e.g. Wallpaper inside
Members).

The `.sub-menu-btn` div injected by the theme into `#mobile-menu` is left untouched.
