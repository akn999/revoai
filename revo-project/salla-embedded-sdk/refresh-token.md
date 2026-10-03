# Refresh Token

The `refresh` method triggers a reload of the iframe with a fresh session token from the Salla Host. Use this when your backend indicates that the current token has expired.

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

// Trigger a token refresh
embedded.auth.refresh();
```

## API Reference

### `embedded.auth.refresh()`

| Parameters | Return Value | Description |
| :--- | :--- | :--- |
| - | `void` | Requests the host to reload the iframe with a new token. |


:::info[Best Practices]

*   **Avoid Loops**: Ensure you don't call `refresh()` repeatedly if the token remains invalid for other reasons.
*   **User Feedback**: Consider showing a loading indicator or a brief message before refreshing to explain the slight delay to the merchant.

:::
