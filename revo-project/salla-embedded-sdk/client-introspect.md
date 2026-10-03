# Client Introspect

The `introspect` method is a frontend helper that allows you to authorize the short-lived token from the client-side via the introspect API to inspect the details of an embedded page token. This is primarily intended for local development and debugging.


:::warning[Important Note]

This method is implemented as a reference for API calls and introspection flow. This should not be used as a primary authentication method.
:::

## Usage

```javascript
import { embedded } from "@salla.sa/embedded-sdk";

async function checkToken() {
  const result = await embedded.auth.introspect();
  
  if (result.isVerified) {
    console.log("Token is valid for user:", result.data.user_id);
  }
}
```

## API Reference

### `embedded.auth.introspect(options?)`

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **options** | `IntrospectOptions` | No | Configuration for the introspection request. |

### IntrospectOptions

| Property | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| **appId** | `string` | URL param | Your App ID. Auto-extracted if omitted. |
| **token** | `string` | URL param | The token to verify. Auto-extracted if omitted. |
| **refreshOnError** | `boolean` | `true` | Auto-refresh iframe if the token is invalid. |

### Return Value

Resolves to an `IntrospectResponse` object:

| Property | Type | Description |
| :--- | :--- | :--- |
| **isVerified** | `boolean` | `true` if Salla API confirms the token is valid. |
| **isError** | `boolean` | `true` if the API request failed. |
| **data** | `object` | Contains `merchant_id`, `user_id`, and `exp` (expiration). |


:::danger[Security Concern]
**Do NOT** use frontend introspection to make final authentication decisions. Always verify the token on your backend using the Salla Introspection API for production apps.
:::
