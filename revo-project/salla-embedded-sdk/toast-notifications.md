# Toast Notifications

Toasts are non-blocking notifications used to provide quick feedback to the user about an operation's status. They appear at the top of the Salla Merchant Dashboard and automatically disappear after a few seconds.

## Usage

The SDK provides both a generic `show` method and several convenience methods for common notification types.

<Tabs>
  <Tab title="Convenience Methods">
    Most common usage for standard feedback.

    ```javascript
    // Success notification
    embedded.ui.toast.success("Product saved successfully!");

    // Error notification
    embedded.ui.toast.error("Failed to update inventory.");
    ```
  </Tab>
  <Tab title="Generic show()">
    Use this for more control over duration and configuration.

    ```javascript
    embedded.ui.toast.show({
      type: "success",
      message: "Custom duration toast",
      duration: 5000 // 5 seconds
    });
    ```
  </Tab>
</Tabs>

## API Reference

### Methods

| Method | Parameters | Description |
| :--- | :--- | :--- |
| `error(message, duration?)` | `message: string`, `duration?: number` | Displays a red error toast. |
| `warning(message, duration?)` | `message: string`, `duration?: number` | Displays an orange warning toast. |
| `info(message, duration?)` | `message: string`, `duration?: number` | Displays a blue info toast. |
| `show(options)` | `options: ToastOptions` | Generic method for triggered a toast with full configuration. |

### ToastOptions

| Property | Type | Required | Default | Description |
| :--- | :--- | :--- | :--- | :--- |
| **type** | `string` | Yes | - | Notification type: `"success"`, `"error"`, `"warning"`, or `"info"`. |
| **message** | `string` | Yes | - | The message text to display. |
| **duration** | `number` | No | `3000` | Visibility duration in milliseconds. |



:::info[Best Practices]
*   **Keep it brief**: Toasts should be readable at a glance, avoid long sentences.
*   **Avoid Success toast**: "Success" is the expected result. No need to notify the user about a successful action; the UI update is enough.
*   **One at a time**: The SDK handles queuing, but you should avoid triggering multiple toasts simultaneously for the same action.
    :::
