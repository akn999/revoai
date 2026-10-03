# Create an Embedded App

To integrate your application into the Salla Merchant Dashboard, you need to register it in the Salla Partner Portal and configure your embedded pages.

### What you'll learn:
- [Configure Your App](#configure-your-app)
- [How Salla Loads Your App](#how-salla-loads-your-app)
- [URL Parameters](#url-parameters)



## Configure Your App

### Step 1: Access the Partners Portal

1. Log in to your [Salla Partners Portal](https://portal.salla.partners) account.
2. Select your app from the **My Apps** section.
3. Navigate to the **Embedded Pages** section.


![Embedded Pages section](https://api.apidog.com/api/v1/projects/451700/resources/370529/image-preview)

### Step 2: Configure an Embedded Page

When adding a new embedded page, you will need to provide the following details:

![Adding a new embedded page](https://api.apidog.com/api/v1/projects/451700/resources/370530/image-preview)

<Steps>
  <Step title="Route Slug">
    Define a unique path for your page within the dashboard (e.g., `my-app`). This will appear in the URL as `https://s.salla.sa/embedded/app/{appId}/my-app`.
  </Step>
  <Step title="Iframe URL">
    The full URL where your application is hosted (e.g., `https://dashboard.myapp.net/salla/embedded`). 
    **Note:** Salla will append authentication and layout parameters to this URL (e.g., `?token=XXX&theme=dark`), as explained in [Authentication](https://docs.salla.dev/embedded-sdk/authentication.md).
  </Step>
  <Step title="Default Page">
    Toggle this option if you want this page to be the primary landing view when a merchant opens your app.
  </Step>
</Steps>

![Embedded page added](https://api.apidog.com/api/v1/projects/451700/resources/370531/image-preview)


:::check[Embedded App Banner]
It is imperative to add Embedded App Banner image with `1420×520 px` in your App Card for a smooth App publishing journey.
On the Partners Portal, go to My Apps -> Select the App-> App Details -> Start publishing your App-> App Features -> Embedded App Banner.

![image.png](https://api.apidog.com/api/v1/projects/451700/resources/376488/image-preview)

:::

## How Salla Loads Your App

1. The merchant opens the installed app from the Salla dashboard.
2. The app view renders and displays the "Use App" button.
4. When the merchant clicks "Use App", Salla loads your app’s configured Embedded Page inside an iframe.
5. The embedded page establishes a communication bridge using the Embedded SDK.
6. The embedded app initializes and renders its user interface.


![Installed app view](https://api.apidog.com/api/v1/projects/451700/resources/370532/image-preview)

### URL Parameters
Salla automatically appends several query parameters to your URL to help with the initial render:
* `token`: A short-lived authentication token.
* `theme`: The merchant's current theme (`light` or `dark`).
* `lang`: The merchant's preferred language (`ar` or `en`).

![Embedded App Preview](https://api.apidog.com/api/v1/projects/451700/resources/370563/image-preview)

## Next Steps

<CardGroup cols={2}>
  <Card title="Install the SDK" href="./installation" icon="material-outline-file_download">
    Add the SDK to your project via NPM or CDN to initialize your app.
  </Card>
  <Card title="Setup Authentication" href="./authentication" icon="material-outline-vpn_key">
    Securely verify session tokens using the Salla Introspection API.
  </Card>
</CardGroup>
