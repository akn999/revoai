# Welcome to Salla Embedded SDK 💫

The **Salla Embedded SDK** is a powerful communication bridge that allows your third-party application (running in an iframe) to interact securely with the Salla Merchant Dashboard. It provides a set of helper methods to sync themes, handle navigation, trigger UI components, and manage authentication ensuring your app feels like a native part of the Salla platform.

<Frame caption="Embedded App Preview">
  ![](https://api.apidog.com/api/v1/projects/451700/resources/370553/image-preview)
</Frame>

This documentation is written for third-party developers to help build seamless, integrated applications that live directly within the Salla Merchant Dashboard.


## Documentation Structure


|  |  |
| --- | --- |
| **[Overview](doc-1929171)** | Introduction to the SDK, its purpose, and core architecture.  |
| **[Getting Started](doc-1950922)** | Follow quick start guide to set up your app, configure embedded pages, align UI with the dashboard. |
|**[Create an embedded app](doc-1929173)**| How to register your app and add embedded pages in Salla Partners.|
| **[Installation](doc-1929172)** | Get up and running using NPM or a CDN.|
|**[Authentication](doc-1919160)**| Detailed guide on token management and app security.|
| **[App Design Guidelines](doc-1929178)** | Design requirements and brand alignment for a native Salla experience.|
|**[Playground Testing](doc-1929235)**| Using the Playground and Test Kit to prototype SDK functions.|
|**SDK Modules Reference** <br> Detailed guides for every SDK method, organized by module.|<Icon icon="material-outline-lock"/> **[Auth Module](https://docs.salla.dev/embedded-sdk/modules/auth.md)** <br>Token management and app security. <br><Icon icon="material-outline-document_scanner"/> **[Page Module](https://docs.salla.dev/embedded-sdk/modules/page.md)** <br> Managing document titles, navigation, and iframe resizing.<br> <Icon icon="material-outline-navigation"/> **[Nav Module](https://docs.salla.dev/embedded-sdk/modules/nav.md)** <br> Customizing the dashboard navbar and action buttons. <br><Icon icon="material-outline-view_quilt"/> **[UI Module](https://docs.salla.dev/embedded-sdk/modules/ui.md)**  <br>Guides for Toasts, Modals, Confirm dialogs, and Loading states. <br><Icon icon="material-outline-view_quilt"/> **[Checkout Module](https://docs.salla.dev/embedded-sdk/modules/checkout.md)**  <br> Create Checkout, Get App Add-Ons and Subscribe for Payment.|
|[Endpoints](https://docs.salla.dev/6394918f0.md)| [Token Introspect](doc-27474794)|
|[Resources](doc-1929247)| <Icon icon="material-outline-contact_support"/> **[Support & Contribution](https://docs.salla.dev/embedded-sdk/support.md)** <br> Community links and open-source contribution guidelines.|
## Next Steps

<CardGroup cols={2}>
  <Card title="Install the SDK" href="./installation" icon="material-outline-file_download">
    Add the SDK to your project via NPM or CDN to initialize your app.
  </Card>
  <Card title="Create Your First App" href="./create-app" icon="material-outline-add_box">
    Configure your embedded pages in the Salla Partner Portal.
  </Card>
  <Card title="Setup Authentication" href="./authentication" icon="material-outline-vpn_key">
    Securely verify session tokens using the Salla Introspection API.
  </Card>
  <Card title="Prototype in Playground" href="./sandbox" icon="material-outline-code">
    Use the interactive playground to develop and test your integration logic in real-time.
  </Card>
</CardGroup>


###


# Getting Started


This article is s quick guide through the initial steps required to build and integrate your third-party application into the Salla Merchant Dashboard.

## Quick Start

Follow these onboarding path to get your embedded App ready:

<Steps>
  <Step title="Understand the Protocol"  >
Read the [Overview](doc-1929171) and [Authentication](doc-1919160) articles to understand the Salla-App handshake.
    </Step>
  <Step title="Setup">
    [Install](doc-1929172) the SDK in your embedded app and implement the bootstrap flow.
  </Step>
  <Step title="Connect">
    [Configure](doc-1929173) your app embedded page in the Partners Portal.
  </Step>
     <Step title="Style">
    Align your CSS and UI components with [Salla's brand standards](doc-1929178).
  </Step>
</Steps>

:::tip[]
Prototype SDK functions, experiment with UI components, and debug communication in real-time before writing a single line of code in your production application using [Embedded SDK Playground](https://github.com/SallaApp/embedded-sdk-playground)
:::

