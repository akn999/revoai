# Listen for Navbar Item Click

Use `onNavItemClick` to listen when a merchant clicks an injected sub-nav item in the dashboard chrome.

This listener applies to items added by your app through `addNavItem()`, including clicks on the **parent tab** and on **dropdown rows** when you passed `children`. The callback receives **`value`** and **`url`** for the clicked row.

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

const unsubscribe = embedded.nav.onNavItemClick(({ value, url }) => {
  if (value === "overview-tab") {
    console.log("host sub-nav url:", url);
    openOverviewPanel();
  }
});

// Later, when cleaning up:
// unsubscribe();
```

## API Reference

### `embedded.nav.onNavItemClick(callback)`

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **callback** | `function` | Yes | Called when an injected sub-nav item is clicked. |

### Callback Payload

| Property | Type | Description |
| :--- | :--- | :--- |
| **value** | `string` | Immutable key for the clicked parent or child row. |
| **url** | `string` | URL attached to the injected item at click time. |

### Return Value

| Type | Description |
| :--- | :--- |
| `function` | Unsubscribe function for removing the listener. |

:::highlight blue 💡
**Best Practices**
*   **Filter by `value`**: Route behavior by `value` for readability; it is the stable public identifier.
*   **Unsubscribe on Cleanup**: Remove listeners when your component/page unmounts.
*   **Pair with Active State**: After handling a click, call `updateNavItem({ value, active: true })` when you want the host chrome to reflect selection (works for parent or child).
    :::
