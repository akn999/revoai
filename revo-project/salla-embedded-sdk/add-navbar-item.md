# Add Navbar Item

The `addNavItem` method injects a custom sub-navigation item into the Merchant Dashboard sub-nav area and resolves with your **immutable** `value` for that parent row.

Use this when your embedded app needs host-level tabs (for example, `Overview`, `Settings`, `Logs`) that stay visually integrated with dashboard navigation. You can inject a **flat** tab or a tab with a **one-level** dropdown by passing optional `children` (same shape as the root item, without nested `children`).

## Usage

`addNavItem` is asynchronous and resolves once the host acknowledges item creation.

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

async function setupSubNav() {
  const navItem = {
    title: "Overview",
    value: "overview-tab",
    url: "/apps/my-app/overview",
    active: true,
  }
  const result = await embedded.nav.addNavItem(navItem);

  // Use `value` for update/remove and for correlating clicks
  myStore.syncedTabs[navItem.value] = result.value;
}
```

### Tab with dropdown (one level of `children`)

```javascript
await embedded.nav.addNavItem({
  title: "Reports",
  value: "reports",
  url: "/apps/my-app/reports",
  children: [
    { title: "Summary", value: "reports-summary", url: "/apps/my-app/reports/summary" },
    { title: "Export", value: "reports-export", url: "/apps/my-app/reports/export" },
  ],
});
```

Every `value` must be **globally unique** among all injected parents and children. Parent and child rows can be updated or removed by that same `value` via `updateNavItem` / `removeNavItem`. To replace the dropdown list on a parent, call `updateNavItem` on the parent with a new `children` array.

## API Reference

### `embedded.nav.addNavItem(item)`

| Property | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **title** | `string` | Yes | Label shown in the sub-nav. |
| **value** | `string` | Yes | Stable, unique key for this row (and for `updateNavItem` / `removeNavItem` / click payloads). |
| **url** | `string` | Yes | URL associated with the tab. |
| **disabled** | `boolean` | No | Whether the item is disabled initially. |
| **active** | `boolean` | No | Whether the item should be active initially. |
| **children** | `array` | No | Optional one-level submenu. Each entry has `title`, `value`, `url`, and optional `disabled` / `active`. Nested `children` are not allowed. |

### Return Value

`Promise<{ value: string }>`

- **value**: Immutable identifier for the **parent** row you added (`updateNavItem`, `removeNavItem`, and `onNavItemClick` all use this key).

:::highlight blue 💡
**Best Practices**
*   **Persist `value`**: Store `result.value` for `updateNavItem()`, `removeNavItem()`, and matching `onNavItemClick` payloads.
*   **Use Stable Values**: Keep `value` semantic and stable (e.g., `"logs-tab"`), since it is the primary key for parent and child rows.
*   **One Active Item**: If setting `active: true` on the root item, ensure this reflects your real app state for clear UX.
    :::
