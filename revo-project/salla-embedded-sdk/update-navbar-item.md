# Update Navbar Item

The `updateNavItem` method updates a previously injected sub-nav **parent or child** using its immutable `value`.

Use it to rename rows, toggle disabled state, switch active state, patch URLs, or (on a **parent** only) replace the full `children` list. The `value` field itself cannot be changed; remove and re-add to use a new key.

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

function activateSettingsTab(tabValue) {
  embedded.nav.updateNavItem({
    value: tabValue,
    title: "Settings",
    active: true,
  });
}
```

### Replace dropdown children (parent only)

```javascript
embedded.nav.updateNavItem({
  value: "reports",
  children: [
    { title: "Summary", value: "reports-summary", url: "/apps/my-app/reports/summary" },
  ],
});
```

## API Reference

### `embedded.nav.updateNavItem(item)`

| Property | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **value** | `string` | Yes | Immutable key of the parent or child row (`addNavItem` root `value` or a child’s `value`). |
| **title** | `string` | No | New item label. |
| **url** | `string` | No | New URL for the item. |
| **disabled** | `boolean` | No | Disable or re-enable the item. |
| **active** | `boolean` | No | Toggle active visual state. |
| **children** | `array` | No | **Parent only:** replaces the entire submenu list. Ignored when patching a child row. |

## Notes

- At least one field besides `value` should be provided (including `children` when you only want to replace the list).
- Unknown values are ignored safely by the host.
- Setting one injected item to `active: true` clears `active` on other injected **parent** tabs; for a child, other children under the same parent are cleared when that child becomes active.
- `value` is not patchable; remove + `addNavItem` to change it.

:::highlight blue 💡
**Best Practices**
*   **Patch Minimal Fields**: Send only fields you want to change to keep intent clear.
*   **Keep App State Source of Truth**: Derive `active` from your internal route/view state.
*   **Graceful Unknown Values**: If your app can reload, guard update calls when stored values are missing.
    :::
