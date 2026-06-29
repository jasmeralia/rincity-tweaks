# rincity-nav-tweaks

Fixes mobile submenu expand/collapse on the Ashe Pro theme.

## Problem

The Ashe Pro theme injects a `.sub-menu-btn` overlay as a tap target for parent menu items, but `#mobile-menu li a` has `position: relative; z-index: 5`, which renders the overlay dead. The `.sub-menu-btn-icon` chevron is visible but has no click handler. Result:

- `href="#"` parents (Members, Wallpaper) scroll the page to top on tap instead of expanding
- Real-link parents (About, Spoil Me!) navigate away instead of expanding their submenus

## Solution

Two JS handlers wired after theme JS runs:

1. **Chevron click** (`.sub-menu-btn-icon`) — `slideToggle`s the sibling `.sub-menu` and rotates the chevron SVG. Works at all nesting depths.
2. **`href="#"` anchor click** — `preventDefault` to stop scroll-to-top, then toggles the submenu and chevron the same way.

Real-link parents (About, Spoil Me!) are left untouched on anchor click; only their chevron triggers expand/collapse.

The `.sub-menu-btn` div injected by the theme is left in the DOM unchanged.
