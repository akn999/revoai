# Confirm Dialogs

Confirm dialogs are used to interrupt the user and require an explicit "Confirm" or "Cancel" action. These are essential for destructive actions like deletions or major configuration changes.

## Usage

Unlike toasts, `embedded.ui.confirm` returns a **Promise** that resolves with the user's choice.

```javascript
async function handleDelete() {
  // 1. Trigger the confirm dialog
  const result = await embedded.ui.confirm({
    title: "Delete Category?",
    message: "This will permanently remove the category and all its product assignments. This action cannot be undone.",
    confirmText: "Yes, Delete It",
    cancelText: "Keep Category",
    variant: "danger"
  });

  // 2. Act based on user's choice
  if (result.confirmed) {
    await api.deleteCategory(id);
    embedded.ui.toast.success("Category deleted.");
  }
}
```

## Options

The `confirm` method accepts a configuration object:

| Option | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| **title** | `string` | - | The title of the dialog. |
| **message** | `string` | - | The body text explaining the action. |
| **confirmText** | `string` | `"Confirm"` | Text for the confirmation button. |
| **cancelText** | `string` | `"Cancel"` | Text for the cancellation button. |
| **variant** | `string` | `"info"` | Visual style: `"info"`, `"warning"`, or `"danger"`. |

## Return Value

The method returns a `Promise<ConfirmResult>`:

```typescript
interface ConfirmResult {
  confirmed: boolean; // true if 'Confirm' was 
  clicked, false if 'Cancel' or closed.
}
```

:::info[Best Practices]
*   **Use Danger Variant for Deletions**: Always use `variant: "danger"` for destructive actions to visually warn the user.
*   **Clear Consequences**: The `message` should clearly state what will happen if the user confirms.
*   **Keep it Action-Oriented**: Use button text like "Save Changes" or "Delete" instead of just "Yes" or "No".
    :::
