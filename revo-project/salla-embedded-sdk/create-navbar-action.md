# Create Navbar Action


The `setAction` method allows you to inject a primary action button into the dashboard's top navigation bar. This is the best place for "Create", "Save", or "Export" buttons.

## Usage

You can configure a simple button or a complex button with a dropdown menu.

<Tabs>
  <Tab title="Simple Button">
    A single primary button.

    ```javascript
    import { embedded } from "@salla.sa/embedded-sdk";

    embedded.nav.setAction({
      title: "Create Product",
      value: "create-product",
      icon: "salla-icon-plus",
    });

    // Handle clicks via onActionClick
    embedded.nav.onActionClick((value) => {
      if (value === "create-product") {
        openCreateForm();
      }
    });
    ```
  </Tab>
  <Tab title="With Dropdown">
    A button with a list of "Extended Actions."

    ```javascript
    embedded.nav.setAction({
      title: "Actions",
      value: "main-action",
      extendedActions: [
        { title: "Export to CSV", value: "export_csv" },
        { title: "Print Report", value: "print" },
        { title: "Settings", value: "settings" },
      ]
    });

    // Handle all clicks in one place
    embedded.nav.onActionClick((value) => {
      switch (value) {
        case "export_csv":
          handleExport();
          break;
        case "print":
          handlePrint();
          break;
        case "settings":
          embedded.page.navigate("/my-app/settings");
          break;
      }
    });
    ```
  </Tab>
</Tabs>

## API Reference


| Option | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **title** | `string` | Yes | The text displayed on the button. |
| **value** | `string` | Yes | Identifier passed to the `onActionClick` listener. |
| **icon** | `string` | No | Class name for a Salla icon (e.g., `salla-icon-save`). |
| **disabled** | `boolean` | No | Whether the button is grayed out and unclickable. |
| **extendedActions** | `array` | No | List of objects for the dropdown menu. |

### Extended Action Object

Each item in the `extendedActions` array can have:
| Option | Type | Required | Description |
| :--- | :--- | :--- | :---- |
| **title**    | `string`  | Yes | The text displayed for the dropdown action item. |
| **value**    | `string`  | Yes | Identifier passed to the `onActionClick` listener. |
| **subTitle** | `string`  | No | Smaller text displayed below the title. |
| **icon**     | `string`  | No | Class name for an associated icon. |
| **disabled** | `boolean` | No | Whether the action is grayed out and unclickable. |

:::info[Best Practices]
*   **Action-Oriented Titles**: Use clear verbs like "Save Changes", "Add Item", or "Export".
*   **Standard Icons**: Use familiar icons from the Salla design system to maintain a native feel.
*   **State Sync**: If your app is loading or a form is invalid, set `disabled: true` on your navbar action.
*   **Single Handler**: Use `onActionClick` to handle all action clicks in one place for cleaner code.
