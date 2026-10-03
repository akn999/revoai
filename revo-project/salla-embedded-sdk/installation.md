# Installation

You can integrate the Salla Embedded SDK into your project using modern package managers or by including a simple script tag.

### What you'll learn:
- [Intstalling using NPM](#using-npm-recommended)
- [Installing using CDN/Script Tag](#using-cdn)
- [Examples](#initial-setup-example).


## Using NPM *Recommended*

This is the preferred method for projects using Vite, Webpack, or modern JavaScript frameworks.

<Tabs>
  <Tab title="ES Modules">
Best for modern build tools.

```bash
# Install via npm
npm install @salla.sa/embedded-sdk
```

```javascript
// Import in your application
import { embedded } from "@salla.sa/embedded-sdk";
```
  </Tab>


    <Tab title="CDN">
        Using the CDN/ Script tag is ideal for vanilla JavaScript projects or quick prototypes.

```html
<!-- Load from UNPKG -->
<script src="https://unpkg.com/@salla.sa/embedded-sdk/dist/umd/index.js"></script>

<script>
  // Access via the global Salla object
  const embedded = Salla.embedded;
  
  // Alternatively, via the package namespace
  // const embedded = SallaEmbeddedSDK.embedded;
</script>
```
         </Tab>

</Tabs>


### Initial Setup Example

Here is a complete example of how to properly initialize your app and signal readiness once installed:

```typescript
import { embedded } from "@salla.sa/embedded-sdk";

async function bootstrapApp() {
  try {
    // 1. Initialize SDK and get dashboard context
    const { layout } = await embedded.init({ debug: true });
    
    // 2. Handle token verification
    const token = embedded.auth.getToken();
    if (!token) throw new Error("Unauthorized");
    
    // ... Verify token with your backend ...

    // 3. Signal that the app is ready for the user
    embedded.ready();
    
    // Set a native page title
    embedded.page.setTitle("My App Dashboard");
    
  } catch (err) {
    console.error("SDK initialization failed", err);
    embedded.destroy(); // Exit the embedded view on error
  }
}

bootstrapApp();
```

## Next Steps

<CardGroup cols={2}>
  <Card title="Setup Authentication" href="./authentication" icon="material-outline-vpn_key">
    Securely verify session tokens using the Salla Introspection API.
  </Card>
  <Card title="Review Design Guidelines" href="./design-guidelines" icon="material-outline-palette">
    Ensure your app design aligns with Salla's visual identity and standards.
  </Card>
</CardGroup>
