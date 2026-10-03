# Loading States

The Loading module allows you to control the native loading skeleton within the Salla Merchant Dashboard. This is useful for providing visual feedback during asynchronous operations like API calls or data processing.

## Usage

You can toggle the loading state using the `show` and `hide` methods.

```javascript
async function saveData() {
  // 1. Show loading indicator
  embedded.ui.loading.show();
  
  try {
    const response = await api.save(data);
    embedded.ui.toast.success("Data saved!");
  } catch (err) {
    embedded.ui.toast.error("Failed to save data.");
  } finally {
    // 2. Always hide loading in finally block
    embedded.ui.loading.hide();
  }
}
```

## Methods

### `embedded.ui.loading.show()`
Displays the dashboard's loading overlay or spinner. This signals to the merchant that an operation is in progress and may temporarily disable interactions.

### `embedded.ui.loading.hide()`
Removes the loading indicator. Ensure this is called even if an operation fails to prevent the UI from being stuck.



:::info [Best Practices]
- The `finally` Pattern

Always wrap your async logic in a `try...catch...finally` block. Calling `hide()` in the `finally` block ensures the loading state is cleared regardless of the outcome.

- Granular vs. Full Page

Remember that `embedded.ui.loading` affects the dashboard's UI context. If you only want to show a small loader inside your own app's buttons or sections, you should use your app's internal loading components instead.
:::

