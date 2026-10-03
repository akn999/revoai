# Breadcrumbs

The `breadcrumbs` sub-module lets your embedded app control visibility of the host breadcrumbs container.

Use this when your embedded experience has its own internal navigation and host breadcrumbs create visual noise.

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

// Hide host breadcrumbs for a focused full-screen flow
embedded.ui.breadcrumbs.hide();

// Show them again when returning to standard views
embedded.ui.breadcrumbs.show();
```

## API Reference

### `embedded.ui.breadcrumbs.hide()`

Hides the host breadcrumbs container.

### `embedded.ui.breadcrumbs.show()`

Shows the host breadcrumbs container.

## Notes

- Breadcrumb visibility is controlled by the host shell.
- This feature affects dashboard chrome, not content inside your iframe.

:::highlight blue 💡
**Best Practices**
*   **Scope by View**: Hide breadcrumbs only in views where they conflict with your app's navigation model.
*   **Restore Visibility**: Ensure `show()` is called when leaving focused flows or during route cleanup.
*   **Avoid Flicker**: Trigger visibility changes on stable route/state transitions, not on every render.
    :::
