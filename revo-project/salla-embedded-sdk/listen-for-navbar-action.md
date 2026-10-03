# Listen for Navbar Actions

When a merchant interacts with your custom navbar button or dropdown items, the SDK provides a way to listen and respond to those events.

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

// Subscribe to clicks
const unsubscribe = embedded.nav.onActionClick((value) => {
  switch (value) {
    case "create-product":
      openCreateForm();
      break;
    // ...
    case "help":

embedded.page.redirect("https://help.example.com");
      break;
  }
});

// Unsubscribe when cleaning up
// unsubscribe();
```

## API Reference

### `embedded.nav.onActionClick(callback)`

Registers a global listener for navigation action events.

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **callback** | `function` | Yes | Function called when an action is clicked. Receives `(value)`. |

### Callback Parameters

| Parameter | Type | Description |
| :--- | :--- | :--- |
| **value** | `string` | The value identifier of the clicked action (primary or extended). |

### Return Value

| Type | Description |
| :--- | :--- |
| `function` | An unsubscribe function to remove the listener. |

## Complete Example

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

// Set up the navbar action
embedded.nav.setAction({
  title: "Add Product",
  value: "add-product",
  icon: "sicon-plus",
  extendedActions: [
    { title: "Import Products", value: "import" },
    { title: "View Catalog", value: "view-catalog" },
    { title: "Help Center", value: "help" },
  ]
});

// Handle all clicks
const unsubscribe = embedded.nav.onActionClick((value) => {
  switch (value) {
    case "add-product":
      openProductForm();
      break;
    case "import":
      embedded.page.navigate("/import");
      break;
    case "view-catalog":
      embedded.page.navigate("/products");
      break;
    case "help":
      embedded.page.redirect("https://help.salla.com");
      break;
  }
});

// Clean up when component unmounts
// unsubscribe();
// embedded.nav.clearAction();
```
