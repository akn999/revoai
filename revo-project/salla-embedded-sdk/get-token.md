# Get Token

The `getToken` method is used to retrieve the short-lived session token that Salla passes to your app via the iframe URL. This token is required for backend verification.

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

const token = embedded.auth.getToken();

if (token) {
  // Send to your backend for verification
} else {
  console.error("No authentication token found in URL");
}
```

## API Reference

### `embedded.auth.getToken()`

| Return Value | Description |
| :--- | :--- |
| `string \| null` | The session token if present in the URL, otherwise `null`. |


:::info[Best Practices]
*   **Call early**: Retrieve the token immediately after `init()` to start your verification process.
*   **Handle Nulls**: Always check if the token exists before attempting to use it.

:::
